<?php

/**
 * OSS Framework
 *
 * This file is part of the "OSS Framework" - a library of tools, utilities and
 * extensions to the Zend Framework V1.x used for PHP application development.
 *
 * Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * All rights reserved.
 *
 * Open Source Solutions Limited is a company registered in Dublin,
 * Ireland with the Companies Registration Office (#438231). We
 * trade as Open Solutions with registered business name (#329120).
 *
 * Contact: Barry O'Donovan - info (at) opensolutions (dot) ie
 *          http://www.opensolutions.ie/
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 *
 * It is also available through the world-wide-web at this URL:
 *     http://www.opensolutions.ie/licenses/new-bsd
 *
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@opensolutions.ie so we can send you a copy immediately.
 *
 * @category   OSS
 * @package    OSS_Crypt
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */


/**
 * Bcrypt (Blowfish) hashing tools for password.
 *
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 * @category   OSS
 * @package    OSS_Crypt
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 */
class OSS_Crypt_Bcrypt
{
    /**
     * @var int The cost for the hashing algorithm
     *
     * Example values showing the cost of 10 and the average on an i7 are:
     *
     * 01: 0.001437    0.000144
     * 02: 0.001441    0.000144
     * 03: 0.002011    0.000201
     * 04: 0.017564    0.001756
     * 05: 0.029720    0.002972
     * 06: 0.055418    0.005542
     * 07: 0.109075    0.010908
     * 08: 0.207278    0.020728
     * 09: 0.407365    0.040737
     * 10: 0.839712    0.083971
     * 11: 1.674868    0.167487
     * 12: 3.336014    0.333601
     * 13: 6.699570    0.669957
     * 14: 15.655678   1.565568
     * 15: 26.771987   2.677199
     */
    private $_cost = 9;


    /**
     * @param int|numeric-string $cost Bcrypt work factor (4 through 31)
     * @throws OSS_Crypt_Exception
     * @SuppressWarnings("PHPMD.MissingImport") Legacy OSS classes use global PSR-0 names.
     */
    public function __construct($cost = 9)
    {
        if (!is_int($cost) && !(is_string($cost) && preg_match('/^\d+$/D', $cost) === 1)) {
            throw new OSS_Crypt_Exception('Bcrypt cost must be an integer between 4 and 31');
        }

        $cost = (int) $cost;
        if ($cost < 4 || $cost > 31) {
            throw new OSS_Crypt_Exception('Bcrypt cost must be an integer between 4 and 31');
        }

        $this->_cost = $cost;
    }


    /**
     * @param string $plain
     * @return non-empty-string
     * @throws OSS_Crypt_Exception
     * @SuppressWarnings("PHPMD.MissingImport") Legacy OSS classes use global PSR-0 names.
     */
    public function hash($plain)
    {
        $hash = crypt($plain, $this->generateSalt());

        if (preg_match('/^\$2a\$\d{2}\$[.\/A-Za-z0-9]{53}$/D', $hash) === 1) {
            return $hash;
        }

        throw new OSS_Crypt_Exception('Bcrypt hashing failed');
    }


    /**
     * @param string $plain
     * @param string $hash
     * @return bool
     */
    public static function verify($plain, $hash)
    {
        // Constant-time comparison (avoid timing side-channel on the hash).
        return hash_equals((string) $hash, crypt($plain, (string) $hash));
    }

    /**
     * @return non-empty-string
     * @SuppressWarnings("PHPMD.StaticAccess") OSS_String is a legacy static utility.
     */
    public function generateSalt()
    {
        $salt = OSS_String::randomFromSet('./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 21);
        $salt .= OSS_String::randomFromSet('.Oeu', 1);

        return sprintf('$2a$%02d$%s', $this->_cost, $salt);
    }

}
