<?php

namespace Jasny\Auth\Tests\Authz;

use Jasny\Auth\UserInterface as User;
use Jasny\Auth\Authz\Groups;
use Jasny\PHPUnit\ExpectWarningTrait;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Jasny\Auth\Authz\Groups
 * @covers \Jasny\Auth\Authz\StateTrait
 */
class GroupsTest extends TestCase
{
    use ExpectWarningTrait;
    
    /**
     * @var Groups&MockObject
     */
    protected $authz;
    
    public function setUp(): void
    {
        $this->authz = new Groups([
            'user' => [],
            'client' => ['user'],
            'mod' => ['user'],
            'dev' => ['user'],
            'admin' => ['mod', 'dev']
        ]);
    }
    
    public function testAvailableGetRoles()
    {
        $this->assertEquals(
            ['user', 'client', 'mod', 'dev', 'admin'],
            $this->authz->getAvailableRoles()
        );
    }

    public function roleProvider()
    {
        return [
            'user' => [
                'user',
                ['user' => true, 'client' => false, 'mod' => false, 'dev' => false, 'admin' => false],
            ],
            'client' => [
                'client',
                ['user' => true, 'client' => true, 'mod' => false, 'dev' => false, 'admin' => false],
            ],
            'admin' => [
                'admin',
                ['user' => true, 'client' => false, 'mod' => true, 'dev' => true, 'admin' => true],
            ],
            'mod+client' => [
                ['mod', 'client'],
                ['user' => true, 'client' => true, 'mod' => true, 'dev' => false, 'admin' => false],
            ],
            'user+foo' => [
                ['user', 'foo'],
                ['user' => true, 'client' => false, 'mod' => false, 'dev' => false, 'admin' => false],
            ],
        ];
    }

    public function testIsWithoutUser()
    {
        $this->assertFalse($this->authz->is('user'));
    }

    /**
     * @dataProvider roleProvider
     * 
     * @param string|array $role
     * @param array        $expect
     */
    public function testIsWithUser($role, array $expect)
    {
        $user = $this->createMock(User::class);
        $user->expects($this->any())->method('getAuthRole')->willReturn($role);

        $this->authz = $this->authz->forUser($user);

        $this->assertEquals($expect['user'], $this->authz->is('user'));
        $this->assertEquals($expect['client'], $this->authz->is('client'));
        $this->assertEquals($expect['mod'], $this->authz->is('mod'));
        $this->assertEquals($expect['dev'], $this->authz->is('dev'));
        $this->assertEquals($expect['admin'], $this->authz->is('admin'));
    }


    public function testIsWithUnknownRole()
    {
        $this->expectWarningMessage("Unknown authz role 'foo'");
        $this->assertFalse($this->authz->is('foo'));
    }

    public function testIsWithUnknownUserRole()
    {
        $user = $this->createConfiguredMock(User::class, ['getAuthRole' => 'foo', 'getAuthId' => '42']);
        $this->authz = $this->authz->forUser($user);

        $this->assertFalse($this->authz->is('user'));
    }


    public function testRecalc()
    {
        $user = $this->createMock(User::class);
        $user->expects($this->exactly(2))->method('getAuthRole')
            ->willReturnOnConsecutiveCalls('client', 'admin');

        $this->authz = $this->authz->forUser($user);

        $this->assertTrue($this->authz->is('client'));
        $this->assertFalse($this->authz->is('dev'));

        // $user->role = 'admin';
        $updatedAuthz = $this->authz->recalc();

        $this->assertFalse($this->authz->is('dev'));
        $this->assertTrue($updatedAuthz->is('dev')); // admin supersedes dev
    }

    public function testRecalcWithoutAnyChange()
    {
        $user = $this->createMock(User::class);
        $user->expects($this->exactly(2))->method('getAuthRole')
            ->willReturnOnConsecutiveCalls('client', 'client');

        $this->authz = $this->authz->forUser($user);
        $updatedAuthz = $this->authz->recalc();

        $this->assertSame($this->authz, $updatedAuthz);
    }

    public function testRecalcWithoutUser()
    {
        $this->authz = $this->authz->forUser(null);
        $updatedAuthz = $this->authz->recalc();

        $this->assertSame($this->authz, $updatedAuthz);
    }


    public function crossReferenceProvider()
    {
        return [
            'client' => ['client'],
            'customer' => ['customer'],
            'king' => ['king'],
        ];
    }

    /**
     * @dataProvider crossReferenceProvider
     */
    public function testCrossReference(string $role)
    {
        $this->authz = new Groups([
            'user' => [],
            'client' => ['user', 'customer'],
            'customer' => ['client', 'king'],
            'king' => ['customer'],
        ]);

        $user = $this->createConfiguredMock(User::class, ['getAuthRole' => $role]);

        $this->assertTrue($this->authz->forUser($user)->is('user'));
        $this->assertTrue($this->authz->forUser($user)->is('client'));
        $this->assertTrue($this->authz->forUser($user)->is('customer'));
        $this->assertTrue($this->authz->forUser($user)->is('king'));
    }
}
