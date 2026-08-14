<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Wishlist\WishlistRepository;

/**
 * Customer wishlist (PROJECT_RULES.md §15).
 */
final class WishlistTest extends DatabaseTestCase
{
    private WishlistRepository $wishlist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wishlist = new WishlistRepository($this->db);
    }

    public function test_add_and_contains(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->assertFalse($this->wishlist->contains($userId, $productId));

        $this->wishlist->add($userId, $productId);

        $this->assertTrue($this->wishlist->contains($userId, $productId));
    }

    /**
     * The unique index guarantee: adding the same product twice must not
     * error or duplicate.
     */
    public function test_adding_twice_is_harmless(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->wishlist->add($userId, $productId);
        $this->wishlist->add($userId, $productId);

        $this->assertSame(1, $this->wishlist->count($userId));
    }

    public function test_remove(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->wishlist->add($userId, $productId);
        $this->wishlist->remove($userId, $productId);

        $this->assertFalse($this->wishlist->contains($userId, $productId));
    }

    public function test_removing_something_never_added_is_harmless(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->wishlist->remove($userId, $productId);

        $this->assertSame(0, $this->wishlist->count($userId));
    }

    public function test_wishlist_is_scoped_per_customer(): void
    {
        $alice     = $this->createUser('alice@test.com');
        $bob       = $this->createUser('bob@test.com');
        $productId = $this->createProduct();

        $this->wishlist->add($alice, $productId);

        $this->assertTrue($this->wishlist->contains($alice, $productId));
        $this->assertFalse($this->wishlist->contains($bob, $productId), "Bob's wishlist must be independent of Alice's.");
    }

    public function test_archived_products_are_excluded_from_the_listing(): void
    {
        $userId    = $this->createUser();
        $visible   = $this->createProduct('Visible');
        $archived  = $this->createProduct('Archived');

        $this->wishlist->add($userId, $visible);
        $this->wishlist->add($userId, $archived);
        $this->db->query("UPDATE products SET status='archived', deleted_at=NOW() WHERE id={$archived}");

        $items = $this->wishlist->forUser($userId);

        $this->assertCount(1, $items, 'Archived products must not appear in the wishlist listing.');
        $this->assertSame('Visible', $items[0]['name']);
    }

    public function test_product_ids_for_user_returns_a_flat_list(): void
    {
        $userId = $this->createUser();
        $a      = $this->createProduct('A');
        $b      = $this->createProduct('B');

        $this->wishlist->add($userId, $a);
        $this->wishlist->add($userId, $b);

        $ids = $this->wishlist->productIdsForUser($userId);

        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);
        $this->assertCount(2, $ids);
    }
}
