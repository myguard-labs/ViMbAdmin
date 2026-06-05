<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Form;

/**
 * One field in a native {@see Form} (Phase 4, docs/ZF1-REMOVAL.md — the
 * framework-free replacement for ZF1 form element).
 *
 * A field carries its name, a label, a type (text/password/checkbox/…), its
 * current value, and an ordered list of validation rules. Each rule is a
 * `callable(mixed $value): ?string` returning an error message, or null when the
 * value passes. Keeping rules as plain callables means the form layer needs no
 * validator framework and stays unit-testable; the common ones are produced by
 * {@see Validators}.
 *
 * The field holds its own error after {@see Form::validate()} so the template can
 * render it next to the input, exactly as the ZF1 form decorators did.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class Field
{
    private mixed $value = null;
    private ?string $error = null;
    private bool $readonly = false;
    /** @var array<int|string,string> value → label, for a `select` field. */
    private array $options = [];

    /**
     * @param array<int,callable(mixed):?string> $rules
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label = '',
        public readonly string $type = 'text',
        private array $rules = [],
    ) {
    }

    /** Append a validation rule (fluent). */
    public function addRule(callable $rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    /**
     * Mark the field read-only (rendered with a `readonly` attribute). Used by
     * edit forms where a value is shown but cannot be changed — e.g. a domain
     * name on the edit-domain form, mirroring the ZF1 `setAttrib('readonly')`.
     */
    public function setReadonly(bool $readonly = true): self
    {
        $this->readonly = $readonly;

        return $this;
    }

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    /**
     * Set the option list for a `select` field as a value→label map (e.g. the
     * domain id → name list a domain-assignment dropdown offers).
     *
     * @param array<int|string,string> $options
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /** @return array<int|string,string> */
    public function options(): array
    {
        return $this->options;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Run the rules in order, stopping at the first failure; store and return the
     * error (or null when the field is valid).
     */
    public function validate(): ?string
    {
        $this->error = null;

        foreach ($this->rules as $rule) {
            $error = $rule($this->value);
            if ($error !== null) {
                $this->error = $error;
                break;
            }
        }

        return $this->error;
    }
}
