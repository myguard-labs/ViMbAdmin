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
 * @copyright Copyright (c) 2011 - 2014 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 * @author Open Source Solutions Limited <info _at_ opensolutions.ie>
 * @author Barry O'Donovan <barry _at_ opensolutions.ie>
 * @author Roland Huszti <roland _at_ opensolutions.ie>
 */

/**
 * The AdditionalInfo plugin
 *
 * @package ViMbAdmin
 * @subpackage Plugins
 */
class ViMbAdminPlugin_DirectoryEntry extends ViMbAdmin_Plugin implements OSS_Plugin_Observer, ViMbAdmin_Plugin_MailboxFormExtension
{
    /**
     * The directory-entry attributes, in form order, with their input type. The
     * field name is `plugin_directoryEntry_<Attr>` and the entity accessor is
     * `get<Attr>`/`set<Attr>` (the same convention the ZF1 subform's
     * prepare()/formToEntity() use). Source of truth for the native form section.
     *
     * @var array<string,string> attribute => input type
     */
    private const DE_ATTRS = [
        'PersonalTitle' => 'text', 'GivenName' => 'text', 'Sn' => 'text',
        'DisplayName' => 'text', 'Initials' => 'text', 'BusinessCategory' => 'text',
        'EmployeeType' => 'text', 'Title' => 'text', 'DepartmentNumber' => 'text',
        'Ou' => 'text', 'RoomNumber' => 'text', 'O' => 'text', 'CarLicense' => 'text',
        'EmployeeNumber' => 'text', 'Manager' => 'text', 'Secretary' => 'text',
        'Mail' => 'text', 'HomePhone' => 'text', 'Mobile' => 'text', 'Pager' => 'text',
        'TelephoneNumber' => 'text', 'FacsimileTelephoneNumber' => 'text',
        'HomePostalAddress' => 'textarea', 'LabeledURI' => 'textarea',
        'JpegPhoto' => 'text', 'PreferredLanguage' => 'text',
    ];


    public function __construct(object $controller)
    {
        parent::__construct($controller, get_class($this));

        // no setup tasks are required
        //
        // typically you might load an config file for example, but as this is a system
        // plugin, we can use the main application.ini for that.
    }
    /**
     * Deletes the directory entry with its mailbox.
     *
     * @param ViMbAdmin_Plugin_MailboxContext $controller
     * @param array<string,mixed>|null $params
     * @return void
     * @access public
     */
    public function mailbox_purge_preFlush($controller, $params)
    {
        $mailbox = $controller->getMailbox();

        if ($de = $mailbox->getDirectoryEntry()) {
            $controller->getD2EM()->remove($de);
            $controller->getD2EM()->flush();
        }
    }

    // -- Native mailbox-form extension ---------------------------------------

    /**
     * Which attributes are disabled via vimbadmin_plugins.DirectoryEntry.disabled_elements.
     *
     * @param array<string,mixed> $options
     * @return array<string,bool>
     */
    private function _disabled(array $options): array
    {
        if (!array_key_exists('vimbadmin_plugins', $options)) {
            return [];
        }
        if (!is_array($options['vimbadmin_plugins'])) {
            throw new \TypeError('plugin options must be an array');
        }
        if (!array_key_exists('DirectoryEntry', $options['vimbadmin_plugins'])) {
            return [];
        }
        if (!is_array($options['vimbadmin_plugins']['DirectoryEntry'])) {
            throw new \TypeError('DirectoryEntry options must be an array');
        }
        if (!array_key_exists('disabled_elements', $options['vimbadmin_plugins']['DirectoryEntry'])) {
            return [];
        }
        $disabled = $options['vimbadmin_plugins']['DirectoryEntry']['disabled_elements'];
        if (!is_array($disabled)) {
            throw new \TypeError('disabled_elements must be an array');
        }
        $result = [];
        foreach ($disabled as $key => $value) {
            if (!is_string($key)) {
                throw new \TypeError('disabled element names must be strings');
            }
            if (is_bool($value)) {
                $result[$key] = $value;
            } elseif (is_string($value) && ($value === '0' || $value === '1')) {
                $result[$key] = $value === '1';
            } elseif (is_int($value) && ($value === 0 || $value === 1)) {
                $result[$key] = $value === 1;
            } else {
                throw new \TypeError('disabled element values must be boolean');
            }
        }
        return $result;
    }

    /**
     * DirectoryEntry.jpegPhoto is still stored through Doctrine's legacy
     * serialized-scalar object type, so the native text field only replays
     * string-ish values.
     */
    private function _fieldValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * The legacy jpegPhoto column persists a serialized scalar, not an object.
     *
     * @return mixed
     */
    private function _jpegPhotoValue(mixed $value)
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function _persistDirectoryEntry(object $em, \Entities\DirectoryEntry $dentry): void
    {
        if (!is_callable([ $em, 'persist' ])) {
            return;
        }

        call_user_func([ $em, 'persist' ], $dentry);
    }

    private function _setJpegPhoto(\Entities\DirectoryEntry $dentry, mixed $value): void
    {
        if (!is_callable([ $dentry, 'setJpegPhoto' ])) {
            return;
        }

        call_user_func([ $dentry, 'setJpegPhoto' ], $this->_jpegPhotoValue($value));
    }

