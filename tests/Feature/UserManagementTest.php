<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Users\UserRepository;
use RuntimeException;

/**
 * Admin user management (PROJECT_RULES.md §16).
 */
final class UserManagementTest extends DatabaseTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new UserRepository($this->db);
    }

    public function test_new_accounts_start_active(): void
    {
        $userId = $this->createUser();

        $this->assertTrue($this->repo->isActive($userId));
    }

    public function test_suspend_blocks_the_account(): void
    {
        // Two admins so suspending the customer doesn't touch the admin count.
        $this->createUser('admin@test.com', 'admin');
        $userId = $this->createUser();

        $this->repo->suspend($userId);

        $this->assertFalse($this->repo->isActive($userId));
    }

    public function test_reactivate_restores_access(): void
    {
        $this->createUser('admin@test.com', 'admin');
        $userId = $this->createUser();

        $this->repo->suspend($userId);
        $this->repo->reactivate($userId);

        $this->assertTrue($this->repo->isActive($userId));
    }

    /**
     * The core safety rule: the store must always have at least one admin
     * who can log in.
     */
    public function test_cannot_suspend_the_only_active_admin(): void
    {
        $adminId = $this->createUser('admin@test.com', 'admin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only active admin');

        $this->repo->suspend($adminId);
    }

    public function test_can_suspend_an_admin_when_another_active_admin_exists(): void
    {
        $adminA = $this->createUser('a@test.com', 'admin');
        $this->createUser('b@test.com', 'admin');

        $this->repo->suspend($adminA);

        $this->assertFalse($this->repo->isActive($adminA));
    }

    public function test_promote_and_demote(): void
    {
        $this->createUser('admin@test.com', 'admin');
        $userId = $this->createUser();

        $this->repo->setRole($userId, 'admin');
        $this->assertSame('admin', $this->repo->find($userId)['role']);

        $this->repo->setRole($userId, 'user');
        $this->assertSame('user', $this->repo->find($userId)['role']);
    }

    public function test_cannot_demote_the_only_active_admin(): void
    {
        $adminId = $this->createUser('admin@test.com', 'admin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only active admin');

        $this->repo->setRole($adminId, 'user');
    }

    /**
     * A suspended admin does not count toward "active admins", so demoting
     * the last *active* one is still blocked even if a suspended admin
     * technically has the admin role in the database.
     */
    public function test_suspended_admins_do_not_count_toward_the_minimum(): void
    {
        $activeAdmin    = $this->createUser('active@test.com', 'admin');
        $suspendedAdmin = $this->createUser('suspended@test.com', 'admin');

        $this->db->query("UPDATE users SET status = 'suspended' WHERE id = {$suspendedAdmin}");

        $this->expectException(RuntimeException::class);

        $this->repo->setRole($activeAdmin, 'user');
    }

    public function test_search_matches_name_and_email(): void
    {
        $this->createUser('findme@test.com');
        $this->createUser('other@test.com');

        $result = $this->repo->paginate('findme');

        $this->assertSame(1, $result['total']);
    }

    public function test_paginate_reports_order_count(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        (new \App\Orders\OrderService($this->db))->placeOrderFromCart(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $addressId
        );

        $result = $this->repo->paginate();
        $row    = current(array_filter($result['items'], static fn(array $u): bool => (int) $u['id'] === $userId));

        $this->assertSame(1, (int) $row['order_count']);
    }
}
