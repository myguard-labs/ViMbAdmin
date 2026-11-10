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
 * @param int|string|null $string input date or Unix timestamp
 * @param string|null $format  strftime format for output
 * @param int|string|null $default_date default date if $string is empty
 * @param string $formatter    either 'strftime' or 'auto'
 * @return string|null
 * @uses smarty_make_timestamp()
 * @see http://www.smarty.net/forums/viewtopic.php?t=10632
 */
function smarty_modifier_date_formatter($string, $format = null, $default_date = '', $formatter = 'auto')
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
        return null;
    }

    if ($formatter == 'strftime' || ($formatter == 'auto' && strpos($format, '%') !== false)) {
        // Use Smarty's maintained strftime adapter, including locale, Windows
        // compatibility and its qualified PHP 8.1+ deprecation handling.
        static $legacyFormatter = null;
        $legacyFormatter ??= (new \Smarty\Smarty())->getModifierCallback('date_format');
        if (!is_callable($legacyFormatter)) {
            throw new \LogicException('Smarty date_format adapter is unavailable');
        }
        // A date object preserves our already-resolved epoch, including zero,
        // without triggering the adapter's empty-input or MySQL-date parsing.
        $formatted = $legacyFormatter(new \DateTimeImmutable('@' . $timestamp), $format, '', 'strftime');

        if ($formatted !== null && $formatted !== false && !is_string($formatted)) {
            throw new \LogicException('Smarty date_format adapter returned an invalid result');
        }

        return $formatted === false ? '' : $formatted;
    } else {
        return date($format, $timestamp);
    }
}
