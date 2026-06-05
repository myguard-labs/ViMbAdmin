<?php

/**
 * Domain/application service exception.
 *
 * Thrown by the framework-free service classes under library/ViMbAdmin/Service/
 * to signal a business-rule violation (e.g. "admin already assigned to this
 * domain"). Controllers catch it and translate the message into the appropriate
 * OSS_Message / redirect — the service itself stays free of any HTTP, view or
 * ZF1 concern.
 *
 * Part of Phase 1 of the ZF1 removal roadmap (docs/ZF1-REMOVAL.md): business
 * logic moves out of the controllers into plain, unit-testable services that
 * take the Doctrine entity manager plus scalars and return data or throw.
 *
 * @package ViMbAdmin
 * @subpackage Service
 */
class ViMbAdmin_Service_Exception extends \Exception
{
}
