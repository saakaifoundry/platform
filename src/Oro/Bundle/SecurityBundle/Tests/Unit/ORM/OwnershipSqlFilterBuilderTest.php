<?php

namespace Oro\Bundle\SecurityBundle\Tests\Unit\ORM;

use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Acl\Domain\ObjectIdAccessor;
use Oro\Bundle\SecurityBundle\ORM\OwnershipSqlFilterBuilder;
use Oro\Bundle\EntityBundle\Owner\Metadata\OwnershipMetadata;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\BusinessUnit;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\Organization;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\TestEntity;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\User;
use Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\OwnershipMetadataProviderStub;
use Oro\Bundle\SecurityBundle\Owner\OwnerTree;
use Oro\Bundle\SecurityBundle\Acl\Domain\OneShotIsGrantedObserver;

class OwnershipSqlFilterBuilderTest extends \PHPUnit_Framework_TestCase
{
    const BUSINESS_UNIT = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\BusinessUnit';
    const ORGANIZATION = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\Organization';
    const USER = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\User';
    const TEST_ENTITY = 'Oro\Bundle\SecurityBundle\Tests\Unit\Acl\Domain\Fixtures\Entity\TestEntity';

    /**
     * @var OwnershipSqlFilterBuilder
     */
    private $builder;

    /** @var OwnershipMetadataProviderStub */
    private $metadataProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $securityContext;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $aclVoter;

    /** @var OwnerTree */
    private $tree;

    protected function setUp()
    {
        $this->tree = new OwnerTree();

        $this->metadataProvider = new OwnershipMetadataProviderStub($this);
        $this->metadataProvider->setMetadata(
            $this->metadataProvider->getOrganizationClass(),
            new OwnershipMetadata()
        );
        $this->metadataProvider->setMetadata(
            $this->metadataProvider->getBusinessUnitClass(),
            new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner_id')
        );
        $this->metadataProvider->setMetadata(
            $this->metadataProvider->getUserClass(),
            new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner_id')
        );

        $this->securityContext = $this->getMock('Symfony\Component\Security\Core\SecurityContextInterface');
        $this->aclVoter = $this->getMockBuilder('Oro\Bundle\SecurityBundle\Acl\Voter\AclVoter')
            ->disableOriginalConstructor()
            ->getMock();

        $this->builder = new OwnershipSqlFilterBuilder(
            $this->securityContext,
            $this->aclVoter,
            new ObjectIdAccessor(),
            $this->metadataProvider,
            $this->tree
        );
    }

    private function buildTestTree()
    {
        /**
         * org1  org2  org3       org4
         *             |          |
         * bu1   bu2   bu3        bu4
         *       |     |          |
         *       |     +-bu31     |
         *       |     | |        |
         *       |     | +-user31 |
         *       |     |          |
         * user1 user2 user3      user4
         *                        |
         *                        +-bu3
         *                        +-bu4
         *                          |
         *                          +-bu41
         *                            |
         *                            +-bu411
         *                              |
         *                              +-user411
         */
        $this->tree->addBusinessUnit('bu1', null);
        $this->tree->addBusinessUnit('bu2', null);
        $this->tree->addBusinessUnit('bu3', 'org3');
        $this->tree->addBusinessUnit('bu31', 'org3');
        $this->tree->addBusinessUnit('bu4', 'org4');
        $this->tree->addBusinessUnit('bu41', 'org4');
        $this->tree->addBusinessUnit('bu411', 'org4');

        $this->tree->addBusinessUnitRelation('bu1', null);
        $this->tree->addBusinessUnitRelation('bu2', null);
        $this->tree->addBusinessUnitRelation('bu3', null);
        $this->tree->addBusinessUnitRelation('bu31', 'bu3');
        $this->tree->addBusinessUnitRelation('bu4', null);
        $this->tree->addBusinessUnitRelation('bu41', 'bu4');
        $this->tree->addBusinessUnitRelation('bu411', 'bu41');

        $this->tree->addUser('user1', null);
        $this->tree->addUser('user2', 'bu2');
        $this->tree->addUser('user3', 'bu3');
        $this->tree->addUser('user31', 'bu31');
        $this->tree->addUser('user4', 'bu4');
        $this->tree->addUser('user41', 'bu41');
        $this->tree->addUser('user411', 'bu411');

        $this->tree->addUserBusinessUnit('user4', 'bu3');
        $this->tree->addUserBusinessUnit('user4', 'bu4');
    }

