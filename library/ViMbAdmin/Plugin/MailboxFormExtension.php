<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * Native mailbox-form extension contract (Phase 4f of docs/ZF1-REMOVAL.md) — the
 * form-build counterpart of the Phase 4b mutation contract.
 *
 * Historically a plugin extended the mailbox add/edit form by injecting a
 * ZF1 form subform in a `mailbox_add_formPostProcess` hook and reading it back
 * in `mailbox_add_addPostvalidate` / `addPreflush`. That tied form extension to
 * the ZF1 form layer and was the wall blocking a native mailbox form.
 *
 * A plugin that also implements this interface can contribute its section to the
 * NATIVE mailbox form without any ZF1 form: it returns framework-free
 * {@see ViMbAdmin\Kernel\Form\Field} objects, validates the submitted values,
 * and writes them back onto the mailbox entity. The legacy Zend hooks are kept
 * for the ZF1 path; this is the parallel native surface. A plugin not
 * implementing it simply contributes nothing to the native form.
 *
 * @package ViMbAdmin
 * @subpackage Plugin
 */
interface ViMbAdmin_Plugin_MailboxFormExtension
{
    /**
     * The fields this plugin appends to the mailbox add/edit form.
     *
     * @param \Entities\Mailbox|null $mailbox the mailbox being edited (null on
     *        add), so the fields can be pre-filled
     * @param array $options the merged application options
     * @return \ViMbAdmin\Kernel\Form\Field[]
     */
    public function nativeMailboxFields(?\Entities\Mailbox $mailbox, array $options): array;

    /**
     * Validate the submitted values for this plugin's section (cross-field rules
     * a single Field rule cannot express). Returns an error message, or null when
     * the section is valid.
     *
     * @param array<string,mixed> $values the submitted form values
     * @param array $options the merged application options
     */
    public function nativeMailboxValidate(array $values, array $options): ?string;

    /**
     * Apply the submitted values onto the mailbox entity (the native equivalent
     * of the plugin's addPostvalidate/addPreflush writeback).
     *
     * @param array<string,mixed> $values the submitted form values
     * @param array $options the merged application options
     * @param object|null $em the Doctrine entity manager, for a section that owns a
     *        SEPARATE entity it must persist itself (e.g. DirectoryEntry, whose
     *        relation is the inverse side and therefore not cascade-persisted via
     *        the mailbox). Sections that only write columns/preferences on the
     *        mailbox itself ignore it. The native host always supplies it.
     */
    public function nativeMailboxApply(\Entities\Mailbox $mailbox, array $values, array $options, ?object $em = null): void;
}
