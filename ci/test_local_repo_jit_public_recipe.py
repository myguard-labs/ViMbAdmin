"""Synthetic descriptor fixtures; none of these bytes are public recipe locks."""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

SOURCE = (
    Path(__file__).resolve().parents[1]
    / ".github/scripts/local-repo-jit/public_recipe.py"
)
SPEC = importlib.util.spec_from_file_location("public_recipe", SOURCE)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("public recipe module unavailable")
recipe = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = recipe
SPEC.loader.exec_module(recipe)


def fixture():
    rows = []

    def add(name, kind, publisher, url, dependencies=()):
        content = ("synthetic fixture: " + name).encode()
        sha = hashlib.sha256(content).hexdigest()
        rows.append(
            {
                "id": name,
                "kind": kind,
                "publisher": publisher,
                "url": url.replace("DIGEST", sha),
                "sha256": sha,
                "bytes": len(content),
                "version": "1.0",
                "architecture": "amd64",
                "dependencies": list(dependencies),
            }
        )

    add(
        "base",
        "base",
        "debian-cloud",
        "https://cloud.debian.org/images/cloud/bookworm/20260101-1/"
        "debian-12-genericcloud-amd64-20260101-1.qcow2",
    )
    add(
        "runner",
        "runner-archive",
        "github-actions-runner",
        "https://github.com/actions/runner/releases/download/v1.0/"
        "actions-runner-linux-x64-1.0.tar.gz",
    )
    add(
        "image",
        "runtime-image",
        "docker-official-debian",
        "https://registry-1.docker.io/v2/library/debian/manifests/sha256:DIGEST",
        ["blob"],
    )
    add(
        "blob",
        "runtime-blob",
        "docker-official-debian",
        "https://registry-1.docker.io/v2/library/debian/blobs/sha256:DIGEST",
    )
    for name in sorted(recipe.TOOLS):
        add(
            "tool-" + name,
            "toolchain",
            "debian-snapshot",
            "https://snapshot.debian.org/archive/debian/20260101T000000Z/pool/"
            "main/p/pkg/" + name + "_1.0_amd64.deb",
        )
    for name in sorted(recipe.GUEST_ROOTS):
        if name in {"python3", "qemu-guest-agent"}:
            publisher = "debian-snapshot"
            prefix = "https://snapshot.debian.org/archive/debian-security/20260101T000000Z/pool/main/p/pkg/"
        else:
            publisher = "docker-debian"
            prefix = "https://download.docker.com/linux/debian/dists/bookworm/pool/stable/amd64/"
        add(
            "guest-" + name,
            "guest-package",
            publisher,
            prefix + name + "_1.0_amd64.deb",
        )
    return {
        "schema": 1,
        "recipe": "test-only-new-identity",
        "artifacts": rows,
        "tools": {name: "tool-" + name for name in sorted(recipe.TOOLS)},
        "guest_roots": {name: "guest-" + name for name in sorted(recipe.GUEST_ROOTS)},
    }


def encoded(data):
    return json.dumps(data).encode()


