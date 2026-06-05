<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Plugin;

/**
 * Framework-free host for native mailbox-form extensions (Phase 4f of
 * docs/ZF1-REMOVAL.md) — the form-build counterpart of {@see PluginHost}.
 *
 * It loads the enabled plugins from the plugin directory (honouring the same
 * `vimbadmin_plugins.<name>.disabled` switch) and keeps the ones that implement
 * {@see \ViMbAdmin_Plugin_MailboxFormExtension}. A native mailbox controller asks
 * it for the extra form fields, validates the submitted section values, and
 * applies the writebacks onto the mailbox entity — so a plugin's form section
 * (e.g. AccessPermissions' access-restriction checkboxes) renders, validates and
 * persists natively, with no ZF1 form layer.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class FormPluginHost
{
    /** @var array<int,\ViMbAdmin_Plugin_MailboxFormExtension> */
    private array $extensions = [];

    /**
     * @param array<string,mixed> $options    the merged application options
     * @param string|null         $pluginsDir defaults to `APPLICATION_PATH/plugins`
     */
    public function __construct(private readonly array $options, ?string $pluginsDir = null)
    {
        $pluginsDir ??= (defined('APPLICATION_PATH') ? APPLICATION_PATH : '') . '/plugins';

        // A plugin constructor may read getOptions() off the object it is handed
        // (it never keeps a reference), so pass a minimal options carrier.
        $ctorContext = new class ($this->options) {
            public function __construct(private array $options) {}
            public function getOptions(): array { return $this->options; }
        };

        foreach (glob(rtrim($pluginsDir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if (!empty($this->options['vimbadmin_plugins'][$name]['disabled'])) {
                continue;
            }

            require_once $file;
            $class = 'ViMbAdminPlugin_' . $name;
            if (class_exists($class)) {
                $plugin = new $class($ctorContext);
                if ($plugin instanceof \ViMbAdmin_Plugin_MailboxFormExtension) {
                    $this->extensions[] = $plugin;
                }
            }
        }
    }

    /**
     * Every extension field, in plugin order, to append to the mailbox form.
     *
     * @return \ViMbAdmin\Kernel\Form\Field[]
     */
    public function fields(?object $mailbox, array $options): array
    {
        $fields = [];
        foreach ($this->extensions as $ext) {
            foreach ($ext->nativeMailboxFields($mailbox, $options) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The first extension validation error for the submitted values, or null when
     * every extension section is valid.
     *
     * @param array<string,mixed> $values
     */
    public function validate(array $values, array $options): ?string
    {
        foreach ($this->extensions as $ext) {
            $error = $ext->nativeMailboxValidate($values, $options);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Apply every extension's writeback onto the mailbox entity.
     *
     * @param array<string,mixed> $values
     */
    public function apply(object $mailbox, array $values, array $options): void
    {
        foreach ($this->extensions as $ext) {
            $ext->nativeMailboxApply($mailbox, $values, $options);
        }
    }

    /** Number of loaded form-extension plugins (diagnostics/tests). */
    public function extensionCount(): int
    {
        return count($this->extensions);
    }
}
