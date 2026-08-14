# Shirt & Pant Store

A clothing e-commerce web application built with **PHP 8** and **MySQL/MariaDB**.
Customers browse and buy products; admins manage the catalog from a separate
seller panel.

The codebase follows the engineering rules in [PROJECT_RULES.md](PROJECT_RULES.md),
which is the project's architecture and security constitution. **Phases 0–3 are
complete**: [Architecture & Safety Foundation](#phase-0--architecture--safety-foundation-complete),
[Catalog Foundation](#phase-1--catalog-foundation-complete),
[Cart & Checkout](#phase-2--cart--checkout-complete) and
[Orders & Address Book](#phase-3--orders--address-book-complete).

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
- **Add to cart as a guest** — the cart follows you into your account on login
- Update quantities, remove items, empty the cart
- **Manage a full address book** (multiple addresses, one default)
- Checkout: choose delivery address, payment method, add a note, review, place
- Buy Now for a single item, skipping straight to checkout
- Register / login (bcrypt hashes, rate-limited login)
- Track order status on a real timeline (Pending → Confirmed → Processing →
  Shipped → Delivered, or Cancelled/Returned/Refunded)
- Order history, one row per checkout regardless of item count

**Admin (Seller Panel)**
- Dashboard: order status breakdown, revenue, low/out of stock, customers
- **Manage orders**: filter by status, search, view detail, transition status
  with a note (only the legal next statuses are ever offered)
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
│   ├── Catalog/                   ProductRepository, CategoryRepository,
│   │                              ProductImageRepository
│   ├── Cart/                      CartService, CartRepository
│   ├── Account/                   AddressRepository
│   └── Orders/                    OrderService, OrderRepository, OrderStatus,
│                                  PaymentMethod
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

## Phase 2 — Cart & Checkout (complete)

| Feature | Detail |
|---|---|
| Cart | `carts` + `cart_items`, one line per product (`UNIQUE (cart_id, product_id)`). Add, update quantity, remove and clear all go through one POST-only, CSRF-verified endpoint. |
| **Guest carts** | Supported. Anonymous visitors get a random cart token in an httponly cookie; on login that cart is merged into their account — quantities summed, then clamped to available stock — and discarded. |
| Server-side money | Totals are always computed from the live `products.price`. `price_at_add` is stored **only** to tell the customer "this was ৳100 when you added it"; it never sets the charge. |
| Stock safety | Validated on add and update, then re-validated at checkout under a `SELECT … FOR UPDATE` row lock. The cart warns "only N left" and blocks checkout — enforced on the server, not just by disabling the button. |
| Transactional checkout | `placeOrderFromCart()` locks products in id order (no deadlocks), re-prices every line, and rolls back the whole order if any line fails. **Partial success is impossible.** |
| Order references | One checkout = one `ORD-XXXXXXXX` reference, so a three-product order reads as one order in My Orders instead of three. |
| Idempotency | A single-use token means a double-click or refresh on Place Order cannot create a second order. |

Verified end-to-end: a guest built a cart, logged in and kept it; a two-product
checkout decremented both stocks and emptied the cart; replaying the checkout
token created no duplicate; forcing a POST past the disabled button was refused
server-side with zero orders written; and editing another customer's cart line
by changing `item_id` was rejected.

## Phase 3 — Orders & Address Book (complete)

| Feature | Detail |
|---|---|
| Orders/order_items restructure | Replaced the line-item-only `single_order` table with `orders` (one row per checkout: status, address snapshot, payment) + `order_items` (one row per product). `single_order`/`payments` are preserved untouched as historical record; a migration copied their data forward without deleting the originals (Rule 10). |
| Order status machine | `OrderStatus` defines the legal transition graph explicitly (Pending → Confirmed → Processing → Shipped → Delivered, plus Cancelled/Failed/Returned/Refunded as appropriate branches) and rejects everything else — enforced server-side regardless of what the admin dropdown offers. |
| Order status history | Every transition is recorded: from/to status, which admin changed it, an optional note, a timestamp. Customers and admins both see it as an actual timeline, not a fixed progress bar that would misrepresent a cancelled order. |
| Address book | Customers manage multiple delivery addresses with one default. Checkout selects from the book instead of a fixed registration address; deleting or editing a saved address never changes what a past order shows, because the order keeps its own text snapshot. |
| Admin order management | `admin/orders.php` (filter by status, search, paginate) and `admin/vieworder.php` (full detail + status transition). |
| One order-creation path | `singleorder.php` and the old `placeSingleProductOrder()` were removed. Buy Now now adds one item to the cart and goes straight to checkout — there is exactly one place order creation happens. |

Verified end-to-end: checkout without a saved address blocks with a link to
add one; placing an order snapshots the chosen address; the admin dashboard
and order list show live status counts; an admin cannot skip status steps even
by forging the POST value (rejected server-side, no history row written); a
status change is instantly visible on the customer's tracking page; and a
customer cannot open another customer's order by guessing its reference (403).

A real bug was caught before merging: `ProductRepository::hasOrders()` still
queried the retired `single_order` table after the restructure and was fixed
to query `order_items`.

## Roadmap

Phases 0–3 are done. Next, in the order PROJECT_RULES.md §37 recommends:
**inventory movement tracking** → payment abstraction (bKash/card) → customer
profile editing/password reset → email notifications → reviews → wishlist →
blog CMS → roles & permissions → analytics → audit logs → production hardening.

Known gaps deliberately **not** addressed yet: real email delivery, password
reset, profile editing beyond addresses, reviews, wishlist, and a proper
payment gateway (COD only for now, by design — see `PaymentMethod`).

## Author

Iftakher Ahmed Rakib
