<?php
/**
 * Smarty plugin
 *
 * @package Smarty
 * @subpackage PluginsModifier
 */

/**
 * Smarty date_format modifier plugin
 *
 * Type:     modifier<br>
 * Name:     date_format<br>
 * Purpose:  format datestamps via strftime<br>
 * Input:<br>
 *          - string: input date string
 *          - format: strftime format for output
 *          - default_date: default date if $string is empty
 *
 * @link http://www.smarty.net/manual/en/language.modifier.date.format.php date_format (Smarty online manual)
 * @author Monte Ohrt <monte at ohrt dot com>
 * @param string $string       input date string
 * @param string $format       strftime format for output
 * @param string $default_date default date if $string is empty
 * @param string $formatter    either 'strftime' or 'auto'
 * @return string |void
 * @uses smarty_make_timestamp()
 * @see http://www.smarty.net/forums/viewtopic.php?t=10632
 */
function smarty_modifier_date_formatter($string, $format=null, $default_date='', $formatter='auto')
{
    if ($format === null) {
        // Smarty 5 removed Smarty::$_DATE_FORMAT.
        $format = '%b %e, %Y';
    }

    // Smarty 5 no longer ships shared.make_timestamp.php / SMARTY_PLUGINS_DIR;
    // resolve the timestamp inline.
    $make_ts = static function ($value) {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            return $ts === false ? time() : $ts;
        }
        return time();
    };

    if ($string != '' && $string != '0000-00-00' && $string != '0000-00-00 00:00:00') {
        $timestamp = $make_ts($string);
    } elseif ($default_date != '') {
        $timestamp = $make_ts($default_date);
    } else {
        return;
    }

    if ($formatter=='strftime'||($formatter=='auto'&&strpos($format,'%')!==false)) {
        if (DIRECTORY_SEPARATOR == '\\') {
            $_win_from = ['%D', '%h', '%n', '%r', '%R', '%t', '%T'];
            $_win_to = ['%m/%d/%y', '%b', "\n", '%I:%M:%S %p', '%H:%M', "\t", '%H:%M:%S'];
            if (strpos($format, '%e') !== false) {
                $_win_from[] = '%e';
                $_win_to[] = sprintf('%\' 2d', date('j', $timestamp));
            }
            if (strpos($format, '%l') !== false) {
                $_win_from[] = '%l';
                $_win_to[] = sprintf('%\' 2d', date('h', $timestamp));
            }
            $format = str_replace($_win_from, $_win_to, $format);
        }

        return date($format, $timestamp);
    } else {
        return date($format, $timestamp);
    }
}
