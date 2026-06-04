<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Smarty modifier: render a byte count as a human-readable size.
 *
 * Quotas/usage are stored in the database in bytes. This formats them with
 * binary (1024-based) units and one decimal, e.g.:
 *
 *   {$domain.quota|filesize}      2147483648 -> "2 GB"
 *   {$mbox.quota_bytes|filesize}  943718     -> "0.9 MB"
 *   0 or '' -> the caller usually shows ∞/0 instead, but we return "0 B".
 *
 * Not byte-exact on purpose: rounded to one decimal for display.
 *
 * @param int|string|null $bytes
 * @return string
 */
function smarty_modifier_filesize( $bytes )
{
    $bytes = (float) $bytes;
    if( $bytes <= 0 )
        return '0 B';

    $units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
    $i = 0;
    while( $bytes >= 1024 && $i < count( $units ) - 1 )
    {
        $bytes /= 1024;
        $i++;
    }

    // Whole numbers print without a trailing ".0"; otherwise one decimal.
    $rounded = round( $bytes, 1 );
    $str = ( $rounded == (int) $rounded ) ? (string) (int) $rounded : number_format( $rounded, 1 );

    return $str . ' ' . $units[ $i ];
}