    /**
     * @dataProvider buildFilterConstraintProvider
     */
    public function testBuildFilterConstraint(
        $userId,
        $isGranted,
        $accessLevel,
        $ownerType,
        $targetEntityClassName,
        $targetTableAlias,
        $expectedConstraint
    ) {
        $this->buildTestTree();

        if ($ownerType !== null) {
            $this->metadataProvider->setMetadata(
                self::TEST_ENTITY,
                new OwnershipMetadata($ownerType, 'owner', 'owner_id')
            );
        }

        /** @var OneShotIsGrantedObserver $aclObserver */
        $aclObserver = null;
        $this->aclVoter->expects($this->once())
            ->method('addOneShotIsGrantedObserver')
            ->will(
                $this->returnCallback(
                    function ($observer) use (&$aclObserver, &$accessLevel) {
                        $aclObserver = $observer;
                        /** @var OneShotIsGrantedObserver $aclObserver */
                        $aclObserver->setAccessLevel($accessLevel);
                    }
                )
            );

        $user = new User($userId);
        $token = $this->getMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');
        $token->expects($this->any())
            ->method('getUser')
            ->will($this->returnValue($user));
        $this->securityContext->expects($this->once())
            ->method('isGranted')
            ->with($this->equalTo('VIEW'), $this->equalTo('entity:' . $targetEntityClassName))
            ->will($this->returnValue($isGranted));
        $this->securityContext->expects($this->any())
            ->method('getToken')
            ->will($this->returnValue($token));

        $this->assertEquals(
            $expectedConstraint,
            $this->builder->buildFilterConstraint($targetEntityClassName, $targetTableAlias)
        );
    }

    public static function buildFilterConstraintProvider()
    {
        return array(
            array('', false, AccessLevel::UNDEFINED, null, self::TEST_ENTITY, '', '1 = 0'),
            array('', false, AccessLevel::UNDEFINED, null, self::TEST_ENTITY, 't', "'t' = ''"),
            array('', true, AccessLevel::UNDEFINED, null, '\stdClass', '', ''),
            array('user4', true, AccessLevel::SYSTEM_LEVEL, null, self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::SYSTEM_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::SYSTEM_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::SYSTEM_LEVEL, 'USER', self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::GLOBAL_LEVEL, null, self::TEST_ENTITY, '', ''),
            array(
                'user4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                'ORGANIZATION',
                self::TEST_ENTITY,
                '',
                'owner_id IN (org3,org4)'
            ),
            array(
                'user4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                'BUSINESS_UNIT',
                self::TEST_ENTITY,
                '',
                'owner_id IN (bu3,bu4,bu31,bu41,bu411)'
            ),
            array(
                'user4',
                true,
                AccessLevel::GLOBAL_LEVEL,
                'USER',
                self::TEST_ENTITY,
                '',
                'owner_id IN (user3,user31,user4,user41,user411)'
            ),
            array('user4', true, AccessLevel::DEEP_LEVEL, null, self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::DEEP_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, '', '1 = 0'),
            array(
                'user4',
                true,
                AccessLevel::DEEP_LEVEL,
                'BUSINESS_UNIT',
                self::TEST_ENTITY,
                '',
                'owner_id IN (bu3,bu4,bu31,bu41,bu411)'
            ),
            array(
                'user4',
                true,
                AccessLevel::DEEP_LEVEL,
                'USER',
                self::TEST_ENTITY,
                '',
                'owner_id IN (user3,user4,user31,user41,user411)'
            ),
            array('user4', true, AccessLevel::LOCAL_LEVEL, null, self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::LOCAL_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, '', '1 = 0'),
            array(
                'user4',
                true,
                AccessLevel::LOCAL_LEVEL,
                'BUSINESS_UNIT',
                self::TEST_ENTITY,
                '',
                'owner_id IN (bu3,bu4)'
            ),
            array(
                'user4',
                true,
                AccessLevel::LOCAL_LEVEL,
                'USER',
                self::TEST_ENTITY,
                '',
                'owner_id IN (user3,user4)'
            ),
            array('user4', true, AccessLevel::BASIC_LEVEL, null, self::TEST_ENTITY, '', ''),
            array('user4', true, AccessLevel::BASIC_LEVEL, 'ORGANIZATION', self::TEST_ENTITY, '', '1 = 0'),
            array('user4', true, AccessLevel::BASIC_LEVEL, 'BUSINESS_UNIT', self::TEST_ENTITY, '', '1 = 0'),
            array(
                'user4',
                true,
                AccessLevel::BASIC_LEVEL,
                'USER',
                self::TEST_ENTITY,
                '',
                'owner_id = user4'
            ),
        );
    }
}