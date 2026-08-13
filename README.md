# Shirt & Pant Store

A clothing e-commerce web application built with **PHP 8** and **MySQL/MariaDB**.
Customers browse and buy products; admins manage the catalog from a separate
seller panel.

The codebase follows the engineering rules in [PROJECT_RULES.md](PROJECT_RULES.md),
which is the project's architecture and security constitution. **Phase 0
(Architecture & Safety Foundation) is complete** — see
[Phase 0 changelog](#phase-0--architecture--safety-foundation-complete) below.

## Tech Stack

- PHP 8.1+ (modular OOP, `mysqli` with prepared statements throughout)
- MySQL / MariaDB
- Composer (PSR-4 autoloading), PHPUnit 10 for tests
- HTML5, CSS3 (single shared stylesheet, no framework)

## Setup

```bash
# 1. Start Apache + MySQL (XAMPP/Laragon).

# 2. Install dev dependencies (needed for the test suite).
composer install

# 3. Create/upgrade the database and load demo data.
php database/migrate.php

# 4. Open the project in your browser, e.g.
#    http://localhost/ClothingSite-professional/ClothingSite/index.php
```

To override credentials without editing tracked code, copy
`config/config.local.example.php` to `config/config.local.php` — that file is
git-ignored (§19 "Secrets").

> `database.sql` is deprecated and must not be imported; it is superseded by
> the versioned migrations in `database/migrations/`.

### Demo accounts

| Role     | Email             | Password     |
|----------|-------------------|--------------|
| Admin    | admin@shop.com    | Admin@123    |
| Customer | customer@shop.com | Customer@123 |

### Useful commands

| Command | What it does |
|---|---|
| `php database/migrate.php` | Apply pending migrations |
| `php database/migrate.php --status` | List applied/pending migrations |
| `php database/migrate.php --backup` | Back up the database, then migrate |
| `vendor/bin/phpunit` | Run the full test suite |
| `vendor/bin/phpunit --testsuite Unit` | Run unit tests only |

## Features

**Customer**
- Browse products on the Home and Shop pages
- Search, filter by category, sort and paginate the catalog
- Register / login (bcrypt hashes, rate-limited login)
- Buy a product (quantity selectable, stock-checked, transactional)
- View personal order history with the price actually paid

**Admin (Seller Panel)**
- Dashboard: products, low/out of stock, archived, orders, revenue, customers
- Add / update products with validated image upload
- Archive & restore products (archiving preserves order history)

**Static pages**
- About, Blog, Contact (contact messages are stored with a reference number)

## Project Structure

```
ClothingSite/
├── config/
│   ├── config.php                 Centralized configuration (business rules)
│   └── config.local.example.php   Template for local secrets (git-ignored copy)
├── src/
│   ├── bootstrap.php              Autoloader, config, session, security headers
│   ├── Support/                   Config, Database, Auth, Csrf, Session,
│   │                              Validator, Flash, Http, ImageUploader,
│   │                              OneTimeToken, RateLimiter, Logger, View
│   ├── Catalog/                   ProductRepository, CategoryRepository
│   └── Orders/                    OrderService, PaymentMethod
├── database/
│   ├── migrate.php                Migration runner (+ backup)
│   ├── migrations/                Versioned schema changes
│   └── backups/                   Generated dumps (git-ignored)
├── tests/
│   ├── Unit/                      Validation + security primitives
│   └── Feature/                   Real-database order & catalog tests
├── admin/                         Seller panel
├── assets/                        CSS + images
├── includes/                      Shared layout partials
└── *.php                          Public pages
```

Architecture note: business logic lives in `src/` services and repositories;
page files only orchestrate the request and render output. Pages do not contain
SQL (PROJECT_RULES.md §3).

## Phase 0 — Architecture & Safety Foundation (complete)

### The critical bug this phase fixed

`single_order.product_id` referenced `products` with **`ON DELETE CASCADE`**,
and `payments.order_id` cascaded from `single_order`. Deleting **one product
silently deleted every order that contained it and the matching payment rows**
— a customer's purchase history and the store's revenue record could disappear
because an admin tidied up the catalog.

The fix has three parts, and all three are required:

1. **Order snapshots** — orders now store `product_name`, `unit_price` and
   `quantity` as they were at purchase time, so history stays accurate after a
   product is renamed or repriced.
2. **`RESTRICT` foreign keys** — the database now refuses to delete a product
   or customer that has order history.
3. **Soft delete** — "Delete" in the admin panel now *archives*: the product
   leaves the storefront, its orders survive, and the action is reversible.

Regression tests in `tests/Feature/OrderHistoryIntegrityTest.php` fail loudly if
this behaviour is ever reintroduced.

### Everything else delivered in Phase 0

| Area | Before | After |
|---|---|---|
| CSRF | None anywhere | Token on every state-changing form, verified server-side |
| HTTP methods | Buy Now & Delete were GET links | POST-only, enforced server-side (405 on GET) |
| Duplicate orders | Refresh created a second order | Single-use token makes checkout idempotent |
| Uploads | Filename-only check, trusted `accept` attribute | Real MIME sniffed from content, size/dimension limits, server-generated filename |
| Config | `stock < 5` and credentials hardcoded in 3+ files | Centralized in `config/config.php`, secrets in git-ignored local file |
| Validation | Ad-hoc inline `if` checks; JS was sole source for some rules | Shared server-side `Validator` |
| Authorization | Role check duplicated in two files | Single `Auth` gate; ownership checks to prevent IDOR |
| Payment method | `'cash_on_delivery'` hardcoded in order code | `PaymentMethod` registry, validated against config whitelist |
| Stock races | Read-then-write, could oversell | Row lock (`FOR UPDATE`) + guarded update + DB `CHECK (stock >= 0)` |
| Catalog | Flat unfiltered list | Server-side search, category filter, sort, pagination (indexed) |
| Contact form | Showed fake success, saved nothing | Persists the message and returns a real reference number |
| Schema changes | Hand-imported `database.sql` | Versioned, tracked migrations with backup support |
| Tests | None | 46 PHPUnit tests (unit + real-database feature tests) |
| Login | Unlimited attempts; error revealed whether an email existed | Rate limited; identical message for unknown email and wrong password |

### Verified end-to-end

Buy Now and product deletion return **405** on GET; a POST without a CSRF token
returns **403**; a replayed checkout token is rejected while the first submit
succeeds; archiving a product with orders keeps the order visible in *My
Orders* but removes it from the shop; a PHP file renamed to `.jpg` is rejected
by content sniffing and never written to disk; SQL injection attempts through
`?q=` and `?sort=` leave the schema intact.

## Roadmap

Phase 0 is done. Remaining phases, in the order PROJECT_RULES.md §37
recommends: catalog foundation → **cart** → checkout & order status machine →
admin order management → inventory movements → payment abstraction →
customer profile/address/password reset → notifications → reviews → wishlist →
blog CMS → roles & permissions → analytics → audit logs → production hardening.

Known gaps deliberately **not** addressed in Phase 0: shopping cart, order
status tracking, real email delivery, password reset, reviews, wishlist,
profile editing, and the admin order-management screen.

## Author

Iftakher Ahmed Rakib
