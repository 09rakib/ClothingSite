<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Slugger;
use PHPUnit\Framework\TestCase;

/**
 * Slug generation rules (PROJECT_RULES.md §11, §26).
 *
 * Slugs end up in public URLs, so anything that could produce an unsafe or
 * empty slug is worth pinning down.
 */
final class SluggerTest extends TestCase
{
    public function test_basic_names_become_lowercase_hyphenated(): void
    {
        $this->assertSame('denim-pant', Slugger::make('Denim Pant'));
        $this->assertSame('classic-formal-shirt', Slugger::make('Classic Formal Shirt'));
    }

    /**
     * Apostrophes are removed rather than turned into a separator, so
     * "Men's Shirt" reads as "mens-shirt" and not "men-s-shirt". Other symbols
     * act as word boundaries.
     */
    public function test_punctuation_and_symbols_are_stripped(): void
    {
        $this->assertSame('mens-shirt', Slugger::make("Men's Shirt"));
        $this->assertSame('shirt-pant-combo', Slugger::make('Shirt & Pant Combo'));
        $this->assertSame('50-off-sale', Slugger::make('50% Off Sale!'));
    }

    public function test_repeated_and_edge_separators_are_collapsed(): void
    {
        $this->assertSame('a-b', Slugger::make('  a   ---  b  '));
        $this->assertSame('trailing', Slugger::make('---trailing---'));
    }

    /**
     * A slug must never be empty, because it becomes part of a URL.
     */
    public function test_unusable_input_falls_back(): void
    {
        $this->assertSame('item', Slugger::make(''));
        $this->assertSame('item', Slugger::make('!!!'));
        $this->assertSame('product', Slugger::make('###', 'product'));
    }

    public function test_result_is_always_url_safe(): void
    {
        foreach (['Café Crème', 'Ünïcödé Shirt', '日本語 shirt', "quote'd \"item\"", 'a/b\\c'] as $input) {
            $slug = Slugger::make($input);

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                $slug,
                "Slug for '{$input}' was '{$slug}', which is not URL-safe."
            );
        }
    }

    public function test_long_names_are_truncated_without_trailing_hyphen(): void
    {
        $slug = Slugger::make(str_repeat('long name ', 40));

        $this->assertLessThanOrEqual(180, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }
}
