<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 - 2014 Open Source Solutions Limited
 *
 * ViMbAdmin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ViMbAdmin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ViMbAdmin.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Open Source Solutions Limited T/A Open Solutions
 *   147 Stepaside Park, Stepaside, Dublin 18, Ireland.
 *   Barry O'Donovan <barry _at_ opensolutions.ie>
 *
 * @copyright Copyright (c) 2014 Matthias Fechner
 * @copyright Copyright (c) 2022 Daniel Rudolf
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 * @author Matthias Fechner <matthias _at_ fechner.net>
 * @author Daniel Rudolf <vimbadmin _at_ daniel-rudolf.de>
 */

/**
 * The Mailbox Automatic Aliases Plugin
 *
 * The plugin ensures that a required set of aliases for a domain are existent.
 *
 * Required aliases:
 *   postmaster@domain.tld
 *   abuse@domain.tld
 * Optional aliases:
 *   webmaster@domain.tld
 *   hostmaster@domains.tld
 *
 * Add the following lines to configs/application.ini:
 *   vimbadmin_plugins.MailboxAutomaticAliases.disabled = false
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultAliases[] = "postmaster"
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultAliases[] = "abuse"
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultAliases[] = "hostmaster"
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultAliases[] = "webmaster"
 *
 * Automatic aliases are created when a new mailbox or alias is created. They
 * either use a configured defaultMapping, or the just created mailbox or alias
 * as goto address. See configs/application.ini:
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultMapping.postmaster = "@other.tld"
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultMapping.abuse = "postmaster"
 *   vimbadmin_plugins.MailboxAutomaticAliases.defaultMapping.* = "root@domain.tld"
 *
 * @package ViMbAdmin
 * @subpackage Plugins
 */

class ViMbAdminPlugin_MailboxAutomaticAliases extends ViMbAdmin_Plugin implements OSS_Plugin_Observer
{
    /** @var array<int, string> */
    private $defaultAliases;

    /** @var array<string, string> */
    private $defaultMapping;

