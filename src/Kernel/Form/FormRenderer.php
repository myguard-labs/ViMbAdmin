<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Form;

/**
 * Renders a {@see Form} to the Bootstrap-2 markup the ViMbAdmin views use
 * (Phase 4, docs/ZF1-REMOVAL.md — the framework-free replacement for ZF1 forms'
 * decorators / the `{$form}` template output).
 *
 * Each field becomes a `.control-group` (flagged `.error` when it failed) with a
 * `.control-label` and a `.controls` wrapper holding the input and any inline
 * error. A hidden `csrf` input carries the per-session token, and a primary
 * submit button closes the form. Values and labels are HTML-escaped. This is
 * deliberately server-side only: it loses the legacy `{addJSValidator}`
 * client-side validation (the form still validates on POST and re-renders with
 * errors) — a JS-validation replacement can be layered on later.
 *
 * Kept separate from {@see Form} so the form's validation logic stays pure and
 * markup-free, and the rendering is unit-testable on its own.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class FormRenderer
{
    /**
     * Render the complete `<form>` for a POST submission to $action.
     */
    public function render(Form $form, string $action, string $submitLabel = 'Save'): string
    {
        $html  = '<form method="post" action="' . $this->esc($action) . '" class="form-horizontal">' . "\n";
        $html .= $this->formError($form);

        foreach ($form->fields() as $field) {
            $html .= $this->field($field);
        }

        $token = $form->csrfToken();
        if ($token !== '') {
            $html .= '    <input type="hidden" name="csrf" value="' . $this->esc($token) . '" />' . "\n";
        }

        $html .= '    <div class="form-actions">' . "\n";
        $html .= '        <button type="submit" class="btn btn-primary">' . $this->esc($submitLabel) . '</button>' . "\n";
        $html .= '    </div>' . "\n";
        $html .= '</form>' . "\n";

        return $html;
    }

    private function formError(Form $form): string
    {
        $error = $form->errors()['_form'] ?? null;
        if ($error === null) {
            return '';
        }

        return '    <div class="alert alert-error">' . $this->esc($error) . '</div>' . "\n";
    }

    private function field(Field $field): string
    {
        $groupClass = 'control-group' . ($field->hasError() ? ' error' : '');

        $html  = '    <div class="' . $groupClass . '">' . "\n";
        $html .= '        <label class="control-label" for="' . $this->esc($field->name) . '">'
            . $this->esc($field->label) . '</label>' . "\n";
        $html .= '        <div class="controls">' . "\n";
        $html .= '            ' . $this->input($field) . "\n";

        if ($field->hasError()) {
            $html .= '            <span class="help-inline">' . $this->esc((string) $field->error()) . '</span>' . "\n";
        }

        $html .= '        </div>' . "\n";
        $html .= '    </div>' . "\n";

        return $html;
    }

    private function input(Field $field): string
    {
        $name = $this->esc($field->name);

        if ($field->type === 'checkbox') {
            $checked = $field->value() ? ' checked="checked"' : '';

            return '<input type="checkbox" name="' . $name . '" id="' . $name . '" value="1"' . $checked . ' />';
        }

        if ($field->type === 'textarea') {
            $value = $field->value();
            $value = $value === null ? '' : (string) $value;

            return '<textarea name="' . $name . '" id="' . $name . '" rows="3">' . $this->esc($value) . '</textarea>';
        }

        if ($field->type === 'select') {
            $current = $field->value();
            $current = $current === null ? '' : (string) $current;

            $html = '<select name="' . $name . '" id="' . $name . '">' . "\n";
            foreach ($field->options() as $optValue => $optLabel) {
                $selected = ((string) $optValue === $current) ? ' selected="selected"' : '';
                $html .= '                <option value="' . $this->esc((string) $optValue) . '"' . $selected . '>'
                    . $this->esc($optLabel) . '</option>' . "\n";
            }
            $html .= '            </select>';

            return $html;
        }

        $type  = in_array($field->type, ['text', 'password', 'email', 'hidden'], true) ? $field->type : 'text';
        $value = $field->value();
        $value = $value === null ? '' : (string) $value;

        // Never echo a submitted password back into the markup.
        if ($field->type === 'password') {
            $value = '';
        }

        $readonly = $field->isReadonly() ? ' readonly="readonly"' : '';

        return '<input type="' . $type . '" name="' . $name . '" id="' . $name
            . '" value="' . $this->esc($value) . '"' . $readonly . ' />';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
