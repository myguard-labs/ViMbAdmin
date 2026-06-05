<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Plugin;

use ViMbAdmin\Kernel\Flash\FlashMessages;

/**
 * Shared base for the native plugin contexts (Phase 4c of docs/ZF1-REMOVAL.md).
 *
 * Implements the {@see \ViMbAdmin_Plugin_MutationContext} surface every mutation
 * hook needs — options, the entity manager, the acting admin, the in-scope
 * domain, and a message sink. A plugin written against a ZF1 controller calls
 * exactly these methods; here they are answered from values a native controller
 * already has, so the same plugin code runs unchanged under the native kernel.
 *
 * `addMessage()` writes to the framework-free {@see FlashMessages} queue (the
 * same one {@see \ViMbAdmin\Kernel\Mvc\AbstractController::flash()} uses), so a
 * plugin notice surfaces on the next page through the `{OSS_Message}` renderer.
 * The OSS_Message level constants are already the flash level strings
 * (`success`/`error`/`info`/`warning`), so the class maps straight through; the
 * legacy `$type` argument (block/pop-up, dead in ViMbAdmin) is ignored.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
abstract class AbstractContext implements \ViMbAdmin_Plugin_MutationContext
{
    /**
     * @param object       $em      the Doctrine entity manager (typed object to
     *                     keep the kernel from naming Doctrine)
     * @param object       $admin   the acting \Entities\Admin
     * @param object       $domain  the in-scope \Entities\Domain
     * @param array<string,mixed> $options the merged application options
     * @param FlashMessages $flash  the queue plugin messages are written to
     */
    public function __construct(
        private readonly object $em,
        private readonly object $admin,
        private readonly object $domain,
        private readonly array $options,
        private readonly FlashMessages $flash,
    ) {
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function getD2EM()
    {
        return $this->em;
    }

    public function getAdmin()
    {
        return $this->admin;
    }

    public function getDomain()
    {
        return $this->domain;
    }

    public function addMessage($message, $class = null, $type = null)
    {
        $level = is_string($class) && $class !== '' ? $class : FlashMessages::SUCCESS;
        $this->flash->add((string) $message, $level);
    }
}
