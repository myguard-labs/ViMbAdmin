"""Explicit source identities; selection grants no execution or qualification."""
HISTORICAL = {
    'name': 'historical',
    'recipe_sha256': None,
    'base_sha256': '04e8f2461a16a44e78a370284471f29616cdd9baf1893df25807b71f985b6c94',
    'base_bytes': 621284352,
    'root_start_bytes': 1074790400,
    'gpt_tail_bytes': 16896,
    'alpine_sha256': '2003971c6efd8d91676f9527ee06c561fe4019350324941e8afdc5608d81ed5f',
    'alpine_image': 'sha256:b92841763c5c2140a20eeb2a44e94ebb0e80fd51d9d6b5895ea72fa999525d68',
    'alpine_bytes': None,
    'alpine_manifest': None,
    'alpine_origin': None,
}
PUBLIC = {
    'name': 'public-c22-alpine-c24',
    'recipe_sha256': '84a3ead5b6068d228ce244d3e54172f7640ef7138efde1b9d5a15e0eb2a1df07',
    'base_sha256': '24036a8b79cbb11d809752c60f2f885b1a3b02b1d663faa0b648eb87bd7a256e',
    'base_bytes': 349831168,
    # Read-only GPT header/entry CRC-checked prefix of this exact base.
    'root_start_bytes': 134217728,
    'gpt_tail_bytes': 16896,
    'alpine_sha256': '8cf1273f37e9fc9c54f5f55f0935beb52c0f0afe5228d8161fbe872199f4d36e',
    'alpine_image': 'sha256:411bc66a7adb849165d4e733707efe59d6b5edf8b4d96506cb6a1e31ba270199',
    'alpine_bytes': 8089600,
    'alpine_manifest': 'sha256:f25ebd2af60b9de8e26e5100fe6f493beb81bff010f27e84f223ad19886a78f0',
    'alpine_origin': 'https://registry-1.docker.io/v2/library/alpine/manifests/sha256:f25ebd2af60b9de8e26e5100fe6f493beb81bff010f27e84f223ad19886a78f0',
}


def select(name):
    """Return a fresh copy of one named identity, never a caller-supplied profile."""
    if name == 'historical':
        return dict(HISTORICAL)
    if name == 'public-c22-alpine-c24':
        return dict(PUBLIC)
    raise ValueError('explicit reviewed recipe selection required')
