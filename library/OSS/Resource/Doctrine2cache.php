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
 * @package    OSS_Resource
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <barry@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

/**
 * @category   OSS
 * @package    OSS_Resource
 * @copyright  Copyright (c) 2007 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 */
class OSS_Resource_Doctrine2cache extends Zend_Application_Resource_ResourceAbstract
{
    /**
     * Holds the Doctrine instance
     *
     * @var null|Doctrine\ORM\EntityManager
     */
    protected $_d2cache = null;

    /**
     * Initialisation function
     *
     * @return Doctrine\Common\Cache
     */
    public function init()
    {
        return $this->getDoctrine2cache();
    }


    /**
     * Get Doctrine2Cache
     *
     * @return Doctrine\Common\Cache
     */
    public function getDoctrine2cache()
    {
        if( $this->_d2cache === null )
        {
            // Get Doctrine configuration options from the application.ini file
            $config = $this->getOptions();

            // Autoloading is handled by Composer now; the legacy
            // Doctrine\ORM\Tools\Setup::registerAutoload* helpers were removed
            // in Doctrine ORM 2.20.

            $namespace = isset( $config['namespace'] ) ? (string) $config['namespace'] : '';

            // Doctrine ORM 2.20 / doctrine-cache 2.x dropped the concrete
            // Doctrine\Common\Cache\*Cache classes. We build a PSR-6 pool with
            // symfony/cache and wrap it in DoctrineProvider so that both the
            // ORM (setMetadataCacheImpl etc.) and the legacy ->fetch()/->save()
            // callers keep working unchanged.
            switch( $config['type'] )
            {
                case 'ApcCache':
                case 'ApcuCache':
                    $pool = new \Symfony\Component\Cache\Adapter\ApcuAdapter( $namespace );
                    break;

                case 'MemcacheCache':
                case 'MemcachedCache':
                    $dsns = array();
                    if( isset( $config['memcache']['servers'] ) )
                    {
                        foreach( $config['memcache']['servers'] as $server )
                        {
                            $host = isset( $server['host'] ) ? $server['host'] : '127.0.0.1';
                            $port = isset( $server['port'] ) ? $server['port'] : 11211;
                            $dsns[] = 'memcached://' . $host . ':' . $port;
                        }
                    }
                    if( !$dsns )
                        $dsns[] = 'memcached://127.0.0.1:11211';

                    $client = \Symfony\Component\Cache\Adapter\MemcachedAdapter::createConnection( $dsns );
                    $pool   = new \Symfony\Component\Cache\Adapter\MemcachedAdapter( $client, $namespace );
                    break;

                default:
                    // ArrayCache (per-request, in-memory) is the safe default.
                    $pool = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
            }

            $cache = \Doctrine\Common\Cache\Psr6\DoctrineProvider::wrap( $pool );

            // stick the cache in the registry
            Zend_Registry::set( 'd2cache', $cache );
            $this->setDoctrine2Cache( $cache );
        }

        return $this->_d2cache;
    }

    /**
     * Set the classes $_d2cache member
     *
     * @param Doctrine\Common\Cache $c The object to set
     * @return void
     */
    public function setDoctrine2Cache( $c )
    {
        $this->_d2cache = $c;
    }


}
