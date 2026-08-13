<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalog\CategoryRepository;
use App\Catalog\ProductImageRepository;
use App\Catalog\ProductRepository;
use App\Support\Slugger;

/**
 * Phase 1 catalog foundation: slugs, categories, image galleries and the
 * per-product low-stock override.
 */
final class CatalogFoundationTest extends DatabaseTestCase
{
    private ProductRepository $products;
    private CategoryRepository $categories;
    private ProductImageRepository $images;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products   = new ProductRepository();
        $this->categories = new CategoryRepository();
        $this->images     = new ProductImageRepository();
    }

    /* ---------------- Slugs ---------------- */

    public function test_creating_a_product_generates_a_slug(): void
    {
        $id      = $this->products->create('Denim Pant', 'desc', '100.00', 5, 'a.jpg', null);
        $product = $this->products->find($id);

        $this->assertSame('denim-pant', $product['slug']);
    }

    /**
     * Two products may legitimately share a name; their URLs may not collide.
     */
    public function test_duplicate_names_get_distinct_slugs(): void
    {
        $first  = $this->products->create('Denim Pant', 'desc', '100.00', 5, 'a.jpg', null);
        $second = $this->products->create('Denim Pant', 'desc', '100.00', 5, 'b.jpg', null);

        $this->assertSame('denim-pant', $this->products->find($first)['slug']);
        $this->assertSame('denim-pant-2', $this->products->find($second)['slug']);
    }

    public function test_product_is_findable_by_slug(): void
    {
        $this->products->create('Cotton Polo Shirt', 'desc', '650.00', 10, 'a.jpg', null);

        $found = $this->products->findActiveBySlug('cotton-polo-shirt');

        $this->assertNotNull($found);
        $this->assertSame('Cotton Polo Shirt', $found['name']);
    }

    public function test_archived_product_is_not_findable_by_slug(): void
    {
        $id = $this->products->create('Gone Shirt', 'desc', '100.00', 5, 'a.jpg', null);
        $this->products->archive($id);

        $this->assertNull($this->products->findActiveBySlug('gone-shirt'));
    }

    /**
     * Editing price or stock must not change the URL, or every existing link
     * and search-engine result would break.
     */
    public function test_slug_is_stable_when_the_name_does_not_change(): void
    {
        $id           = $this->products->create('Stable Shirt', 'desc', '100.00', 5, 'a.jpg', null);
        $originalSlug = $this->products->find($id)['slug'];

        $this->products->update($id, 'Stable Shirt', 'new description', '200.00', 9, 'a.jpg', null);

        $this->assertSame($originalSlug, $this->products->find($id)['slug']);
    }

    public function test_renaming_a_product_updates_its_slug(): void
    {
        $id = $this->products->create('Old Name', 'desc', '100.00', 5, 'a.jpg', null);

        $this->products->update($id, 'Brand New Name', 'desc', '100.00', 5, 'a.jpg', null);

        $this->assertSame('brand-new-name', $this->products->find($id)['slug']);
    }

    public function test_slugger_unique_ignores_the_row_being_edited(): void
    {
        $id = $this->products->create('Self Slug', 'desc', '100.00', 5, 'a.jpg', null);

        $slug = Slugger::unique($this->db, 'Self Slug', 'products', 'slug', $id);

        $this->assertSame('self-slug', $slug, 'A product must be allowed to keep its own slug.');
    }

    /* ---------------- Categories ---------------- */

    public function test_category_crud_with_slug(): void
    {
        $id = $this->categories->create('Winter Wear', 'Warm clothing');

        $category = $this->categories->find($id);
        $this->assertSame('Winter Wear', $category['name']);
        $this->assertSame('winter-wear', $category['slug']);

        $this->categories->update($id, 'Summer Wear', 'Light clothing');
        $this->assertSame('summer-wear', $this->categories->find($id)['slug']);

        $this->categories->delete($id);
        $this->assertNull($this->categories->find($id));
    }

    /**
     * §6.1 in spirit: deleting a grouping must not destroy the things grouped.
     */
    public function test_deleting_a_category_keeps_its_products_on_sale(): void
    {
        $categoryId = $this->categories->create('Doomed');
        $productId  = $this->products->create('Survivor Shirt', 'desc', '100.00', 5, 'a.jpg', $categoryId);

        $this->categories->delete($categoryId);

        $product = $this->products->find($productId);
        $this->assertNotNull($product, 'Product must survive its category being deleted.');
        $this->assertNull($product['category_id'], 'Product should become uncategorised.');
        $this->assertNotNull($this->products->findActive($productId), 'Product must remain purchasable.');
    }

    public function test_name_taken_detects_duplicates_and_ignores_self(): void
    {
        $id = $this->categories->create('Shirts');

        $this->assertTrue($this->categories->nameTaken('Shirts'));
        $this->assertFalse($this->categories->nameTaken('Shirts', $id));
        $this->assertFalse($this->categories->nameTaken('Pants'));
    }

    public function test_product_count_includes_archived_products(): void
    {
        $categoryId = $this->categories->create('Counted');
        $a = $this->products->create('A', 'desc', '100.00', 5, 'a.jpg', $categoryId);
        $this->products->create('B', 'desc', '100.00', 5, 'b.jpg', $categoryId);

        $this->products->archive($a);

        $this->assertSame(2, $this->categories->productCount($categoryId));

        $adminRows = $this->categories->allForAdmin();
        $row = array_values(array_filter($adminRows, static fn($r) => $r['id'] === $categoryId))[0];

        $this->assertSame(2, $row['product_count']);
        $this->assertSame(1, $row['active_count']);
    }

    /* ---------------- Image gallery ---------------- */

    public function test_first_image_added_becomes_primary_automatically(): void
    {
        $productId = $this->products->create('Gallery Shirt', 'desc', '100.00', 5, 'first.jpg', null);

        $this->images->add($productId, 'first.jpg');

        $gallery = $this->images->forProduct($productId);
        $this->assertCount(1, $gallery);
        $this->assertTrue($gallery[0]['is_primary']);
    }

    public function test_setting_a_new_primary_demotes_the_previous_one(): void
    {
        $productId = $this->products->create('Gallery Shirt', 'desc', '100.00', 5, 'first.jpg', null);

        $this->images->add($productId, 'first.jpg');
        $secondId = $this->images->add($productId, 'second.jpg');

        $this->images->makePrimary($secondId);

        $gallery  = $this->images->forProduct($productId);
        $primaries = array_filter($gallery, static fn(array $i): bool => $i['is_primary']);

        $this->assertCount(1, $primaries, 'Exactly one image may be primary.');
        $this->assertSame($secondId, array_values($primaries)[0]['id']);
    }

    /**
     * products.image is a denormalised cache of the primary image; if it drifts
     * out of sync every listing page shows the wrong picture.
     */
    public function test_primary_image_is_mirrored_onto_the_product_row(): void
    {
        $productId = $this->products->create('Mirror Shirt', 'desc', '100.00', 5, 'first.jpg', null);

        $this->images->add($productId, 'first.jpg');
        $secondId = $this->images->add($productId, 'second.jpg');
        $this->images->makePrimary($secondId);

        $this->assertSame('second.jpg', $this->products->find($productId)['image']);
    }

    public function test_last_remaining_image_cannot_be_deleted(): void
    {
        $productId = $this->products->create('One Image', 'desc', '100.00', 5, 'only.jpg', null);
        $imageId   = $this->images->add($productId, 'only.jpg');

        $this->assertFalse($this->images->delete($imageId), 'Deleting the only image must be refused.');
        $this->assertCount(1, $this->images->forProduct($productId));
    }

    public function test_deleting_the_primary_promotes_another_image(): void
    {
        $productId = $this->products->create('Promote Shirt', 'desc', '100.00', 5, 'first.jpg', null);

        $firstId = $this->images->add($productId, 'first.jpg');
        $this->images->add($productId, 'second.jpg');

        $this->assertTrue($this->images->delete($firstId));

        $gallery = $this->images->forProduct($productId);
        $this->assertCount(1, $gallery);
        $this->assertTrue($gallery[0]['is_primary'], 'A product must always have a primary image.');
        $this->assertSame('second.jpg', $this->products->find($productId)['image']);
    }

    /* ---------------- Low-stock override ---------------- */

    public function test_low_stock_threshold_falls_back_to_config(): void
    {
        // Config default is 5.
        $this->assertSame(5, ProductRepository::lowStockThresholdFor(['low_stock_threshold' => null]));
        $this->assertSame(20, ProductRepository::lowStockThresholdFor(['low_stock_threshold' => 20]));
    }

    public function test_stats_respect_the_per_product_threshold(): void
    {
        // Stock 8 is above the store default of 5, so normally not "low"...
        $this->products->create('Custom Threshold', 'desc', '100.00', 8, 'a.jpg', null, null, 10);
        // ...and this one uses the default, so 8 is fine.
        $this->products->create('Default Threshold', 'desc', '100.00', 8, 'b.jpg', null);

        $stats = $this->products->stats();

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['low_stock'], 'Only the product with the raised threshold is low.');
    }

    public function test_related_products_come_from_the_same_category(): void
    {
        $shirts = $this->categories->create('Shirts');
        $pants  = $this->categories->create('Pants');

        $main = $this->products->create('Main Shirt', 'desc', '100.00', 5, 'a.jpg', $shirts);
        $this->products->create('Other Shirt', 'desc', '100.00', 5, 'b.jpg', $shirts);
        $this->products->create('A Pant', 'desc', '100.00', 5, 'c.jpg', $pants);

        $related = $this->products->relatedTo($main, $shirts);

        $this->assertCount(1, $related);
        $this->assertSame('Other Shirt', $related[0]['name']);
    }

    public function test_uncategorised_product_has_no_related_products(): void
    {
        $id = $this->products->create('Lonely', 'desc', '100.00', 5, 'a.jpg', null);

        $this->assertSame([], $this->products->relatedTo($id, null));
    }
}
