<?php

/*
 * This file is part of discord-bot.
 *
 * (c) Aaron Scherer <aequasi@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE
 */

namespace LFGamers\Discord\Helper;

use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use LFGamers\Discord\Model\Config;
use LFGamers\Discord\Model\Permission;
use LFGamers\Discord\Model\Role as DbRole;
use Psr\Log\LoggerInterface;

/**
 * @author Aaron Scherer <aequasi@gmail.com>
 *
 * AclHelper Class
 */
class AclHelper
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $cache = [];

    /**
     * ConfigHelper constructor.
     *
     * @param EntityManager   $entityManager
     * @param LoggerInterface $logger
     */
    public function __construct(EntityManager $entityManager, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->logger        = $logger;
    }

    /**
     * @param Member      $member
     * @param Role|string $role
     *
     * @return bool
     */
    public function userHasRole(Member $member, $role) : bool
    {
        return RoleHelper::userHasRole($member, $role);
    }

    /**
     * @param Member $member
     * @param string $permission
     * @param bool   $fresh
     *
     * @return bool
     */
    public function isAllowed(Member $member, string $permission, bool $fresh = false) : bool
    {
        $key = $member->id.'-'.$permission;
        if (isset($this->cache[$key]) && !$fresh) {
            return $this->cache[$key];
        }

        $hasPerms = false;
        foreach (RoleHelper::getUserRoles($member) as $role) {
            /** @var Role $role */
            $allowed = $this->isRoleAllowed($role, $permission);

            if ($allowed === 1) {
                $hasPerms = true;
            } elseif ($allowed === -1) {
                return false;
            }
        }

        return $this->cache[$key] = $hasPerms;
    }

    /**
     * @param Role   $role
     * @param string $permission
     *
     * @return int
     */
    public function isRoleAllowed(Role $role, string $permission) : int
    {
        /** @var DbRole $dbRole */
        $dbRole = $this->entityManager->getRepository(DbRole::class)->findOneByIdentifier($role->id);
        if (empty($dbRole)) {
            return 0;
        }

        foreach ($dbRole->getPermissions() as $perm) {
            $isWildcard = strpos($perm->getName(), '*') !== false && $this->isWildcardMatch($perm, $permission);
            if ($isWildcard || $perm->getName() === $permission) {
                return $perm->isAllowed() ? 1 : -1;
            }
        }

        return 0;
    }

    /**
     * @param Permission $perm
     * @param string     $permission
     *
     * @return bool
     */
    private function isWildcardMatch(Permission $perm, string $permission) : bool
    {
        $nameArray       = explode('.', $perm->getName());
        $permissionArray = explode('.', $permission);
        foreach ($nameArray as $x) {
            foreach ($permissionArray as $y) {
                if ($x === '*') {
                    return true;
                }

                if ($x !== $y) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param Role   $role
     * @param string $permission
     * @param bool   $allowed
     */
    public function grantPermission(Role $role, string $permission, $allowed = true)
    {
        $dbRole = $this->entityManager->getRepository(DbRole::class)->findOneByIdentifier($role->id);
        if (empty($dbRole)) {
            $dbRole = new DbRole();
            $dbRole->setIdentifier($role->id);
            $this->entityManager->persist($dbRole);
        }

        $set = false;
        foreach ($dbRole->getPermissions() as $perm) {
            if ($perm->getName() === $permission || $perm->getName() === '*') {
                $perm->setAllowed($allowed);
                $set = true;
                break;
            }
        }

        if (!$set) {
            $perm = new Permission();
            $perm->setName($permission);
            $perm->setAllowed($allowed);
            $perm->setRole($dbRole);
            $this->entityManager->persist($perm);

            $dbRole->addPermission($perm);
        }

        $this->entityManager->flush($dbRole);
        $this->cache = [];
    }

    /**
     * @param Guild $server
     */
    public function wipeServerPermissions(Guild $server)
    {
        foreach ($server->roles->all() as $role) {
            /** @var \LFGamers\Discord\Model\Role $dbRole */
            $dbRole = $this->entityManager->getRepository(DbRole::class)->findOneByIdentifier($role->id);
            if (empty($dbRole)) {
                continue;
            }

            foreach ($dbRole->getPermissions() as $permission) {
                $this->entityManager->remove($permission);
            }
            $this->entityManager->remove($dbRole);
        }

        $this->entityManager->flush();
    }
}
