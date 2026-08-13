# Shirt & Pant Store

A clothing e-commerce web application built with **PHP 8** and **MySQL/MariaDB**.
Customers browse and buy products; admins manage the catalog from a separate
seller panel.

The codebase follows the engineering rules in [PROJECT_RULES.md](PROJECT_RULES.md),
which is the project's architecture and security constitution. **Phase 0
(Architecture & Safety Foundation) and Phase 1 (Catalog Foundation) are
complete** — see the [Phase 0](#phase-0--architecture--safety-foundation-complete)
and [Phase 1](#phase-1--catalog-foundation-complete) changelogs below.

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
- Product detail pages at readable URLs (`product.php?slug=denim-pant`) with an
  image gallery, stock state and related products
- Register / login (bcrypt hashes, rate-limited login)
- Buy a product (quantity selectable, stock-checked, transactional)
- View personal order history with the price actually paid

**Admin (Seller Panel)**
- Dashboard: products, low/out of stock, archived, orders, revenue, customers
- Add / update products with validated image upload, SKU and an optional
  per-product low-stock threshold
- Manage each product's image gallery (upload, set primary, delete)
- Manage categories (create, rename, delete safely)
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

## Phase 1 — Catalog Foundation (complete)

| Feature | Detail |
|---|---|
| Product slugs | `App\Support\Slugger` generates unique, URL-safe slugs. Stable across edits that don't change the name; `?product_id=N` 301-redirects to the canonical slug URL. |
| Product detail page | New `product.php` — gallery, breadcrumb, stock state, SKU, related products, `meta description` + Open Graph tags. Unknown slug returns a real **404**. |
| Multiple images | `product_images` table with exactly one primary. `products.image` is kept in sync as a denormalised cache so list views need no join. A product can never be left with zero images. |
| Category management | Full admin CRUD. Deleting a category leaves its products on sale and uncategorised, and says how many are affected before you confirm. |
| Per-product low stock | Optional `low_stock_threshold` override; `NULL` means "use the store default from config". Dashboard counts respect the override via `COALESCE`. |
| SKU | Optional stock-keeping code, shown on the product page. |
| PHP migrations | The runner now executes `.php` migrations as well as `.sql`, so data backfills can reuse application logic instead of reimplementing it in SQL. Both the CLI runner and the test bootstrap go through one shared `Migrator` class, so the test schema cannot drift from the real one. |

Verified end-to-end: slug URLs resolve, unknown slugs 404, legacy id URLs
301-redirect, gallery upload stores a server-generated filename, set-primary
updates `products.image`, deleting the last image is refused, a forged
`image_id` from another product is rejected, and deleting a category leaves its
products on sale with order history intact.

## Roadmap

Phases 0 and 1 are done. Next, in the order PROJECT_RULES.md §37 recommends:
**cart** → checkout → order status machine → admin order management →
inventory movements → payment abstraction → customer profile/address/password
reset → notifications → reviews → wishlist → blog CMS → roles & permissions →
analytics → audit logs → production hardening.

Known gaps deliberately **not** addressed yet: shopping cart, order status
tracking, real email delivery, password reset, reviews, wishlist, profile
editing, and the admin order-management screen.

## Author

Iftakher Ahmed Rakib
