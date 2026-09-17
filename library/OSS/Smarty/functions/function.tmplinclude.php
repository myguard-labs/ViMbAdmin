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
 * @package    OSS_Smarty
 * @subpackage Functions
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

/**
 * @category   OSS
 * @package    OSS_Smarty
 * @subpackage Functions
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 */

/**
 * Function icludes template form skin, if file is not existing in skin folder
 * it displays default one.
 *
 * @category   OSS
 * @package    OSS_Smarty
 * @subpackage Functions
 *
 * @param array{file?: string, assign?: string, ...<string, mixed>} $params
 * @param \Smarty\Smarty|\Smarty\Template $smarty
 * @return string
 */
function smarty_function_tmplinclude($params, $smarty)
{
    if (!is_array($params)) {
        throw new \Smarty\CompilerException("tmplinclude parameters must be an array");
    }
    foreach ($params as $arg => $_value) {
        if (!is_string($arg)) {
            throw new \Smarty\CompilerException("tmplinclude parameter names must be strings");
        }
    }

    if (!array_key_exists('file', $params)) {
        throw new \Smarty\CompilerException("Missing 'file' attribute in tmplinclude tag");
    }
    if (!is_string($params['file'])) {
        throw new \Smarty\CompilerException("tmplinclude 'file' attribute must be a string");
    }
    if (array_key_exists('assign', $params) && !is_string($params['assign'])) {
        throw new \Smarty\CompilerException("tmplinclude 'assign' attribute must be a string");
    }

    // Smarty 5 passes a \Smarty\Template (not the engine) to function plugins;
    // templateExists()/fetch() live on the engine, so resolve it.
    if (!($smarty instanceof \Smarty\Smarty) && !($smarty instanceof \Smarty\Template)) {
        throw new \Smarty\CompilerException("tmplinclude requires a Smarty engine or template");
    }
    $engine = $smarty instanceof \Smarty\Template ? $smarty->getSmarty() : $smarty;
    $templateRoots = $engine->getTemplateDir();
    if (!is_array($templateRoots)) {
        $templateRoots = [ $templateRoots ];
    }
    $containedTemplate = static function (string $name) use ($templateRoots): bool {
        $candidate = null;
        foreach ($templateRoots as $root) {
            if (!is_string($root)) {
                continue;
            }
            $rootReal = realpath($root);
            $candidateReal = realpath($root . '/' . $name);
            if (is_string($rootReal) && is_string($candidateReal)) {
                $candidate = [ $rootReal, $candidateReal ];
                break;
            }
        }
        return $candidate !== null
            && ($candidate[1] === $candidate[0] || str_starts_with($candidate[1], $candidate[0] . '/'));
    };

    $resolveTemplateName = static function (mixed $value): string {
        if (!is_string($value)) {
            throw new \Smarty\CompilerException("tmplinclude template name must be a string");
        }
        if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\') || str_contains($value, ':') || $value[0] === '/') {
            throw new \Smarty\CompilerException("tmplinclude template name must be a safe relative path");
        }
        foreach (explode('/', $value) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \Smarty\CompilerException("tmplinclude template name must be a safe relative path");
            }
        }
        return $value;
    };

    $original_values = [];

    if (substr($params['file'], 0, 24) == '$_smarty_tpl->tpl_vars[\'') {
        $params['file'] = substr($params['file'], 24);
        $end = strpos($params['file'], '\'');
        $params['file'] = substr($params['file'], 0, $end === false ? 0 : $end);
        $params['file'] = $smarty->getTemplateVars($params['file']);
    } elseif (substr($params['file'], 0, 24) == '($_smarty_tpl->tpl_vars[') {
        $params['file'] = substr($params['file'], 24);
        $end = strpos($params['file'], ']');
        $params['file'] = substr($params['file'], 0, $end === false ? 0 : $end);
        $params['file'] = $smarty->getTemplateVars($params['file']);
    } elseif (substr($params['file'], 0, 23) == '$_smarty_tpl->tpl_vars[') {
        $params['file'] = substr($params['file'], 23);
        $end = strpos($params['file'], ']');
        $params['file'] = substr($params['file'], 0, $end === false ? 0 : $end);
        $params['file'] = $smarty->getTemplateVars($params['file']);
    } else {
        $params['file'] = str_replace([ '\'', '"' ], '', $params['file']);
    }

    $params['file'] = $resolveTemplateName($params['file']);

    if ($smarty->getTemplateVars('___SKIN')) {
        $skin = $smarty->getTemplateVars('___SKIN');
    } else {
        $skin = false;
    }
    if ($skin !== false) {
        if (!is_string($skin)) {
            throw new \Smarty\CompilerException("tmplinclude skin must be a string");
        }
        if (str_contains($skin, "\0") || str_contains($skin, '/') || str_contains($skin, '\\') || $skin === '.' || $skin === '..') {
            throw new \Smarty\CompilerException("tmplinclude skin must be a safe directory name");
        }
    }

    if ($skin && $engine->templateExists('_skins/' . $skin . '/' . $params['file'])) {
        $skinFile = '_skins/' . $skin . '/' . $params['file'];
        if (!$containedTemplate($skinFile)) {
            throw new \Smarty\CompilerException("Template file is outside configured template roots - [{$skinFile}]");
        }
        $params['file'] = $skinFile;
    } elseif (!$engine->templateExists($params['file'])) {
        throw new \Smarty\CompilerException("Template file does not exist - [{$params['file']}]");
    } elseif (!$containedTemplate($params['file'])) {
        throw new \Smarty\CompilerException("Template file is outside configured template roots - [{$params['file']}]");
    }

    foreach ($params as $arg => $value) {
        if (is_bool($value)) {
            $params[ $arg ] = $value ? 'true' : 'false';
        }

        if (!in_array($arg, [ 'file', 'assign' ])) {
            $original_values[ $arg ] = $value;
            $smarty->assign($arg, $value);
        }
    }

    $output = '';
    if (isset($params['assign'])) {
        $smarty->assign($params['assign'], $engine->fetch($params['file']));
    } else {
        $output = $engine->fetch($params['file']);
    }

    foreach ($original_values as $arg => $value) {
        $smarty->assign($arg, $value);
    }

    return $output;
}