    public function __construct(object $controller)
    {
        parent::__construct($controller, get_class($this));

        if (!method_exists($controller, 'getOptions')) {
            throw new InvalidArgumentException('MailboxAutomaticAliases requires a controller with getOptions().');
        }

        $options = $controller->getOptions();
        if (!is_array($options)) {
            throw new UnexpectedValueException('MailboxAutomaticAliases controller getOptions() must return an array.');
        }

        $plugins = array_key_exists('vimbadmin_plugins', $options) ? $options['vimbadmin_plugins'] : [];
        if (!is_array($plugins)) {
            throw new UnexpectedValueException('vimbadmin_plugins must be an array.');
        }
        $config = array_key_exists('MailboxAutomaticAliases', $plugins) ? $plugins['MailboxAutomaticAliases'] : [];
        if (!is_array($config)) {
            throw new UnexpectedValueException('MailboxAutomaticAliases options must be an array.');
        }

        $aliases = array_key_exists('defaultAliases', $config) ? $config['defaultAliases'] : [];
        if (!is_array($aliases) || !array_is_list($aliases)) {
            throw new UnexpectedValueException('defaultAliases must be a list.');
        }
        foreach ($aliases as $alias) {
            if (!is_string($alias)) {
                throw new UnexpectedValueException('defaultAliases entries must be strings.');
            }
        }
        $uniqueAliases = [];
        $seenAliases = [];
        foreach ($aliases as $alias) {
            $key = strtolower($alias);
            if (!isset($seenAliases[$key])) {
                $seenAliases[$key] = true;
                $uniqueAliases[] = $alias;
            }
        }
        $aliases = $uniqueAliases;
        $this->defaultAliases = $aliases;

        $mapping = array_key_exists('defaultMapping', $config) ? $config['defaultMapping'] : [];
        if (!is_array($mapping)) {
            throw new UnexpectedValueException('defaultMapping must be an array.');
        }
        foreach ($mapping as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new UnexpectedValueException('defaultMapping must be a string map.');
            }
        }
        $this->defaultMapping = $mapping;
    }

    /** @return array{address:string,goto:string} */
    private function requiredAliasIdentity(\Entities\Alias $alias): array
    {
        $address = $alias->getAddress();
        if ($address === null) {
            throw new \LogicException('Alias address cannot be null.');
        }

        $goto = $alias->getGoto();
        if ($goto === null) {
            throw new \LogicException('Alias goto cannot be null.');
        }

        return [ 'address' => $address, 'goto' => $goto ];
    }

    /**
     * Create automatic aliases after a mailbox was created
     *
     * @param ViMbAdmin_Plugin_MailboxContext $controller
     * @param array<string, mixed>|null $options
     * @return void
     */
    public function mailbox_add_addPostflush(ViMbAdmin_Plugin_MailboxContext $controller, $options)
    {
        $domain = $controller->getDomain()->requiredDomainName();
        $mailbox = $controller->getMailbox()->requiredUsername();

        if ($this->defaultAliases) {
            // no default aliases are required to exist if the whole domain is aliased
            if ($this->hasActiveDomainAlias($controller)) {
                return;
            }

            $created = false;
            foreach ($this->defaultAliases as $item) {
                // automatic alias exists
                if ($this->getAlias($controller, $item . '@' . $domain) !== null) {
                    continue;
                }

                // create automatic alias
                $alias = $this->createAutomaticAlias($controller, $item, $mailbox);
                $created = true;
                $identity = $this->requiredAliasIdentity($alias);

                $message = _('Auto-created alias %s -> %s.');
                $controller->addMessage(sprintf($message, $identity['address'], $identity['goto']));
            }
            if ($created) {
                $controller->getD2EM()->flush();
            }
        }
    }

    /**
     * Checks whether a mailbox is an automatic alias' goto mailbox and
     * prevents its deletion
     *
     * @param ViMbAdmin_Plugin_MailboxContext $controller
     * @param array<string, mixed>|null $options
     * @return bool
     */
    public function mailbox_purge_preRemove(ViMbAdmin_Plugin_MailboxContext $controller, $options)
    {
        $domain = $controller->getDomain()->requiredDomainName();
        $mailbox = $controller->getMailbox()->getUserName();

        if ($this->defaultAliases) {
            // no default aliases are required to exist if the whole domain is aliased
            if ($this->hasActiveDomainAlias($controller)) {
                return true;
            }

            foreach ($this->defaultAliases as $item) {
                // prevent deletion of an automatic alias' goto mailbox
                $alias = $this->getAlias($controller, $item . '@' . $domain);
                if ($alias !== null && $alias['goto'] === $mailbox) {
                    $message = _('Mailbox %s is used to fullfill automatic alias %s. '
                        . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>. '
                        . 'If you want to delete it, update the alias to use a different goto address first.');
                    $controller->addMessage(sprintf($message, $mailbox, $alias['address']), OSS_Message::ERROR);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Checks whether a mailbox is an automatic alias' goto mailbox and
     * prevents disabling the mailbox
     *
     * @param ViMbAdmin_Plugin_MailboxContext $controller
     * @param array{active: bool|null} $options
     * @return bool
     */
    public function mailbox_toggleActive_preToggle(ViMbAdmin_Plugin_MailboxContext $controller, $options)
    {
        $domain = $controller->getDomain()->requiredDomainName();
        $mailbox = $controller->getMailbox()->getUserName();

        if ($options['active'] && $this->defaultAliases) {
            // no default aliases are required to exist if the whole domain is aliased
            if ($this->hasActiveDomainAlias($controller)) {
                return true;
            }

            foreach ($this->defaultAliases as $item) {
                // prevent toggling an automatic alias' goto mailbox off
                $alias = $this->getAlias($controller, $item . '@' . $domain);
                if ($alias !== null && $alias['goto'] === $mailbox) {
                    $message = _('Mailbox %s is used to fullfill automatic alias %s. '
                        . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>. '
                        . 'If you want to disable it, update the alias to use a different goto address first.');
                    $controller->addMessage(sprintf($message, $mailbox, $alias['address']), OSS_Message::ERROR);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Create automatic aliases after an alias was created
     *
     * @param ViMbAdmin_Plugin_AliasContext $controller
     * @param array<string, mixed>|null $options
     * @return void
     */
    public function alias_add_addPostflush(ViMbAdmin_Plugin_AliasContext $controller, $options)
    {
        $domain = $controller->getDomain()->requiredDomainName();

        if ($this->defaultAliases) {
            $identity = $this->requiredAliasIdentity($controller->getAlias());
            $aliasAddress = $identity['address'];

            // no default aliases are required to exist if the whole domain is aliased
            if ($this->hasActiveDomainAlias($controller)) {
                return;
            }

            // create automatic aliases, if required
            $created = false;
            foreach ($this->defaultAliases as $item) {
                // automatic alias exists
                if ($this->getAlias($controller, $item . '@' . $domain) !== null) {
                    continue;
                }

                // create automatic alias
                $alias = $this->createAutomaticAlias($controller, $item, $aliasAddress);
                $created = true;
                $automaticIdentity = $this->requiredAliasIdentity($alias);

                $message = _('Auto-created alias %s -> %s.');
                $controller->addMessage(sprintf($message, $automaticIdentity['address'], $automaticIdentity['goto']));
            }
            if ($created) {
                $controller->getD2EM()->flush();
            }
        }
    }

    /**
     * Checks whether an alias is an automatic alias or an automatic alias'
     * goto alias, and prevents its deletion
     *
     * @param ViMbAdmin_Plugin_AliasContext $controller
     * @param array<string, mixed>|null $options
     * @return bool
     */
    public function alias_delete_preRemove(ViMbAdmin_Plugin_AliasContext $controller, $options)
    {
        $domain = $controller->getDomain()->requiredDomainName();

        if ($this->defaultAliases) {
            $identity = $this->requiredAliasIdentity($controller->getAlias());
            $aliasAddress = $identity['address'];

            // if we're about to delete a domain alias, ensure that distinct automatic aliases exist
            if ('@' . $domain === $aliasAddress) {
                foreach ($this->defaultAliases as $item) {
                    $alias = $this->getAlias($controller, $item . '@' . $domain);
                    if ($alias === null || !$alias['active']) {
                        $message = _('Alias %s is used to fullfill automatic alias %s. '
                            . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>. '
                            . 'If you want to delete it, create a distinct alias first.');
                        $controller->addMessage(sprintf($message, $aliasAddress, $item . '@' . $domain), OSS_Message::ERROR);
                        return false;
                    }
                }

                return true;
            }

            // no default aliases are required to exist if the whole domain is aliased
            if ($this->hasActiveDomainAlias($controller)) {
                return true;
            }

            foreach ($this->defaultAliases as $item) {
                // prevent deletion of an automatic alias
                if ($item . '@' . $domain === $aliasAddress) {
                    $message = _('Alias %s is required and cannot be deleted. '
                        . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>.');
                    $controller->addMessage(sprintf($message, $aliasAddress), OSS_Message::ERROR);
                    return false;
                }

                // prevent deletion of an automatic alias' goto alias
                $alias = $this->getAlias($controller, $item . '@' . $domain);
                if ($alias !== null && $alias['goto'] === $aliasAddress) {
                    $message = _('Alias %s is used to fullfill automatic alias %s. '
                        . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>. '
                        . 'If you want to delete it, update the alias to use a different goto address first.');
                    $controller->addMessage(sprintf($message, $aliasAddress, $alias['address']), OSS_Message::ERROR);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Checks whether an alias is an automatic alias or an automatic alias'
     * goto alias, and prevents disabling the alias
     *
     * @param ViMbAdmin_Plugin_AliasContext $controller
     * @param array{active: bool|null} $options
     * @return bool
     */
    public function alias_toggleActive_preToggle(ViMbAdmin_Plugin_AliasContext $controller, $options)
    {
        $domain = $controller->getDomain()->requiredDomainName();

        if ($options['active'] && $this->defaultAliases) {
            $identity = $this->requiredAliasIdentity($controller->getAlias());
            $aliasAddress = $identity['address'];

            // if we're about to disable a domain alias, ensure that distinct automatic aliases exist
            if ('@' . $domain === $aliasAddress) {
                foreach ($this->defaultAliases as $item) {
                    $alias = $this->getAlias($controller, $item . '@' . $domain);
                    if ($alias === null || !$alias['active']) {
                        $message = _('Alias %s is used to fullfill automatic alias %s. '
                            . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>. '
                            . 'If you want to disable it, create a distinct alias first.');
                        $controller->addMessage(sprintf($message, $aliasAddress, $item . '@' . $domain), OSS_Message::ERROR);
                        return false;
                    }
                }

                return true;
            }

            // no default aliases are required to exist if the whole domain is aliased
            if ($this->hasActiveDomainAlias($controller)) {
                return true;
            }

            foreach ($this->defaultAliases as $item) {
                // prevent toggling an automatic alias off
                if ($item . '@' . $domain === $aliasAddress) {
                    $message = _('Alias %s is required and cannot be disabled. '
                        . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>');
                    $controller->addMessage(sprintf($message, $aliasAddress), OSS_Message::ERROR);
                    return false;
                }

                // prevent toggling an automatic alias' goto alias off
                $alias = $this->getAlias($controller, $item . '@' . $domain);
                if ($alias !== null && $alias['goto'] === $aliasAddress) {
                    $message = _('Alias %s is used to fullfill automatic alias %s. '
                        . 'See <a href="https://www.ietf.org/rfc/rfc2142.txt" target="page">RFC2142</a>. '
                        . 'If you want to disable it, update the alias to use a different goto address first.');
                    $controller->addMessage(sprintf($message, $aliasAddress, $alias['address']), OSS_Message::ERROR);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns an alias' entity
     *
     * @param ViMbAdmin_Plugin_MutationContext $controller
     * @param string $alias
     * @return array{address: string, goto: string, active: bool}|null
     */
    private function getAlias(ViMbAdmin_Plugin_MutationContext $controller, $alias)
    {
        $domain = $controller->getDomain();
        $domain->requiredId();
        $admin = $controller->getAdmin();
        if (!$admin->isSuper() && !$admin->canManageDomain($domain)) {
            throw new \LogicException('Admin cannot manage the automatic alias domain.');
        }

        $aliasRepository = $controller->getD2EM()->getRepository("\\Entities\\Alias");
        if (!$aliasRepository instanceof \Repositories\Alias) {
            throw new \LogicException('Alias entity must use Repositories\\Alias.');
        }
        $aliasEntity = $aliasRepository->findOneBy([ 'address' => $alias, 'Domain' => $domain ]);
        if (!$aliasEntity instanceof \Entities\Alias) {
            return null;
        }

        return $this->requiredAliasIdentity($aliasEntity) + [ 'active' => $aliasEntity->getActive() === true ];
    }

    /**
     * Checks whether a domain alias exists and is active
     *
     * @param ViMbAdmin_Plugin_MutationContext $controller
     * @return bool
     */
    private function hasActiveDomainAlias(ViMbAdmin_Plugin_MutationContext $controller)
    {
        $alias = $this->getAlias($controller, '@' . $controller->getDomain()->requiredDomainName());
        return $alias !== null && $alias['active'];
    }

    /**
     * Creates a new alias
     *
     * @param ViMbAdmin_Plugin_MutationContext $controller
     * @param string $item
     * @param string $goto
     * @return \Entities\Alias
     */
    private function createAutomaticAlias(ViMbAdmin_Plugin_MutationContext $controller, $item, $goto)
    {
        $domain = $controller->getDomain()->requiredDomainName();

        if (isset($this->defaultMapping[$item])) {
            $goto = $this->defaultMapping[$item];
        } elseif (isset($this->defaultMapping['*'])) {
            $goto = $this->defaultMapping['*'];
        }

        if (strpos($goto, '@') === false) {
            $goto = $goto . '@' . $domain;
        } elseif ($goto[0] === '@') {
            $goto = $item . $goto;
        }

        $alias = new \Entities\Alias();
        $alias->setAddress($item . '@' . $domain);
        $alias->setGoto($goto);
        $alias->setDomain($controller->getDomain());
        $alias->setActive(true);
        $alias->setCreated(new \DateTime());
        $controller->getD2EM()->persist($alias);

        $controller->getDomain()->increaseAliasCount();
        return $alias;
    }

}
