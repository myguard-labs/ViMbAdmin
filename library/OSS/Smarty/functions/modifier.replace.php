<?php
/**
 * Smarty |replace modifier (Smarty 4 built-in, dropped in Smarty 5).
 *
 * Usage: {$string|replace:'search':'replace'}
 *
 * @param  string $string
 * @param  string $search
 * @param  string $replace
 * @return string
 */
function smarty_modifier_replace( $string, $search, $replace )
{
    return str_replace( $search, $replace, (string) $string );
}
