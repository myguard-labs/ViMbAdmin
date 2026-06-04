<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Smarty modifier: format a unix timestamp (seconds) as a date string.
 *
 * Stock |date_format runs strtotime(), which mis-parses a bare integer
 * timestamp. This formats the integer directly with date().
 *
 *   {$mbox.last_login|unixdate}                 -> "2026-06-04 15:08"
 *   {$mbox.last_login|unixdate:"%Y-%m-%d"}      (format arg optional)
 *
 * @param int|string|null $ts     unix timestamp in seconds (0/null/'' = empty)
 * @param string          $format date() format (default "Y-m-d H:i")
 * @return string  formatted date, or '' when the timestamp is empty
 */
function smarty_modifier_unixdate( $ts, $format = 'Y-m-d H:i' )
{
    $ts = (int) $ts;
    if( $ts <= 0 )
        return '';
    return date( $format, $ts );
}
