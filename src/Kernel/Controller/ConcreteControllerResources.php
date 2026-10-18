<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Controller;

/**
 * Concrete resource boundary shared only by controllers that require Doctrine's
 * concrete entity manager and the authenticated Admin entity.
 */
trait ConcreteControllerResources
{
    protected function em(): \Doctrine\ORM\EntityManager
    {
        $em = parent::em();
        if (!$em instanceof \Doctrine\ORM\EntityManager) {
            throw new \LogicException('Doctrine entity manager resource has an invalid type');
        }

        return $em;
    }

    protected function admin(): ?\Entities\Admin
    {
        $admin = parent::admin();
        if ($admin !== null && !$admin instanceof \Entities\Admin) {
            throw new \LogicException('Authenticated admin has an invalid type');
        }

        return $admin;
    }
}