    private function _setScalarAttribute(\Entities\DirectoryEntry $dentry, string $attr, string $value): void
    {
        match($attr) {
            'PersonalTitle' => $dentry->setPersonalTitle($value),
            'GivenName' => $dentry->setGivenName($value),
            'Sn' => $dentry->setSn($value),
            'DisplayName' => $dentry->setDisplayName($value),
            'Initials' => $dentry->setInitials($value),
            'BusinessCategory' => $dentry->setBusinessCategory($value),
            'EmployeeType' => $dentry->setEmployeeType($value),
            'Title' => $dentry->setTitle($value),
            'DepartmentNumber' => $dentry->setDepartmentNumber($value),
            'Ou' => $dentry->setOu($value),
            'RoomNumber' => $dentry->setRoomNumber($value),
            'O' => $dentry->setO($value),
            'CarLicense' => $dentry->setCarLicense($value),
            'EmployeeNumber' => $dentry->setEmployeeNumber($value),
            'Manager' => $dentry->setManager($value),
            'Secretary' => $dentry->setSecretary($value),
            'Mail' => $dentry->setMail($value),
            'HomePhone' => $dentry->setHomePhone($value),
            'Mobile' => $dentry->setMobile($value),
            'Pager' => $dentry->setPager($value),
            'TelephoneNumber' => $dentry->setTelephoneNumber($value),
            'FacsimileTelephoneNumber' => $dentry->setFacsimileTelephoneNumber($value),
            'HomePostalAddress' => $dentry->setHomePostalAddress($value),
            'LabeledURI' => $dentry->setLabeledURI($value),
            'PreferredLanguage' => $dentry->setPreferredLanguage($value),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,\ViMbAdmin\Kernel\Form\Field>
     */
    public function nativeMailboxFields(?\Entities\Mailbox $mailbox, array $options): array
    {
        $disabled = $this->_disabled($options);
        $dentry   = $mailbox !== null ? $mailbox->getDirectoryEntry() : null;
        $identity = array_key_exists('identity', $options) ? $options['identity'] : [];
        if (!is_array($identity)) {
            throw new \TypeError('identity options must be an array');
        }
        $orgname = array_key_exists('orgname', $identity) ? $identity['orgname'] : null;
        if ($orgname !== null && !is_string($orgname)) {
            throw new \TypeError('identity orgname must be a string');
        }

        $fields = [];
        foreach (self::DE_ATTRS as $attr => $type) {
            // DisplayName/Initials are hidden (not removed) when disabled in ZF1;
            // every other disabled attribute is dropped from the form entirely.
            if (!empty($disabled[ $attr ]) && !in_array($attr, [ 'DisplayName', 'Initials' ], true)) {
                continue;
            }

            $field = new \ViMbAdmin\Kernel\Form\Field("plugin_directoryEntry_{$attr}", _($attr), $type);

            $getFn = 'get' . $attr;
            $value = $dentry !== null ? $this->_fieldValue($dentry->$getFn()) : null;
            if ($value !== null) {
                $field->setValue($value);
            } elseif ($attr === 'O' && $orgname) {
                $field->setValue($orgname);
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $options
     */
    public function nativeMailboxValidate(array $values, array $options): ?string
    {
        // The directory-entry attributes are free-form text; no cross-field rule.
        return null;
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $options
     * @param object|null $em
     */
    public function nativeMailboxApply(\Entities\Mailbox $mailbox, array $values, array $options, ?object $em = null): void
    {
        $username = $mailbox->requiredUsername();
        // The DirectoryEntry is the inverse side of the relation, so a NEW one must
        // be persisted explicitly (it is not cascade-persisted via the mailbox).
        $dentry = $mailbox->getDirectoryEntry();
        $isNew  = $dentry === null;

        if ($isNew) {
            $dentry = new \Entities\DirectoryEntry();
            $dentry->setMailbox($mailbox);
            $mailbox->setDirectoryEntry($dentry);
            $dentry->setVimbCreated(new \DateTime());
            if ($em !== null) {
                $this->_persistDirectoryEntry($em, $dentry);
            }
        }

        $disabled = $this->_disabled($options);
        foreach (array_keys(self::DE_ATTRS) as $attr) {
            $key = "plugin_directoryEntry_{$attr}";
            if (!array_key_exists($key, $values)) {
                continue;
            }
            // A disabled-and-removed attribute has no field; skip its writeback so
            // we don't clobber an existing value with empty.
            if (!empty($disabled[ $attr ]) && !in_array($attr, [ 'DisplayName', 'Initials' ], true)) {
                continue;
            }

            if ($attr === 'JpegPhoto') {
                $this->_setJpegPhoto($dentry, $values[ $key ]);
                continue;
            }

            if (!is_string($values[$key])) {
                throw new \TypeError('DirectoryEntry field values must be strings');
            }
            $this->_setScalarAttribute($dentry, $attr, $values[ $key ]);
        }

        // `mail` always tracks the mailbox address — set it AFTER the attribute
        // loop so the (possibly empty) submitted Mail field never clobbers it.
        $dentry->setMail($username);
        $dentry->setVimbUpdate(new \DateTime());
    }
}
