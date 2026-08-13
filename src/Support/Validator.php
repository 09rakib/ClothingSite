<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-side input validation (PROJECT_RULES.md §19 "Input validation", Phase 0).
 *
 * WHY: validation used to be a chain of inline `if ($x === '')` checks that
 * differed slightly on every page, and the JS checks in login/register were the
 * only place some rules existed. Client-side validation is a UX affordance, not
 * a control — everything is re-checked here on the server.
 *
 * Usage:
 *   $v = new Validator($_POST);
 *   $v->required('name')->maxLength('name', 120)
 *     ->required('price')->decimal('price', 0);
 *   if ($v->fails()) { $errors = $v->errors(); }
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,string> field => first error message */
    private array $errors = [];

    /** @var array<string,string> field => human readable label */
    private array $labels = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Give a field a friendlier name for error messages.
     */
    public function label(string $field, string $label): self
    {
        $this->labels[$field] = $label;

        return $this;
    }

    public function value(string $field): string
    {
        $value = $this->data[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function required(string $field): self
    {
        if ($this->value($field) === '') {
            $this->fail($field, '%s is required.');
        }

        return $this;
    }

    public function email(string $field): self
    {
        $value = $this->value($field);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->fail($field, 'Please enter a valid %s.');
        }

        return $this;
    }

    public function minLength(string $field, int $min): self
    {
        $value = $this->value($field);
        if ($value !== '' && mb_strlen($value) < $min) {
            $this->fail($field, "%s must be at least {$min} characters.");
        }

        return $this;
    }

    public function maxLength(string $field, int $max): self
    {
        $value = $this->value($field);
        if ($value !== '' && mb_strlen($value) > $max) {
            $this->fail($field, "%s must not exceed {$max} characters.");
        }

        return $this;
    }

    public function matches(string $field, string $otherField): self
    {
        if ($this->value($field) !== $this->value($otherField)) {
            $this->fail($field, '%s does not match.');
        }

        return $this;
    }

    /**
     * Whole number within an optional range.
     */
    public function integer(string $field, ?int $min = null, ?int $max = null): self
    {
        $value = $this->value($field);
        if ($value === '') {
            return $this;
        }

        if (!preg_match('/^-?\d+$/', $value)) {
            return $this->fail($field, '%s must be a whole number.');
        }

        $number = (int) $value;
        if ($min !== null && $number < $min) {
            return $this->fail($field, "%s must be at least {$min}.");
        }
        if ($max !== null && $number > $max) {
            return $this->fail($field, "%s must not be greater than {$max}.");
        }

        return $this;
    }

    /**
     * Money-style decimal. Rejects scientific notation and stray characters
     * so a price can never arrive as "1e5" or "12,50".
     */
    public function decimal(string $field, ?float $min = null, ?float $max = null): self
    {
        $value = $this->value($field);
        if ($value === '') {
            return $this;
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            return $this->fail($field, '%s must be a number with up to two decimal places.');
        }

        $number = (float) $value;
        if ($min !== null && $number < $min) {
            return $this->fail($field, "%s must be at least {$min}.");
        }
        if ($max !== null && $number > $max) {
            return $this->fail($field, "%s must not be greater than {$max}.");
        }

        return $this;
    }

    /**
     * Value must be one of an allowed set (§19 "allowed enums").
     *
     * @param array<int,string> $allowed
     */
    public function inList(string $field, array $allowed): self
    {
        $value = $this->value($field);
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->fail($field, '%s is not a valid choice.');
        }

        return $this;
    }

    public function phone(string $field): self
    {
        $value = $this->value($field);
        if ($value !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $value)) {
            $this->fail($field, 'Please enter a valid %s.');
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * First error message, convenient for pages that show a single alert.
     */
    public function firstError(): string
    {
        return $this->errors === [] ? '' : (string) reset($this->errors);
    }

    /**
     * Record an error, keeping only the first failure per field so the user
     * is not shown five messages about the same input.
     */
    public function fail(string $field, string $template): self
    {
        if (!isset($this->errors[$field])) {
            $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $this->errors[$field] = sprintf($template, $label);
        }

        return $this;
    }
}
