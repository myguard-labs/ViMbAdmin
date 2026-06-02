<?php
/**
 * Smarty |htmlentities modifier.
 *
 * Smarty 5 removed the implicit "PHP function as modifier" passthrough that
 * Smarty 4 provided, so templates using {$x|htmlentities} now need an
 * explicitly registered plugin. This restores it.
 *
 * @param  string $string
 * @return string
 */
function smarty_modifier_htmlentities( $string )
{
    return htmlentities( (string) $string, ENT_QUOTES, 'UTF-8' );
}
