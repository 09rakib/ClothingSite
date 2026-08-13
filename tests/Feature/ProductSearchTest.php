<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalog\ProductRepository;

/**
 * Storefront search, filtering, sorting and pagination (§12).
 */
final class ProductSearchTest extends DatabaseTestCase
{
    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products = new ProductRepository();
    }

    public function test_search_matches_name_and_description(): void
    {
        $this->createProduct('Denim Pant', '1150.00');
        $this->createProduct('Formal Shirt', '850.00');

        $byName = $this->products->paginateActive(['search' => 'Denim']);
        $this->assertSame(1, $byName['total']);
        $this->assertSame('Denim Pant', $byName['items'][0]['name']);

        // Every seeded product shares the description "A test product".
        $byDescription = $this->products->paginateActive(['search' => 'test product']);
        $this->assertSame(2, $byDescription['total']);
    }

    /**
     * A search for "100%" must be treated as literal text, not as a LIKE
     * pattern that matches every row.
     */
    public function test_like_wildcards_in_search_are_escaped(): void
    {
        $this->createProduct('Cotton Shirt');
        $this->createProduct('Linen Shirt');

        $result = $this->products->paginateActive(['search' => '%']);

        $this->assertSame(0, $result['total'], 'A bare % must not match everything.');
    }

    public function test_category_filter_limits_results(): void
    {
        $shirts = $this->createCategory('Shirts');
        $pants  = $this->createCategory('Pants');

        $a = $this->createProduct('Shirt A');
        $b = $this->createProduct('Pant B');
        $this->db->query("UPDATE products SET category_id = {$shirts} WHERE id = {$a}");
        $this->db->query("UPDATE products SET category_id = {$pants} WHERE id = {$b}");

        $result = $this->products->paginateActive(['category' => $shirts]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Shirt A', $result['items'][0]['name']);
    }

    public function test_sorting_by_price(): void
    {
        $this->createProduct('Cheap', '100.00');
        $this->createProduct('Expensive', '900.00');
        $this->createProduct('Middle', '500.00');

        $asc = $this->products->paginateActive(['sort' => 'price_asc']);
        $this->assertSame(['Cheap', 'Middle', 'Expensive'], array_column($asc['items'], 'name'));

        $desc = $this->products->paginateActive(['sort' => 'price_desc']);
        $this->assertSame(['Expensive', 'Middle', 'Cheap'], array_column($desc['items'], 'name'));
    }

    /**
     * ORDER BY cannot be parameterised, so an unknown sort key must fall back
     * to the default rather than reaching the SQL string.
     */
    public function test_unknown_sort_key_falls_back_safely(): void
    {
        $this->createProduct('Only Product');

        $result = $this->products->paginateActive(['sort' => "'; DROP TABLE products; --"]);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $this->countRows('products'), 'products table must still exist.');
    }

    public function test_pagination_splits_results_and_clamps_the_page(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->createProduct("Product {$i}");
        }

        $page1 = $this->products->paginateActive(['per_page' => 4, 'page' => 1]);
        $this->assertCount(4, $page1['items']);
        $this->assertSame(10, $page1['total']);
        $this->assertSame(3, $page1['pages']);

        $page3 = $this->products->paginateActive(['per_page' => 4, 'page' => 3]);
        $this->assertCount(2, $page3['items']);

        // Asking for a page past the end returns the last page, not an error.
        $beyond = $this->products->paginateActive(['per_page' => 4, 'page' => 99]);
        $this->assertSame(3, $beyond['page']);
    }

    public function test_archived_products_are_excluded_from_every_storefront_query(): void
    {
        $visible  = $this->createProduct('Visible Shirt');
        $archived = $this->createProduct('Archived Shirt');

        $this->products->archive($archived);

        $this->assertSame(1, $this->products->paginateActive()['total']);
        $this->assertCount(1, $this->products->latestActive(10));
        $this->assertNull($this->products->findActive($archived));
        $this->assertNotNull($this->products->findActive($visible));
    }

    public function test_stats_count_low_and_out_of_stock_products(): void
    {
        $this->createProduct('Plenty', '100.00', 50);
        $this->createProduct('Low', '100.00', 2);
        $this->createProduct('Gone', '100.00', 0);

        $stats = $this->products->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['low_stock'], 'Low stock counts items below the threshold but above zero.');
        $this->assertSame(1, $stats['out_of_stock']);
    }
}
