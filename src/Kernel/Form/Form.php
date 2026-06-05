<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Form;

use ViMbAdmin\Kernel\Security\Csrf;

/**
 * A native HTML form (Phase 4, docs/ZF1-REMOVAL.md — the framework-free
 * replacement for ZF1 forms).
 *
 * Holds an ordered set of {@see Field}s, binds request data onto them, validates
 * them, and — when a {@see Csrf} service is supplied — guards the submission with
 * the same per-session token the rest of the kernel uses. It is intentionally
 * thin: it owns validation and value/error access; the controller decides what to
 * do with a valid form (persist via a service, flash, redirect) and the template
 * renders the inputs. This keeps the form layer dependency-free and unit-testable
 * with no framework, no DB and no view.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Form
{
    /** @var array<string,Field> */
    private array $fields = [];

    private ?string $submittedToken = null;
    private ?string $formError = null;
    private bool $valid = false;

    public function __construct(private readonly ?Csrf $csrf = null)
    {
    }

    public function add(Field $field): self
    {
        $this->fields[$field->name] = $field;

        return $this;
    }

    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    /** @return array<string,Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Populate the fields (and capture the CSRF token) from request data.
     * A missing key becomes false for checkboxes and null otherwise.
     *
     * @param array<string,mixed> $data
     */
    public function bind(array $data): self
    {
        foreach ($this->fields as $name => $field) {
            if (array_key_exists($name, $data)) {
                $field->setValue($data[$name]);
            } else {
                $field->setValue($field->type === 'checkbox' ? false : null);
            }
        }

        $this->submittedToken = isset($data['csrf']) ? (string) $data['csrf'] : null;

        return $this;
    }

    /**
     * Validate the CSRF token (if guarded) and every field. Binds $data first
     * when given. Returns true only if the token is valid and no field errored.
     *
     * @param array<string,mixed>|null $data
     */
    public function isValid(?array $data = null): bool
    {
        if ($data !== null) {
            $this->bind($data);
        }

        $this->valid      = true;
        $this->formError  = null;

        if ($this->csrf !== null && !$this->csrf->isValid((string) $this->submittedToken)) {
            $this->formError = 'Invalid or missing security token. Please retry from the form.';
            $this->valid     = false;
            // A bad token is a hard stop; the field errors would only be noise.
            return false;
        }

        foreach ($this->fields as $field) {
            if ($field->validate() !== null) {
                $this->valid = false;
            }
        }

        return $this->valid;
    }

    /**
     * The current per-session CSRF token to render in a hidden `csrf` input, or
     * '' when the form is not CSRF-guarded.
     */
    public function csrfToken(): string
    {
        return $this->csrf?->token() ?? '';
    }

    /**
     * @return array<string,mixed> field name → current value
     */
    public function values(): array
    {
        $out = [];
        foreach ($this->fields as $name => $field) {
            $out[$name] = $field->value();
        }

        return $out;
    }

    /**
     * @return array<string,string> field name → error message, for failed fields
     *         only; the synthetic key `_form` carries a form-level error (CSRF).
     */
    public function errors(): array
    {
        $out = [];
        if ($this->formError !== null) {
            $out['_form'] = $this->formError;
        }
        foreach ($this->fields as $name => $field) {
            if ($field->hasError()) {
                $out[$name] = (string) $field->error();
            }
        }

        return $out;
    }
}
