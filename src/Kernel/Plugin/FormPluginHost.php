<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Plugin;

/**
 * Framework-free host for native mailbox-form extensions (Phase 4f of
 * docs/ZF1-REMOVAL.md) — the form-build counterpart of {@see PluginHost}.
 *
 * It loads the enabled plugins from the plugin directory (honouring the same
 * `vimbadmin_plugins.<name>.enabled` opt-in switch) and keeps the ones that implement
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
            /** @var array<string,mixed> */
            private array $options;

            /** @param array<string,mixed> $options */
            public function __construct(array $options)
            {
                $this->options = $options;
            }

            /** @return array<string,mixed> */
            public function getOptions(): array
            {
                return $this->options;
            }
        };

        foreach (glob(rtrim($pluginsDir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            // Opt-in: load only when `vimbadmin_plugins.<name>.enabled` is true.
            if (!$this->pluginEnabled($name)) {
                continue;
            }

            $pluginPath = rtrim($pluginsDir, '/') . '/' . $name;
            require_once $pluginPath . '.php';
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
     * @param array<string,mixed> $options
     * @return \ViMbAdmin\Kernel\Form\Field[]
     */
    public function fields(?object $mailbox, array $options): array
    {
        /** @var \Entities\Mailbox|null $nativeMailbox */
        $nativeMailbox = $mailbox;
        $fields = [];
        foreach ($this->extensions as $ext) {
            foreach ($ext->nativeMailboxFields($nativeMailbox, $options) as $field) {
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
     * @param array<string,mixed> $options
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
     * Apply every extension's writeback onto the mailbox entity. The entity
     * manager is passed through for sections that own a separate entity they must
     * persist themselves (e.g. DirectoryEntry).
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $options
     */
    public function apply(object $mailbox, array $values, array $options, ?object $em = null): void
    {
        /** @var \Entities\Mailbox $nativeMailbox */
        $nativeMailbox = $mailbox;
        foreach ($this->extensions as $ext) {
            $ext->nativeMailboxApply($nativeMailbox, $values, $options, $em);
        }
    }

    /** Number of loaded form-extension plugins (diagnostics/tests). */
    public function extensionCount(): int
    {
        return count($this->extensions);
    }

    private function pluginEnabled(string $name): bool
    {
        if (!array_key_exists('vimbadmin_plugins', $this->options)) {
            return false;
        }
        $plugins = $this->options['vimbadmin_plugins'];
        if ($plugins === null) {
            throw new \TypeError('Plugin configuration must be an array.');
        }
        if (!is_array($plugins)) {
            throw new \TypeError('Plugin configuration must be an array.');
        }

        $entry = $plugins[$name] ?? null;
        if (!is_array($entry)) {
            return false;
        }

        return in_array($entry['enabled'] ?? false, [true, 1, '1'], true);
    }
}
