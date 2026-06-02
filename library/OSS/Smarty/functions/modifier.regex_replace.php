<?php
/**
 * Smarty |regex_replace modifier (Smarty 4 built-in, dropped in Smarty 5).
 *
 * Usage: {$string|regex_replace:'/pattern/':'replacement'}
 *
 * @param  string       $string
 * @param  string|array $pattern
 * @param  string|array $replace
 * @return string
 */
function smarty_modifier_regex_replace( $string, $pattern, $replace )
{
    // Mirror Smarty 4's guard against the \0 null-byte injection trick.
    if ( is_array( $pattern ) ) {
        foreach ( $pattern as $key => $p ) {
            $pattern[ $key ] = smarty_modifier_regex_replace_check( $p );
        }
    } else {
        $pattern = smarty_modifier_regex_replace_check( $pattern );
    }

    return preg_replace( $pattern, $replace, (string) $string );
}

/**
 * Strip a back-reference-unsafe trailing modifier ("e") and reject embedded
 * null bytes, matching the historical Smarty behaviour.
 */
function smarty_modifier_regex_replace_check( $pattern )
{
    if ( ( $pos = strpos( $pattern, "\0" ) ) !== false ) {
        $pattern = substr( $pattern, 0, $pos );
    }
    if ( preg_match( '!([a-zA-Z\s]+)$!s', $pattern, $match ) && ( strpos( $match[1], 'e' ) !== false ) ) {
        $pattern = substr( $pattern, 0, -strlen( $match[1] ) )
                 . preg_replace( '![e\s]+!', '', $match[1] );
    }
    return $pattern;
}
