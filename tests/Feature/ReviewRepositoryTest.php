<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Orders\OrderService;
use App\Orders\OrderStatus;
use App\Reviews\ReviewRepository;

/**
 * Product reviews (PROJECT_RULES.md §14).
 *
 * The central rule under test: only a customer with an actual delivered
 * purchase of the product may review it — see ReviewRepository's docblock
 * for why eligibility is strict rather than open.
 */
final class ReviewRepositoryTest extends DatabaseTestCase
{
    private ReviewRepository $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reviews = new ReviewRepository($this->db);
    }

    /**
     * Places an order for the product and walks it all the way to Delivered,
     * making the buyer eligible to review it.
     */
    private function deliverProductTo(int $userId, int $productId): void
    {
        $addressId = $this->createAddress($userId);
        $admin     = $this->createUser('admin' . uniqid('', true) . '@test.com', 'admin');
        $order     = new OrderService($this->db);

        $result = $order->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $order->updateStatus((int) $result['order_id'], OrderStatus::CONFIRMED, $admin);
        $order->updateStatus((int) $result['order_id'], OrderStatus::PROCESSING, $admin);
        $order->updateStatus((int) $result['order_id'], OrderStatus::SHIPPED, $admin);
        $order->updateStatus((int) $result['order_id'], OrderStatus::DELIVERED, $admin);
    }

    public function test_customer_without_a_purchase_is_not_eligible(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->assertFalse($this->reviews->isEligible($userId, $productId));
    }

    /**
     * An order that has not yet been delivered must not grant eligibility —
     * a customer should not be able to review something before receiving it.
     */
    public function test_pending_order_does_not_grant_eligibility(): void
    {
        $userId    = $this->createUser();
        $addressId = $this->createAddress($userId);
        $productId = $this->createProduct();

        (new OrderService($this->db))->placeOrderFromCart($userId, [['product_id' => $productId, 'quantity' => 1]], $addressId);

        $this->assertFalse($this->reviews->isEligible($userId, $productId));
    }

    public function test_delivered_order_grants_eligibility(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();

        $this->deliverProductTo($userId, $productId);

        $this->assertTrue($this->reviews->isEligible($userId, $productId));
    }

    public function test_eligible_customer_can_post_a_verified_review(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();
        $this->deliverProductTo($userId, $productId);

        $id = $this->reviews->upsert($productId, $userId, 5, 'Great shirt', 'Fits perfectly and great fabric.');

        $review = $this->reviews->find($id);
        $this->assertSame(5, (int) $review['rating']);
        $this->assertSame(1, (int) $review['verified_purchase']);
        $this->assertSame('visible', $review['status']);
    }

    /**
     * §14 "unless business rules allow updates" — they do here: a second
     * submission by the same customer for the same product updates the
     * existing review instead of creating a duplicate.
     */
    public function test_a_second_submission_updates_the_existing_review(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();
        $this->deliverProductTo($userId, $productId);

        $first  = $this->reviews->upsert($productId, $userId, 3, 'OK', 'It was fine.');
        $second = $this->reviews->upsert($productId, $userId, 5, 'Actually great', 'Grew on me!');

        $this->assertSame($first, $second, 'Must update the same row, not create a new one.');
        $this->assertSame(1, $this->countRows('reviews'));

        $review = $this->reviews->find($first);
        $this->assertSame(5, (int) $review['rating']);
        $this->assertSame('Actually great', $review['title']);
    }

    public function test_summary_averages_only_visible_reviews(): void
    {
        $a = $this->createUser('a@test.com');
        $b = $this->createUser('b@test.com');
        $c = $this->createUser('c@test.com');
        $productId = $this->createProduct();

        $this->deliverProductTo($a, $productId);
        $this->deliverProductTo($b, $productId);
        $this->deliverProductTo($c, $productId);

        $this->reviews->upsert($productId, $a, 5, null, 'Excellent all around product.');
        $this->reviews->upsert($productId, $b, 3, null, 'Average, nothing special here.');
        $hiddenId = $this->reviews->upsert($productId, $c, 1, null, 'Terrible spam review here.');
        $this->reviews->setStatus($hiddenId, 'hidden');

        $summary = $this->reviews->summaryForProduct($productId);

        $this->assertSame(2, $summary['count'], 'Hidden review must not count.');
        $this->assertSame(4.0, $summary['average'], '(5+3)/2 = 4.0');
    }

    public function test_summary_for_a_product_with_no_reviews_is_a_defined_zero(): void
    {
        $productId = $this->createProduct();

        $summary = $this->reviews->summaryForProduct($productId);

        $this->assertSame(0, $summary['count']);
        $this->assertSame(0.0, $summary['average']);
    }

    public function test_hidden_reviews_do_not_appear_on_the_product_page(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();
        $this->deliverProductTo($userId, $productId);

        $id = $this->reviews->upsert($productId, $userId, 4, null, 'Pretty good purchase overall.');
        $this->reviews->setStatus($id, 'hidden');

        $this->assertSame([], $this->reviews->forProduct($productId));
    }

    public function test_admin_can_restore_a_hidden_review(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();
        $this->deliverProductTo($userId, $productId);

        $id = $this->reviews->upsert($productId, $userId, 4, null, 'Pretty good purchase overall.');
        $this->reviews->setStatus($id, 'hidden');
        $this->reviews->setStatus($id, 'visible');

        $this->assertCount(1, $this->reviews->forProduct($productId));
    }

    public function test_admin_can_permanently_delete_a_review(): void
    {
        $userId    = $this->createUser();
        $productId = $this->createProduct();
        $this->deliverProductTo($userId, $productId);

        $id = $this->reviews->upsert($productId, $userId, 4, null, 'Pretty good purchase overall.');
        $this->reviews->delete($id);

        $this->assertNull($this->reviews->find($id));
    }

    public function test_reviews_are_isolated_per_product(): void
    {
        $userId = $this->createUser();
        $a      = $this->createProduct('Product A');
        $b      = $this->createProduct('Product B');
        $this->deliverProductTo($userId, $a);
        $this->deliverProductTo($userId, $b);

        $this->reviews->upsert($a, $userId, 5, null, 'Loved this particular product a lot.');

        $this->assertCount(1, $this->reviews->forProduct($a));
        $this->assertCount(0, $this->reviews->forProduct($b));
    }
}
