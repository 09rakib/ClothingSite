<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Cart\CartRepository;
use App\Cart\CartService;
use App\Support\Auth;
use RuntimeException;

/**
 * Cart behaviour (PROJECT_RULES.md §8, Rule 6).
 *
 * CartService reads the current user from the session, so these tests drive
 * $_SESSION directly — the same superglobal the Auth class uses under CLI.
 */
final class CartTest extends DatabaseTestCase
{
    private CartService $cart;
    private CartRepository $repo;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_COOKIE  = [];

        $this->cart   = new CartService($this->db);
        $this->repo   = new CartRepository($this->db);
        $this->userId = $this->createUser();

        $this->loginAsCustomer($this->userId);
    }

    private function loginAsCustomer(int $userId): void
    {
        $_SESSION['user_id']   = $userId;
        $_SESSION['user_name'] = 'Test User';
        $_SESSION['user_role'] = Auth::ROLE_CUSTOMER;
    }

    private function logout(): void
    {
        $_SESSION = [];
    }

    /* =====================================================
     | Adding
     * ===================================================== */

    public function test_adding_a_product_creates_a_cart_line(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $this->cart->add($productId, 2);

        $summary = $this->cart->summary();
        $this->assertCount(1, $summary['items']);
        $this->assertSame(2, $summary['items'][0]['quantity']);
        $this->assertSame(2, $summary['count']);
    }

    /**
     * Adding the same product twice must increase the existing line, not
     * create a duplicate — the unique index would reject the second insert.
     */
    public function test_adding_the_same_product_twice_increments_quantity(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);

        $this->cart->add($productId, 2);
        $this->cart->add($productId, 3);

        $summary = $this->cart->summary();
        $this->assertCount(1, $summary['items'], 'Should be one line, not two.');
        $this->assertSame(5, $summary['items'][0]['quantity']);
    }

    public function test_cannot_add_more_than_available_stock(): void
    {
        $productId = $this->createProduct('Limited', '500.00', 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only 3 available');

        $this->cart->add($productId, 5);
    }

    /**
     * The stock check must consider what is already in the cart, otherwise two
     * separate adds could together exceed stock.
     */
    public function test_incremental_adds_cannot_exceed_stock_in_total(): void
    {
        $productId = $this->createProduct('Limited', '500.00', 3);

        $this->cart->add($productId, 2);

        try {
            $this->cart->add($productId, 2);
            $this->fail('Expected the second add to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already have 2', $e->getMessage());
        }

        $this->assertSame(2, $this->cart->summary()['items'][0]['quantity']);
    }

    public function test_cannot_add_an_out_of_stock_product(): void
    {
        $productId = $this->createProduct('Sold Out', '500.00', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of stock');

        $this->cart->add($productId);
    }

    public function test_cannot_add_an_archived_product(): void
    {
        $productId = $this->createProduct('Gone', '500.00', 10);
        $this->db->query("UPDATE products SET status='archived', deleted_at=NOW() WHERE id={$productId}");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer available');

        $this->cart->add($productId);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $productId = $this->createProduct();

        $this->expectException(RuntimeException::class);

        $this->cart->add($productId, 0);
    }

    /* =====================================================
     | Totals — never trust the browser (Rule 6)
     * ===================================================== */

    public function test_totals_are_computed_from_live_prices(): void
    {
        $a = $this->createProduct('A', '100.00', 10);
        $b = $this->createProduct('B', '250.50', 10);

        $this->cart->add($a, 2);   // 200.00
        $this->cart->add($b, 1);   // 250.50

        $summary = $this->cart->summary();

        $this->assertSame('450.50', $summary['subtotal']);
        $this->assertSame('450.50', $summary['total']);
        $this->assertSame(3, $summary['count']);
    }

    /**
     * The price stored when the item was added must NOT be what the customer
     * is charged; the live price wins and the change is disclosed.
     */
    public function test_price_change_uses_the_new_price_and_is_flagged(): void
    {
        $productId = $this->createProduct('Repriced', '100.00', 10);
        $this->cart->add($productId, 2);

        $this->db->query("UPDATE products SET price = 150.00 WHERE id = {$productId}");

        $summary = $this->cart->summary();
        $item    = $summary['items'][0];

        $this->assertSame('150.00', $item['unit_price'], 'Live price must win.');
        $this->assertSame('100.00', $item['price_at_add']);
        $this->assertTrue($item['price_changed'], 'The change must be disclosed to the customer.');
        $this->assertSame('300.00', $summary['subtotal']);
    }

    /* =====================================================
     | Updating / removing
     * ===================================================== */

    public function test_update_quantity(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);
        $this->cart->add($productId, 1);

        $itemId = $this->cart->summary()['items'][0]['id'];
        $this->cart->updateQuantity($itemId, 4);

        $this->assertSame(4, $this->cart->summary()['items'][0]['quantity']);
    }

    public function test_updating_to_zero_removes_the_line(): void
    {
        $productId = $this->createProduct('Shirt', '500.00', 10);
        $this->cart->add($productId, 2);

        $itemId = $this->cart->summary()['items'][0]['id'];
        $this->cart->updateQuantity($itemId, 0);

        $this->assertSame([], $this->cart->summary()['items']);
    }

    public function test_update_cannot_exceed_stock(): void
    {
        $productId = $this->createProduct('Limited', '500.00', 3);
        $this->cart->add($productId, 1);
        $itemId = $this->cart->summary()['items'][0]['id'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only 3 available');

        $this->cart->updateQuantity($itemId, 9);
    }

    public function test_remove_and_clear(): void
    {
        $a = $this->createProduct('A', '100.00', 10);
        $b = $this->createProduct('B', '100.00', 10);

        $this->cart->add($a);
        $this->cart->add($b);
        $this->assertCount(2, $this->cart->summary()['items']);

        $itemId = $this->cart->summary()['items'][0]['id'];
        $this->cart->remove($itemId);
        $this->assertCount(1, $this->cart->summary()['items']);

        $this->cart->clear();
        $this->assertSame([], $this->cart->summary()['items']);
        $this->assertTrue($this->cart->isEmpty());
    }

    /**
     * §19 "No IDOR": a customer must not be able to touch another cart's line
     * by changing the item_id in the form.
     */
    public function test_cannot_modify_another_customers_cart_item(): void
    {
        $productId = $this->createProduct('Shared', '100.00', 10);

        // Victim adds an item.
        $victimId = $this->createUser('victim@test.com');
        $this->loginAsCustomer($victimId);
        $this->cart->add($productId, 1);
        $victimItemId = $this->cart->summary()['items'][0]['id'];

        // Attacker logs in and targets the victim's line.
        $this->loginAsCustomer($this->userId);
        $this->cart->add($productId, 1);

        try {
            $this->cart->updateQuantity($victimItemId, 99);
            $this->fail('Expected the cross-cart update to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not in your cart', $e->getMessage());
        }

        try {
            $this->cart->remove($victimItemId);
            $this->fail('Expected the cross-cart remove to be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not in your cart', $e->getMessage());
        }

        // The victim's line is untouched.
        $this->loginAsCustomer($victimId);
        $this->assertSame(1, $this->cart->summary()['items'][0]['quantity']);
    }

    /* =====================================================
     | Availability changes after adding
     * ===================================================== */

    public function test_archived_product_moves_to_unavailable(): void
    {
        $productId = $this->createProduct('Doomed', '100.00', 10);
        $this->cart->add($productId, 1);

        $this->db->query("UPDATE products SET status='archived', deleted_at=NOW() WHERE id={$productId}");

        $summary = $this->cart->summary();

        $this->assertSame([], $summary['items'], 'Archived products must not be purchasable.');
        $this->assertCount(1, $summary['unavailable']);
        $this->assertTrue($summary['has_issues']);
    }

    public function test_stock_dropping_below_cart_quantity_is_flagged(): void
    {
        $productId = $this->createProduct('Dwindling', '100.00', 10);
        $this->cart->add($productId, 5);

        $this->db->query("UPDATE products SET stock = 2 WHERE id = {$productId}");

        $summary = $this->cart->summary();

        $this->assertTrue($summary['items'][0]['over_stock']);
        $this->assertTrue($summary['has_issues'], 'Checkout must be blocked until this is fixed.');
    }

    /* =====================================================
     | Guest cart + merge on login
     * ===================================================== */

    public function test_guest_can_build_a_cart(): void
    {
        $this->logout();
        $productId = $this->createProduct('Guest Item', '100.00', 10);

        $this->cart->add($productId, 2);

        $this->assertSame(2, $this->cart->summary()['count']);
        $this->assertArrayHasKey(CartService::GUEST_COOKIE, $_COOKIE);
    }

    public function test_guest_cart_is_adopted_when_the_customer_has_none(): void
    {
        $productId = $this->createProduct('Adopted', '100.00', 10);

        $this->logout();
        $this->cart->add($productId, 3);

        $newUser = $this->createUser('fresh@test.com');
        $this->cart->mergeGuestCartIntoUser($newUser);

        $this->loginAsCustomer($newUser);
        $summary = $this->cart->summary();

        $this->assertCount(1, $summary['items']);
        $this->assertSame(3, $summary['items'][0]['quantity']);
    }

    /**
     * Merging must add the two quantities together, not silently pick one.
     */
    public function test_merge_sums_quantities_for_the_same_product(): void
    {
        $productId = $this->createProduct('Merged', '100.00', 10);

        // Customer already has 2 in their account cart.
        $this->cart->add($productId, 2);

        // Then browses out logged-out and adds 3 more as a guest.
        $this->logout();
        $this->cart->add($productId, 3);

        $this->cart->mergeGuestCartIntoUser($this->userId);

        $this->loginAsCustomer($this->userId);
        $summary = $this->cart->summary();

        $this->assertCount(1, $summary['items']);
        $this->assertSame(5, $summary['items'][0]['quantity']);
    }

    /**
     * Two carts can together want more than exists; the merge must clamp.
     */
    public function test_merge_clamps_to_available_stock(): void
    {
        $productId = $this->createProduct('Scarce', '100.00', 4);

        $this->cart->add($productId, 3);

        $this->logout();
        $this->cart->add($productId, 3);

        $this->cart->mergeGuestCartIntoUser($this->userId);

        $this->loginAsCustomer($this->userId);
        $this->assertSame(4, $this->cart->summary()['items'][0]['quantity']);
    }

    public function test_guest_cart_is_discarded_after_merging(): void
    {
        $productId = $this->createProduct('Temp', '100.00', 10);

        $this->logout();
        $this->cart->add($productId, 1);
        $token = $_COOKIE[CartService::GUEST_COOKIE];

        $this->cart->mergeGuestCartIntoUser($this->userId);

        $this->assertNull($this->repo->findByToken($token), 'The guest cart row should be gone.');
        $this->assertArrayNotHasKey(CartService::GUEST_COOKIE, $_COOKIE);
    }

    public function test_merging_with_no_guest_cart_is_harmless(): void
    {
        $this->cart->mergeGuestCartIntoUser($this->userId);

        $this->assertSame(0, $this->cart->count());
    }

    /* =====================================================
     | Admins
     * ===================================================== */

    public function test_admins_have_no_storefront_cart(): void
    {
        $adminId = $this->createUser('admin@test.com', Auth::ROLE_ADMIN);
        $_SESSION['user_id']   = $adminId;
        $_SESSION['user_role'] = Auth::ROLE_ADMIN;

        $this->assertNull($this->cart->currentCartId(true));
        $this->assertSame(0, $this->cart->count());
    }
}
