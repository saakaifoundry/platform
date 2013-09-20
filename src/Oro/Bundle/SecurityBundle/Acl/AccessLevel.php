<?php

namespace Oro\Bundle\SecurityBundle\Acl;

/**
 * This class defines all available access levels in the BAP security system.
 */
final class AccessLevel
{
    /**
     * Names of all access levels
     *
     * @var string[]
     */
    public static $allAccessLevelNames = array('BASIC', 'LOCAL', 'DEEP', 'GLOBAL', 'SYSTEM');

    /**
     * Undefined access level.
     */
    const UNDEFINED = 0;

    /**
     * This access level gives a user access to own records and objects that are shared with the user.
     */
    const BASIC_LEVEL = 1;

    /**
     * This access level gives a user access to records in all business units are assigned to the user.
     */
    const LOCAL_LEVEL = 2;

    /**
     * This access level gives a user access to records in all business units are assigned to the user
     * and all business units subordinate to business units are assigned to the user.
     */
    const DEEP_LEVEL = 3;

    /**
     * This access level gives a user access to all records within the organization,
     * regardless of the business unit hierarchical level to which the domain object belongs
     * or the user is assigned to.
     */
    const GLOBAL_LEVEL = 4;

    /**
     * This access level gives a user access to all records within the system.
     */
    const SYSTEM_LEVEL = 5;

    /**
     * Gets constant value by its name
     *
     * @param  string $name
     * @return mixed
     */
    public static function getConst($name)
    {
        return constant('self::' . $name);
    }
}