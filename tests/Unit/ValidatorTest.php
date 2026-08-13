<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The validation layer is the boundary that decides what reaches the database,
 * so its edge cases are worth pinning down (PROJECT_RULES.md §24 "Unit").
 */
final class ValidatorTest extends TestCase
{
    public function test_required_flags_missing_and_whitespace_only_values(): void
    {
        $validator = (new Validator(['name' => '   ', 'other' => 'ok']))
            ->required('name')
            ->required('missing')
            ->required('other');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors());
        $this->assertArrayHasKey('missing', $validator->errors());
        $this->assertArrayNotHasKey('other', $validator->errors());
    }

    public function test_email_rejects_malformed_addresses(): void
    {
        $this->assertTrue((new Validator(['email' => 'not-an-email']))->email('email')->fails());
        $this->assertTrue((new Validator(['email' => 'a@b']))->email('email')->fails());
        $this->assertTrue((new Validator(['email' => 'user@example.com']))->email('email')->passes());
    }

    public function test_password_length_rules(): void
    {
        $short = (new Validator(['password' => '1234567']))->minLength('password', 8);
        $this->assertTrue($short->fails());

        $ok = (new Validator(['password' => '12345678']))->minLength('password', 8);
        $this->assertTrue($ok->passes());
    }

    public function test_matches_detects_mismatched_confirmation(): void
    {
        $validator = (new Validator(['password' => 'secret123', 'confirm' => 'secret124']))
            ->matches('confirm', 'password');

        $this->assertTrue($validator->fails());
    }

    /**
     * Money must never be parsed loosely. "1e5" would become 100000.0 under a
     * plain (float) cast, so the regex has to reject it.
     */
    public function test_decimal_rejects_scientific_notation_and_bad_separators(): void
    {
        foreach (['1e5', '12,50', '10.999', 'abc', '-5', ''] as $bad) {
            $validator = (new Validator(['price' => $bad]))->required('price')->decimal('price', 0);
            $this->assertTrue(
                $validator->fails(),
                "Expected '{$bad}' to be rejected as a price."
            );
        }

        foreach (['0', '10', '10.5', '1099.99'] as $good) {
            $validator = (new Validator(['price' => $good]))->decimal('price', 0);
            $this->assertTrue(
                $validator->passes(),
                "Expected '{$good}' to be accepted as a price."
            );
        }
    }

    public function test_integer_enforces_range(): void
    {
        $this->assertTrue((new Validator(['stock' => '-1']))->integer('stock', 0)->fails());
        $this->assertTrue((new Validator(['stock' => '5']))->integer('stock', 0, 10)->passes());
        $this->assertTrue((new Validator(['stock' => '11']))->integer('stock', 0, 10)->fails());
        $this->assertTrue((new Validator(['stock' => '1.5']))->integer('stock')->fails());
    }

    /**
     * inList is what stops a forged category_id or payment_method from being
     * accepted (§19 "allowed enums").
     */
    public function test_in_list_rejects_values_outside_the_whitelist(): void
    {
        $allowed = ['1', '2', '3'];

        $this->assertTrue((new Validator(['category_id' => '99']))->inList('category_id', $allowed)->fails());
        $this->assertTrue((new Validator(['category_id' => '2']))->inList('category_id', $allowed)->passes());
    }

    public function test_only_first_error_per_field_is_kept(): void
    {
        $validator = (new Validator(['email' => '']))
            ->required('email')
            ->email('email')
            ->minLength('email', 5);

        $this->assertCount(1, $validator->errors());
    }

    public function test_labels_are_used_in_messages(): void
    {
        $validator = (new Validator([]))
            ->label('fullname', 'Full name')
            ->required('fullname');

        $this->assertSame('Full name is required.', $validator->firstError());
    }
}