class PublicRecipeTests(unittest.TestCase):
    def setUp(self):
        self.data = fixture()
        self.raw = encoded(self.data)
        self.descriptor = recipe.parse_descriptor(self.raw)
        self.temp = self.enterContext(
            tempfile.TemporaryDirectory(prefix="public-recipe-test-")
        )
        self.root = Path(self.temp)
        self.choices = {}
        for item in self.descriptor.artifacts:
            path = self.root / item.id
            path.write_bytes(("synthetic fixture: " + item.id).encode())
            self.choices[item.id] = recipe.RetainedFile(str(path), item.url)

    def verify(self, raw=None, choices=None, trusted=None):
        return recipe.verify_retained(
            self.raw if raw is None else raw,
            self.choices if choices is None else choices,
            expected_sha256=self.descriptor.sha256 if trusted is None else trusted,
        )

    def refused(self, data, message=None):
        with self.assertRaisesRegex(recipe.RecipeError, message or "."):
            recipe.parse_descriptor(encoded(data))

    def test_canonical_repeat_shuffle_and_independent_directory(self):
        reference = self.descriptor.canonical
        self.assertEqual(hashlib.sha256(reference).hexdigest(), self.descriptor.sha256)
        self.assertTrue(reference.endswith(b"\n"))
        self.assertEqual(recipe.parse_descriptor(reference), self.descriptor)
        for offset in range(12):
            data = copy.deepcopy(self.data)
            rows = data["artifacts"]
            data["artifacts"] = rows[offset:] + rows[:offset]
            if offset % 2:
                data["artifacts"].reverse()
            data = dict(reversed(list(data.items())))
            for row in data["artifacts"]:
                row["dependencies"].reverse()
            data["tools"] = dict(reversed(list(data["tools"].items())))
            self.assertEqual(
                recipe.parse_descriptor(encoded(data)).canonical, reference
            )
        receipt = self.verify()
        self.assertEqual(receipt["recipe_sha256"], self.descriptor.sha256)
        self.assertEqual([x["id"] for x in receipt["artifacts"]], sorted(self.choices))
        other = self.root / "other"
        other.mkdir()
        choices = {}
        for key, choice in self.choices.items():
            path = other / key
            path.write_bytes(Path(choice.path).read_bytes())
            choices[key] = recipe.RetainedFile(str(path), choice.origin)
        self.assertEqual(self.verify(choices=choices), receipt)

    def test_json_errors_and_boundaries(self):
        for raw in [
            None,
            "{}",
            bytearray(b"{}"),
            b"",
            b"x",
            b"\xff",
            b"[",
            b"[]",
            b"null",
            b'{"schema":1,"schema":1}',
            b"[" * 1100 + b"]" * 1100,
            b" " * (recipe.MAX_DESCRIPTOR_BYTES + 1),
        ]:
            with (
                self.subTest(raw_type=type(raw).__name__),
                self.assertRaises(recipe.RecipeError),
            ):
                recipe.parse_descriptor(raw)
        padding = b" " * (recipe.MAX_DESCRIPTOR_BYTES - len(self.raw))
        self.assertEqual(recipe.parse_descriptor(self.raw + padding), self.descriptor)
        nested = self.raw.replace(b'"id": "base"', b'"id": "base", "id": "base"')
        with self.assertRaisesRegex(recipe.RecipeError, "duplicate"):
            recipe.parse_descriptor(nested)

    def test_exact_descriptor_fields_schema_and_tokens(self):
        for key in self.data:
            data = copy.deepcopy(self.data)
            del data[key]
            self.refused(data)
        for key, value in [
            ("extra", 1),
            ("schema", True),
            ("schema", 2),
            ("recipe", "a" * 129),
            ("recipe", "Bad"),
            ("recipe", None),
            ("recipe", "x\u200b"),
        ]:
            data = copy.deepcopy(self.data)
            data[key] = value
            self.refused(data)
        data = copy.deepcopy(self.data)
        data["recipe"] = "a" * 128
        recipe.parse_descriptor(encoded(data))

    def test_artifact_fields_types_and_limits(self):
        for value in [
            None,
            {},
            [],
            [None],
            [self.data["artifacts"][0]] * (recipe.MAX_ARTIFACTS + 1),
        ]:
            data = copy.deepcopy(self.data)
            data["artifacts"] = value
            self.refused(data)
        for key in self.data["artifacts"][0]:
            data = copy.deepcopy(self.data)
            del data["artifacts"][0][key]
            self.refused(data)
        for key, values in {
            "id": [None, "../base", "", "A"],
            "kind": ["unknown"],
            "publisher": ["unknown"],
            "sha256": ["F" * 64, "0" * 63, None],
            "version": ["", "1\n", None],
            "architecture": ["arm64", None],
            "bytes": [True, 0, -1, 1.5, recipe.MAX_INPUT_BYTES + 1],
            "dependencies": [
                None,
                {},
                [1],
                ["base", "base"],
                ["x"] * (recipe.MAX_ARTIFACTS + 1),
            ],
        }.items():
            for value in values:
                with self.subTest(field=key, value=repr(value)[:40]):
                    data = copy.deepcopy(self.data)
                    data["artifacts"][0][key] = value
                    self.refused(data)
        for size in [1, recipe.MAX_INPUT_BYTES]:
            data = copy.deepcopy(self.data)
            data["artifacts"][0]["bytes"] = size
            recipe.parse_descriptor(encoded(data))
        data = copy.deepcopy(self.data)
        data["artifacts"].append(data["artifacts"][0])
        self.refused(data, "duplicate id")

    def test_public_origin_url_refusal(self):
        for url in [
            None,
            "",
            "http://cloud.debian.org/x",
            "file:///etc/passwd",
            "https://user@cloud.debian.org/x",
            "https://user:pass@cloud.debian.org/x",
            "https://cloud.debian.org:443/x",
            "https://cloud.debian.org//x",
            "https://cloud.debian.org/./x",
            "https://cloud.debian.org/../x",
            "https://cloud.debian.org/%2e/x",
            "https://cloud.debian.org/x?q=1",
            "https://cloud.debian.org/x#part",
            "https://cloud.debian.org/x\\y",
            "https://cloud.debian.org/x\n",
            "https://clo\u200bud.debian.org/x",
            "https://127.0.0.1/x",
            "https://cloud.debian.org.evil/x",
        ]:
            with self.subTest(url=url):
                data = copy.deepcopy(self.data)
                data["artifacts"][0]["url"] = url
                self.refused(data)
        for index, row in enumerate(self.data["artifacts"]):
            for field, value in [
                ("url", row["url"] + "-wrong"),
                ("url", row["url"].replace("https://", "https://evil.", 1)),
                ("publisher", "debian-cloud"),
            ]:
                if field == "publisher" and row[field] == value:
                    continue
                data = copy.deepcopy(self.data)
                data["artifacts"][index][field] = value
                self.refused(data, "origin")
        data = copy.deepcopy(self.data)
        data["artifacts"][0]["url"] = data["artifacts"][0]["url"].replace(
            "20260101-1", "latest"
        )
        self.refused(data, "origin")

    def test_missing_singletons_and_bindings(self):
        for kind in recipe.SINGLETONS:
            data = copy.deepcopy(self.data)
            data["artifacts"] = [
                row for row in data["artifacts"] if row["kind"] != kind
            ]
            self.refused(data, "exactly one")
            data = copy.deepcopy(self.data)
            duplicate = copy.deepcopy(
                next(row for row in data["artifacts"] if row["kind"] == kind)
            )
            duplicate["id"] += "-second"
            data["artifacts"].append(duplicate)
            self.refused(data, "exactly one")
        for key in ["tools", "guest_roots"]:
            for value in [None, {}, {"extra": "base"}]:
                data = copy.deepcopy(self.data)
                data[key] = value
                self.refused(data)
            first, second = list(self.data[key])[:2]
            for value in ["missing", "base", None, self.data[key][second]]:
                data = copy.deepcopy(self.data)
                data[key][first] = value
                self.refused(data)

    def test_dependency_closure_kinds_and_unused(self):
        for dep in ["missing", "base"]:
            data = copy.deepcopy(self.data)
            data["artifacts"][0]["dependencies"] = [dep]
            self.refused(data, "dependencies")
        data = copy.deepcopy(self.data)
        data["artifacts"][2]["dependencies"] = []
        self.refused(data, "config/layer")
        data = copy.deepcopy(self.data)
        extra = copy.deepcopy(data["artifacts"][3])
        extra["id"] = "orphan"
        data["artifacts"].append(extra)
        self.refused(data, "unreferenced")

    def test_package_cycles_preserve_edges_and_verify_retained(self):
        for kind in ["toolchain", "guest-package"]:
            for count in [1, 2, 3]:
                with self.subTest(kind=kind, count=count):
                    data = copy.deepcopy(self.data)
                    rows = [row for row in data["artifacts"] if row["kind"] == kind]
                    cycle = rows[:count]
                    for index, row in enumerate(cycle):
                        row["dependencies"] = [cycle[(index + 1) % count]["id"]]
                    # Include an outgoing edge to exercise both new and seen nodes.
                    if count < len(rows):
                        cycle[0]["dependencies"].append(rows[count]["id"])
                    expected = {
                        row["id"]: tuple(sorted(row["dependencies"]))
                        for row in data["artifacts"]
                    }
                    descriptor = recipe.parse_descriptor(encoded(data))
                    self.assertEqual(
                        {row.id: row.dependencies for row in descriptor.artifacts},
                        expected,
                    )
                    receipt = self.verify(raw=encoded(data), trusted=descriptor.sha256)
                    self.assertEqual(
                        [row["id"] for row in receipt["artifacts"]], sorted(expected)
                    )
                    for row in data["artifacts"]:
                        row["dependencies"].reverse()
                    data["artifacts"].reverse()
                    self.assertEqual(recipe.parse_descriptor(encoded(data)), descriptor)
                    self.assertEqual(
                        recipe.parse_descriptor(descriptor.canonical), descriptor
                    )

    def test_package_cycles_require_complete_reachable_same_role_inputs(self):
        for kind in ["toolchain", "guest-package"]:
            with self.subTest(kind=kind):
                data = copy.deepcopy(self.data)
                parent = next(row for row in data["artifacts"] if row["kind"] == kind)
                first = copy.deepcopy(parent)
                second = copy.deepcopy(parent)
                first["id"], second["id"] = "cycle-first", "cycle-second"
                first["dependencies"], second["dependencies"] = [second["id"]], [first["id"]]
                data["artifacts"].extend([first, second])
                self.refused(data, "unreferenced")
                parent["dependencies"] = [first["id"]]
                descriptor = recipe.parse_descriptor(encoded(data))
                self.assertEqual(len(descriptor.artifacts), len(self.data["artifacts"]) + 2)
                second["dependencies"].append("missing")
                self.refused(data, "missing artifact")
                second["dependencies"] = [first["id"], "base"]
                self.refused(data, "incompatible kind")
                second["dependencies"] = [first["id"]]
                data["artifacts"].remove(second)
                self.refused(data, "missing artifact")

    def test_all_architecture_package_dependencies(self):
        for kind in ["toolchain", "guest-package"]:
            with self.subTest(kind=kind):
                data = copy.deepcopy(self.data)
                parent = next(row for row in data["artifacts"] if row["kind"] == kind)
                dependency = copy.deepcopy(parent)
                dependency["id"] += "-data"
                dependency["architecture"] = "all"
                dependency["url"] = (
                    parent["url"].rsplit("/", 1)[0] + "/data-package_1.0_all.deb"
                )
                content = ("synthetic fixture: " + dependency["id"]).encode()
                dependency["sha256"] = hashlib.sha256(content).hexdigest()
                dependency["bytes"] = len(content)
                parent["dependencies"] = [dependency["id"]]
                data["artifacts"].append(dependency)
                descriptor = recipe.parse_descriptor(encoded(data))
                selected = next(
                    row for row in descriptor.artifacts if row.id == dependency["id"]
                )
                self.assertEqual(selected.architecture, "all")
                self.assertEqual(selected.kind, kind)
                path = self.root / dependency["id"]
                path.write_bytes(content)
                choices = {
                    **self.choices,
                    dependency["id"]: recipe.RetainedFile(str(path), dependency["url"]),
                }
                receipt = self.verify(
                    raw=encoded(data), choices=choices, trusted=descriptor.sha256
                )
                self.assertEqual(len(receipt["artifacts"]), len(data["artifacts"]))
                data["artifacts"].reverse()
                self.assertEqual(recipe.parse_descriptor(encoded(data)), descriptor)

    def test_package_architecture_scope_rejection(self):
        for index, row in enumerate(self.data["artifacts"]):
            invalid = ["arm64", "i386", "any", None, True, [], {}]
            if row["kind"] not in {"toolchain", "guest-package"}:
                invalid.append("all")
            for architecture in invalid:
                with self.subTest(kind=row["kind"], architecture=architecture):
                    data = copy.deepcopy(self.data)
                    data["artifacts"][index]["architecture"] = architecture
                    self.refused(data, "architecture")

    def test_shared_transitive_dependencies_and_order(self):
        data = copy.deepcopy(self.data)
        extra = copy.deepcopy(data["artifacts"][3])
        extra["id"] = "blob-second"
        data["artifacts"].append(extra)
        data["artifacts"][2]["dependencies"] = ["blob-second", "blob"]
        tool_rows = [x for x in data["artifacts"] if x["kind"] == "toolchain"]
        tool_rows[0]["dependencies"] = [tool_rows[1]["id"], tool_rows[2]["id"]]
        tool_rows[1]["dependencies"] = [tool_rows[2]["id"]]
        public = recipe.parse_descriptor(encoded(data))
        for row in data["artifacts"]:
            row["dependencies"].reverse()
        data["artifacts"].reverse()
        self.assertEqual(recipe.parse_descriptor(encoded(data)), public)

    def test_trust_and_all_origins_checked_before_file_open(self):
        for trusted in ["0" * 64, "x", None]:
            with mock.patch.object(
                recipe.os, "open", side_effect=AssertionError("file opened")
            ), self.assertRaises(recipe.RecipeError):
                recipe.verify_retained(
                    self.raw, self.choices, expected_sha256=trusted
                )
        for choices in [
            {},
            {**self.choices, "extra": next(iter(self.choices.values()))},
        ]:
            with mock.patch.object(
                recipe.os, "open", side_effect=AssertionError("file opened")
            ), self.assertRaisesRegex(recipe.RecipeError, "explicit"):
                self.verify(choices=choices)
        last = self.descriptor.artifacts[-1].id
        for choice in [
            None,
            recipe.RetainedFile(self.choices[last].path, "https://evil.example/x"),
        ]:
            choices = {**self.choices, last: choice}
            with mock.patch.object(
                recipe.os, "open", side_effect=AssertionError("file opened")
            ), self.assertRaisesRegex(recipe.RecipeError, "origin"):
                self.verify(choices=choices)
        changed = copy.deepcopy(self.data)
        changed["recipe"] = "other-identity"
        with self.assertRaisesRegex(recipe.RecipeError, "trusted identity"):
            self.verify(raw=encoded(changed))

    def test_digest_guard_refuses_same_size_wrong_bytes(self):
        path = Path(self.choices["base"].path)
        path.write_bytes(b"X" * path.stat().st_size)
        with self.assertRaisesRegex(recipe.RecipeError, "wrong SHA256"):
            self.verify()

    def test_wrong_size_and_missing_file(self):
        path = Path(self.choices["base"].path)
        for value in [b"", b"x", b"x" * 100]:
            path.write_bytes(value)
            with self.assertRaisesRegex(recipe.RecipeError, "byte length"):
                self.verify()
        path.unlink()
        with self.assertRaisesRegex(recipe.RecipeError, "inaccessible"):
            self.verify()

    def test_path_refusal_and_file_types(self):
        old = self.choices["base"]
        for path in [
            None,
            "relative",
            "/",
            old.path + "/",
            old.path + "/../x",
            old.path.replace("/base", "//base"),
            old.path + "\x00",
            old.path.replace("/base", "/./base"),
        ]:
            choices = {**self.choices, "base": recipe.RetainedFile(path, old.origin)}
            with self.subTest(path=path), self.assertRaises(recipe.RecipeError):
                self.verify(choices=choices)
        path = self.root / "alias"
        path.symlink_to(old.path)
        choices = {**self.choices, "base": recipe.RetainedFile(str(path), old.origin)}
        with self.assertRaisesRegex(recipe.RecipeError, "inaccessible"):
            self.verify(choices=choices)
        path.unlink()
        path.symlink_to(self.root, target_is_directory=True)
        choices["base"] = recipe.RetainedFile(str(path / "base"), old.origin)
        with self.assertRaisesRegex(recipe.RecipeError, "inaccessible"):
            self.verify(choices=choices)
        choices["base"] = recipe.RetainedFile(str(self.root), old.origin)
        with self.assertRaisesRegex(recipe.RecipeError, "regular"):
            self.verify(choices=choices)
        fifo = self.root / "fifo"
        os.mkfifo(fifo)
        choices["base"] = recipe.RetainedFile(str(fifo), old.origin)
        with self.assertRaisesRegex(recipe.RecipeError, "regular"):
            self.verify(choices=choices)
        os.link(old.path, self.root / "hardlink")
        with self.assertRaisesRegex(recipe.RecipeError, "regular"):
            self.verify()

    def test_read_races_short_growing_and_metadata(self):
        item = next(x for x in self.descriptor.artifacts if x.id == "base")
        path = self.choices[item.id].path
        with (
            mock.patch.object(recipe.os, "read", return_value=b""),
            self.assertRaisesRegex(recipe.RecipeError, "changed"),
        ):
            self.verify()
        with mock.patch.object(
            recipe.os, "read", side_effect=[b"x" * (item.bytes + 1)]
        ), self.assertRaisesRegex(recipe.RecipeError, "changed"):
            self.verify()
        actual = Path(path).stat()
        changed = SimpleNamespace(
            **{
                name: getattr(actual, name)
                for name in [
                    "st_dev",
                    "st_ino",
                    "st_size",
                    "st_mtime_ns",
                    "st_ctime_ns",
                ]
            }
        )
        changed.st_mtime_ns += 1
        with (
            mock.patch.object(recipe.os, "fstat", side_effect=[actual, changed]),
            self.assertRaisesRegex(recipe.RecipeError, "changed"),
        ):
            self.verify()

    def test_read_error_closes_owned_descriptors(self):
        before = set(os.listdir("/proc/self/fd"))
        with mock.patch.object(
            recipe.os, "read", side_effect=OSError("fixture read error")
        ), self.assertRaisesRegex(recipe.RecipeError, "inaccessible"):
            self.verify()
        self.assertEqual(set(os.listdir("/proc/self/fd")), before)


if __name__ == "__main__":
    unittest.main()
