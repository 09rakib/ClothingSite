# Production E-commerce Master Plan & Claude Agent Rules

## 0. Purpose

This document is the permanent engineering guide for the two-role
e-commerce project.

Roles: - Customer/User - Admin

Primary objective: - Evolve the current browse + instant-buy application
into a maintainable, secure, production-ready e-commerce platform. -
Every future feature must follow the architecture, security, database,
testing, and deployment rules in this document. - Claude Agent must read
this document before making project changes and must keep it updated
when architecture decisions change.

------------------------------------------------------------------------

# 1. Current State

## Working now

### Customer

-   Registration with email validation, bcrypt password hashing,
    duplicate-email protection.
-   Session-based login/logout with session fixation protection.
-   Home page with recent products.
-   Shop page with flat product list.
-   Buy Now creates an order when stock exists.
-   Stock is decremented automatically.
-   Cash on delivery is currently hardcoded.
-   Customer can view own order history.
-   About page.
-   Blog is currently hardcoded.
-   Contact form currently displays a success message but does not
    persist/send the message.

### Admin

-   Real role-based authentication gate.
-   Dashboard with product count, low-stock count, order count and
    revenue.
-   Product CRUD with image upload.

### Security/Infrastructure

-   Prepared statements are used for SQL queries.
-   Output is escaped with htmlspecialchars.
-   Passwords use bcrypt.

## Major missing areas

1.  Cart.
2.  Proper checkout.
3.  Payment-method selection.
4.  Shipping address management.
5.  Order lifecycle/status tracking.
6.  Product search/filter/sort/pagination.
7.  Reviews/ratings.
8.  Wishlist.
9.  Profile management.
10. Forgot/reset password.
11. Real email notifications.
12. Database-backed blog.
13. Working contact/ticket system.
14. Admin order management.
15. Sales analytics/reporting.
16. User management.
17. Category management.
18. Strong image/MIME/size validation.
19. Configurable low-stock threshold.
20. Staff/admin permissions.
21. CSRF protection.
22. POST-only destructive/state-changing actions.
23. Correct order-history/product deletion relationship.
24. Product variants.

------------------------------------------------------------------------

# 2. Product Vision

The target system is not simply a PHP shopping website.

It should be treated as a modular e-commerce application with:

-   Customer storefront.
-   Admin back office.
-   Product/catalog management.
-   Inventory management.
-   Cart and checkout.
-   Order management.
-   Payment abstraction.
-   Shipping/address management.
-   Customer account management.
-   Content/blog management.
-   Notification system.
-   Reporting/analytics.
-   Audit/security controls.
-   Automated testing.
-   Production deployment and monitoring.

The system must be designed so that adding a future payment gateway,
delivery provider, staff role, promotion engine, API/mobile client, or
reporting feature does not require rewriting the core order system.

------------------------------------------------------------------------

# 3. Architecture Principles

## 3.1 Do not build everything as one large file

Avoid: - giant PHP files - duplicated SQL - duplicated authentication
checks - business logic inside HTML templates - database queries
directly scattered through views - hardcoded statuses and payment
methods everywhere - hardcoded configuration values - copy/paste
implementations of the same operation

Prefer: - clear modules - service classes - repositories/data-access
layer where useful - request validation - authorization policies -
reusable components - centralized configuration - database migrations -
transactions for business-critical operations

## 3.2 Separate responsibilities

Use a structure conceptually similar to:

-   Controllers: HTTP/request orchestration.
-   Services: business rules.
-   Repositories/Query layer: database access.
-   Models/Entities: domain data.
-   Validators/Form Requests: input validation.
-   Policies/Authorization: permissions.
-   Views/Templates: presentation only.
-   Jobs/Workers: asynchronous tasks.
-   Notifications: email/system notifications.
-   Integrations: payment/email/shipping providers.
-   Helpers: small generic utilities only.

If the current project is plain PHP, do not create a fake framework.
Either refactor toward a clean modular PHP architecture or migrate
deliberately to Laravel rather than mixing two incompatible patterns.

------------------------------------------------------------------------

# 4. Recommended Module Structure

Recommended high-level modules:

1.  Authentication
2.  Users
3.  Catalog
4.  Categories
5.  Product Variants
6.  Inventory
7.  Cart
8.  Checkout
9.  Orders
10. Payments
11. Shipping
12. Reviews
13. Wishlist
14. Promotions
15. Notifications
16. Blog/CMS
17. Contact/Support
18. Admin
19. Reports
20. Audit Logs
21. System Settings

Each module should have: - database tables - validation rules -
authorization rules - service/business logic - UI routes/pages - tests -
audit considerations

------------------------------------------------------------------------

# 5. Database Design Direction

Use normalized relational tables.

Core tables should evolve toward:

## Identity

-   users
-   roles
-   permissions
-   role_permissions
-   user_roles if multi-role support is required
-   password_reset_tokens
-   sessions if database sessions are used

## Catalog

-   products
-   categories
-   product_categories
-   product_images
-   product_variants
-   product_variant_values/options
-   brands if the business needs them

## Inventory

-   inventory
-   inventory_movements
-   stock_reservations if required for checkout/payment windows

Do not only store the current stock number. Maintain an inventory
movement/history trail for important stock changes.

## Shopping

-   carts
-   cart_items
-   wishlists
-   wishlist_items

## Customer

-   customer_profiles
-   addresses
-   optionally customer_notes

Do not store only one address directly on the user record if multiple
shipping addresses may be required.

## Orders

-   orders
-   order_items
-   order_status_history
-   order_addresses/snapshots
-   payments
-   payment_transactions
-   shipments
-   shipment_status_history

Important: Order items must store a snapshot of the product information
needed for historical accuracy: - product ID - variant ID if
applicable - product name at purchase time - SKU - unit price -
quantity - discount - tax if applicable - subtotal

An old order must remain correct even if the product is later renamed,
repriced, archived, or deleted.

## Content

-   blog_posts
-   blog_categories
-   blog_tags if needed
-   contact_messages

## Reviews

-   reviews
-   review_images if needed

## Promotions

-   coupons
-   coupon_usages
-   promotions if a more advanced campaign system is required

## System

-   settings
-   notifications
-   audit_logs
-   admin_activity_logs

------------------------------------------------------------------------

# 6. Critical Database Rules

1.  Never delete historical order data because a product was deleted.
2.  Prefer soft-delete/archive for products that have historical
    references.
3.  Foreign keys must have intentional ON DELETE behavior.
4.  Never use cascading deletes blindly.
5.  Add indexes for:
    -   email
    -   SKU
    -   category
    -   status
    -   created_at
    -   order number
    -   foreign keys
6.  Add unique constraints where business rules require them.
7.  Use decimal/integer-safe money representation; never use
    floating-point for currency.
8.  Use transactions for:
    -   checkout
    -   order creation
    -   stock deduction
    -   payment state changes
    -   cancellation/refund stock restoration
9.  Use database constraints in addition to application validation.
10. All schema changes must be migration-based and reversible where
    practical.

------------------------------------------------------------------------

# 7. Order System Design

The order system is the heart of the application.

Recommended order lifecycle:

Pending -\> Confirmed -\> Processing -\> Shipped -\> Delivered

Alternative terminal states: - Cancelled - Failed - Returned - Refunded

Do not allow arbitrary status changes.

Define valid transitions explicitly.

Example: - Pending -\> Confirmed - Pending -\> Cancelled - Confirmed -\>
Processing - Confirmed -\> Cancelled - Processing -\> Shipped -
Processing -\> Cancelled only if business rules allow it - Shipped -\>
Delivered - Delivered -\> Returned if returns are supported - Returned
-\> Refunded

Every status change should: - be authorized - be recorded in
order_status_history - record who changed it - record timestamp -
optionally record a note/reason

Customer should see a timeline instead of only a single status label.

------------------------------------------------------------------------

# 8. Cart + Checkout Architecture

Implement in this order:

## Cart

-   Add to cart.
-   Update quantity.
-   Remove item.
-   Clear cart.
-   Validate stock.
-   Recalculate totals server-side.
-   Handle logged-in customer cart.
-   Decide whether guest cart is supported.

Never trust totals, prices, discounts, or stock values sent from the
browser.

## Checkout

Steps:

1.  Review cart.
2.  Select/create shipping address.
3.  Select payment method.
4.  Review order.
5.  Place order.
6.  Create payment record.
7.  Reserve/deduct stock according to the chosen payment flow.
8.  Clear cart only after successful order creation.
9.  Send confirmation asynchronously.

Checkout must use a database transaction.

Use idempotency protection so double-clicking Place Order does not
create duplicate orders.

------------------------------------------------------------------------

# 9. Payment Architecture

Do not hardcode:

cash_on_delivery

Create a payment abstraction.

Example conceptual interface:

PaymentGateway - createPayment() - verifyPayment() - cancelPayment() -
refundPayment()

Implement methods independently: - Cash on Delivery - bKash - Card -
future providers

The order system should not contain provider-specific code.

Use payment states such as: - pending - authorized - paid - failed -
cancelled - refunded - partially_refunded

Never mark an online payment as successful merely because the browser
returned to a success URL. Verify server-to-server with the payment
provider.

Never store raw card numbers or CVV.

------------------------------------------------------------------------

# 10. Inventory

Inventory must be treated as a separate business domain.

Features: - Current stock. - Low-stock threshold. - Stock adjustment. -
Stock receive. - Stock deduction. - Stock return. - Stock movement
history. - Admin adjustment reason. - Optional stock reservation during
checkout.

Prevent negative stock at database/business-rule level.

Use transactions and locking where necessary to prevent race conditions
when two customers buy the last item simultaneously.

------------------------------------------------------------------------

# 11. Product & Catalog

Product fields should eventually include:

-   ID
-   Name
-   Slug
-   SKU
-   Short description
-   Full description
-   Category
-   Brand if needed
-   Base price
-   Sale price
-   Cost price if business needs it
-   Stock
-   Low-stock threshold
-   Status
-   Featured flag
-   SEO title
-   SEO description
-   Created/updated timestamps
-   Archived/deleted timestamp

Support: - multiple images - primary image - variants - SKU per
variant - price per variant if required - stock per variant

Use slugs for public URLs.

Do not trust uploaded filenames.

------------------------------------------------------------------------

# 12. Search, Filter, Sort and Pagination

Shop should support:

-   keyword search
-   category filter
-   price range
-   availability
-   sort by newest
-   price low-to-high
-   price high-to-low
-   popularity if implemented
-   rating if implemented
-   pagination

Keep filtering server-side.

Use indexed columns and efficient queries.

If catalog size becomes large, consider a dedicated search engine later.
Do not introduce one prematurely.

------------------------------------------------------------------------

# 13. Customer Features

Customer account should include:

-   Dashboard
-   Profile
-   Phone
-   Password change
-   Addresses
-   Orders
-   Order detail
-   Order tracking
-   Wishlist
-   Reviews
-   Notifications
-   Password reset
-   Account deletion/request
-   Optional communication preferences

Never expose another customer's records by changing an ID in the URL.

Every customer resource must be authorized against the authenticated
user.

------------------------------------------------------------------------

# 14. Reviews and Ratings

Rules: - Only eligible customers may review. - Prefer verified-purchase
reviews. - One review per order item unless business rules allow
updates. - Rating 1-5. - Review moderation. - Admin can hide/remove
abusive content. - Average rating should be calculated safely. - Prevent
XSS in review content.

------------------------------------------------------------------------

# 15. Wishlist

Features: - Add/remove product. - Prevent duplicate entries. - Show
stock/availability. - Move item to cart. - Authorization by customer.

------------------------------------------------------------------------

# 16. Admin Panel

Admin should be a real back office.

Main navigation:

Dashboard - Products - Categories - Inventory - Orders - Customers -
Reviews - Wishlist insights if useful - Payments - Shipping -
Coupons/Promotions - Blog/CMS - Contact/Support - Reports - Audit Logs -
Staff/Roles - Settings

Do not expose every admin function to every staff member.

------------------------------------------------------------------------

# 17. Role & Permission System

Start with:

-   Super Admin
-   Admin
-   Staff/Manager

If only two roles are currently needed, keep the database architecture
extensible but do not add unnecessary complexity.

Permission examples: - product.view - product.create - product.update -
product.delete - inventory.view - inventory.adjust - order.view -
order.update - order.cancel - customer.view - customer.update -
review.moderate - report.view - settings.manage - staff.manage

Use authorization checks at the server side.

UI hiding is not security.

------------------------------------------------------------------------

# 18. Admin Dashboard

Dashboard should eventually include:

-   Revenue today
-   Revenue this week
-   Revenue this month
-   Orders today
-   Pending orders
-   Delivered orders
-   Cancelled orders
-   Average order value
-   Customers
-   New customers
-   Low-stock products
-   Top-selling products
-   Recent orders
-   Revenue chart
-   Orders chart

Add: - date range - comparison period - export CSV/Excel if useful

All analytics queries should be optimized and paginated where needed.

------------------------------------------------------------------------

# 19. Security Baseline

Before production, these are mandatory.

## Authentication

-   Secure password hashing.
-   Session fixation protection.
-   Secure session cookies.
-   HttpOnly.
-   SameSite.
-   HTTPS.
-   Login rate limiting.
-   Password reset with expiring single-use tokens.
-   Optional email verification.
-   Optional 2FA for admins.

## Authorization

-   Server-side role checks.
-   Permission checks.
-   Ownership checks.
-   No IDOR vulnerabilities.

## CSRF

Every state-changing browser form must use CSRF protection.

## HTTP methods

Use: - GET for read-only pages. - POST/PUT/PATCH for state changes. -
DELETE or POST-with-confirmation for destructive operations depending on
framework conventions.

Never delete/order/create via GET links.

## Input validation

Validate: - type - length - format - numeric ranges - allowed enums -
uploaded files - IDs - ownership

## Output encoding

Continue using context-appropriate escaping.

## SQL

Continue prepared statements.

## Upload security

Validate: - MIME type from actual file content - extension - file size -
image dimensions - generated server-side filename - storage location

Never trust the client-provided MIME type or filename.

Do not allow executable uploads into publicly executable directories.

## Headers

Use appropriate: - Content-Security-Policy - X-Content-Type-Options -
Referrer-Policy - Permissions-Policy - HSTS when HTTPS is fully
configured

## Secrets

Never commit: - database password - API keys - payment credentials -
SMTP credentials - app secrets

Use environment variables/secrets management.

------------------------------------------------------------------------

# 20. Email & Notifications

Create a notification abstraction.

Events: - registration - email verification - password reset - order
placed - payment success/failure - order confirmed - order shipped -
order delivered - cancellation - refund

Email sending should not block checkout.

Use queue/background jobs when the stack supports it.

Store notification delivery status where operational visibility is
needed.

------------------------------------------------------------------------

# 21. Blog/CMS

Replace hardcoded blog data with database-backed CMS.

Admin: - create - edit - draft - publish - unpublish - archive -
category - slug - featured image - SEO metadata

Customer: - listing - detail - pagination - search/category if needed

Sanitize rich HTML if rich text is supported.

------------------------------------------------------------------------

# 22. Contact & Support

Contact form should: - validate input - save message - generate
ticket/reference number - optionally send notification - rate-limit
submissions - prevent spam - provide admin inbox - track status: - new -
in_progress - resolved - closed

Never trust a success message that is shown before the operation
actually succeeds.

------------------------------------------------------------------------

# 23. Logging & Audit

Separate technical logs from business audit logs.

Technical logs: - exceptions - failed jobs - payment integration
errors - database errors

Audit logs: - admin login - product created/updated/deleted - price
changes - stock changes - order status changes - user role changes -
settings changes - refunds - manual payment changes

Audit entry should contain: - actor - action - target/entity - target
ID - timestamp - IP where appropriate - metadata/reason where
appropriate

Do not log passwords, card data, tokens or secrets.

------------------------------------------------------------------------

# 24. Testing Strategy

Production level means tests are part of the feature.

Minimum test layers:

## Unit

Test: - pricing - discount calculation - stock rules - order status
transitions - permission rules - payment state transitions

## Feature/Integration

Test: - registration - login - checkout - order creation - stock
deduction - cancellation - admin order status update - password reset -
review authorization

## Security tests

Test: - CSRF - IDOR - unauthorized admin access - SQL injection
attempts - XSS payloads - upload attacks - session security - rate
limits

## Regression

Every fixed bug should receive a regression test when practical.

No major feature should be considered complete without tests.

------------------------------------------------------------------------

# 25. Performance

Before production:

-   Enable database indexes.
-   Avoid N+1 queries.
-   Paginate large lists.
-   Cache suitable read-heavy data.
-   Optimize images.
-   Use lazy loading for storefront images.
-   Minify/bundle frontend assets.
-   Use queues for email and heavy tasks.
-   Add database query monitoring.
-   Use CDN/object storage when traffic justifies it.

Do not add Redis/Elasticsearch/etc. just because they are popular.
Introduce infrastructure when the workload requires it.

------------------------------------------------------------------------

# 26. SEO & Storefront Quality

Public pages should have:

-   clean URLs
-   unique title
-   meta description
-   canonical URL
-   Open Graph metadata
-   sitemap.xml
-   robots.txt
-   structured data where useful
-   SEO-friendly product URLs
-   optimized images
-   alt text

Product pages should have: - clear price - stock state - variant
selection - gallery - reviews - delivery information - add-to-cart -
buy-now if retained

------------------------------------------------------------------------

# 27. Accessibility & UX

Target: - keyboard navigation - semantic HTML - visible focus -
accessible forms - labels for inputs - useful error messages -
sufficient contrast - mobile responsive layout - loading states - empty
states - confirmation dialogs for destructive actions - clear checkout
errors

Never rely only on color to communicate status.

------------------------------------------------------------------------

# 28. Deployment & Production

Before going live:

## Environment

Separate: - local/development - staging - production

## Production requirements

-   HTTPS
-   domain
-   production database
-   secure environment variables
-   backups
-   error monitoring
-   log rotation
-   cron/scheduled jobs if needed
-   queue worker if needed
-   image/file storage strategy
-   database migration process

## Backup

Have: - automated database backup - backup retention policy - restore
test

A backup that has never been restored/tested is not considered reliable.

## Deployment

Use a repeatable process: 1. Pull/versioned release. 2. Install
dependencies. 3. Run migrations safely. 4. Build assets. 5. Clear/warm
caches as appropriate. 6. Restart workers. 7. Run smoke tests. 8.
Monitor errors.

Never edit production code manually through a file manager as the normal
deployment process.

------------------------------------------------------------------------

# 29. Git & Version Control

Use Git from now on.

Branch concept: - main = production - develop = optional integration
branch - feature/* - fix/* - hotfix/\*

Commit messages should describe intent.

Do not commit: - .env - secrets - uploaded user files - generated
caches - production database dumps

Keep a CHANGELOG for important releases.

------------------------------------------------------------------------

# 30. Development Roadmap

## Phase 0 --- Architecture & Safety Foundation

Priority: CRITICAL --- **STATUS: COMPLETE (2026-08-14)**

-   [x] Review existing code structure.
-   [x] Inventory all routes/pages/forms/database tables.
-   [x] Introduce consistent folder/module structure. --- `src/Support`,
    `src/Catalog`, `src/Orders`; PSR-4 autoloading via Composer.
-   [x] Add centralized configuration. --- `config/config.php` plus
    git-ignored `config/config.local.php` for secrets.
-   [x] Add CSRF protection. --- `src/Support/Csrf.php`; token on every
    state-changing form, verified server-side (403 on mismatch).
-   [x] Convert state-changing GET actions to POST/appropriate methods. ---
    Buy Now, product archive/restore and logout are POST-only (405 on GET).
-   [x] Fix product deletion/order-history relationship. --- Migration
    `002`: order snapshots, `ON DELETE RESTRICT`, product soft delete.
-   [x] Add authorization/ownership checks. --- Single `Auth` gate;
    `requireOwnership()` for customer-scoped records.
-   [x] Add validation layer. --- `src/Support/Validator.php`, applied to
    every form.
-   [x] Improve upload validation. --- `src/Support/ImageUploader.php`;
    content-sniffed MIME, size/dimension limits, server-generated filename.
-   [x] Add database migrations/backups. --- `database/migrate.php`
    (`--status`, `--backup`) with tracked migrations.
-   [x] Add Git workflow. --- `.gitignore` excluding secrets, `vendor/`,
    logs, backups and user uploads; feature-branch workflow.
-   [x] Add baseline tests. --- 46 PHPUnit tests (unit + real-database
    feature/regression tests), all passing.

Do not add advanced features before this phase is stable.

### Deliberately deferred out of Phase 0

Recorded here so the gaps are known rather than forgotten:

-   Login rate limiting is session-backed, so it does not stop an attacker who
    discards cookies between requests. A shared IP-keyed store belongs with
    Phase 8 hardening.
-   Content-Security-Policy is not yet sent, because the existing pages rely on
    inline styles; tightening it requires extracting those first.
-   `single_order` still holds one product per row. Multi-item orders and the
    `order_items` table arrive with the cart in Phase 2/3.
-   Order status tracking, real email delivery and password reset remain
    unimplemented (Phases 3, 5).

## Phase 1 --- Catalog Foundation

**STATUS: COMPLETE (2026-08-14)**

-   [x] Categories. --- Admin CRUD at `admin/categories.php`. Deleting a
    category leaves its products on sale and uncategorised
    (`ON DELETE SET NULL`), and the confirmation states how many are affected.
-   [x] Product search. --- Server-side, LIKE wildcards escaped.
-   [x] Category filter.
-   [x] Sorting. --- Whitelisted keys mapped to SQL; an unknown key falls back
    to the default rather than reaching the query.
-   [x] Pagination.
-   [x] Multiple images. --- `product_images` table with one primary image;
    managed at `admin/productimages.php`. `products.image` is kept as a
    denormalised cache of the primary so list views need no join.
-   [x] Product slugs. --- `App\Support\Slugger`; unique per table, stable
    across edits that do not change the name. `?product_id=` redirects 301 to
    the canonical slug URL.
-   [x] Product archive/soft delete. --- Delivered in Phase 0.
-   [x] Configurable low-stock threshold. --- Store-wide default in config,
    with an optional per-product override (`products.low_stock_threshold`,
    NULL meaning "use the default").

Also delivered: a public product detail page (`product.php?slug=…`) with
gallery, breadcrumb, stock state, related products and SEO meta tags — §26
asks for this and there was previously no product page at all.

### Deferred from Phase 1

-   Renaming a product changes its URL; there is no redirect table mapping the
    old slug to the new one. Worth adding with the rest of the SEO work (§26).
-   `product_images.sort_order` exists and is respected on read, but there is
    no drag-to-reorder UI yet.

## Phase 2 --- Cart

**STATUS: COMPLETE (2026-08-14)**

-   [x] Cart table. --- `carts`, owned by either a customer (`user_id`) or an
    anonymous visitor (`token`).
-   [x] Cart items. --- `cart_items` with `UNIQUE (cart_id, product_id)`, so
    adding the same product twice increments the line instead of duplicating it.
-   [x] Add/update/remove/clear. --- All through one POST-only, CSRF-verified
    endpoint (`cartaction.php`) that redirects afterwards.
-   [x] Server-side price calculation. --- Every subtotal and total is computed
    from the live `products.price`. `cart_items.price_at_add` is stored ONLY to
    tell the customer a price moved; it never determines what they are charged.
-   [x] Stock validation. --- Checked on add and update, and re-checked under a
    `SELECT ... FOR UPDATE` row lock at checkout. The cart also surfaces
    "only N left" and blocks checkout until it is fixed, on the server as well
    as in the UI.
-   [x] Cart persistence. --- Guest carts live 30 days in an httponly cookie;
    customer carts persist in the database indefinitely.
-   [x] Guest cart decision. --- **DECIDED: guest carts are supported.**
    Requiring login before a visitor may collect items loses customers, and
    retrofitting guest support later would mean rewriting the cart rather than
    extending it. On login the guest cart is merged into the customer's cart
    (quantities summed, then clamped to available stock) and discarded.

Also delivered, because a cart nobody can check out from is not a feature:
a working transactional checkout. `OrderService::placeOrderFromCart()` locks
every product row in id order (avoiding deadlocks), re-reads every price,
re-checks stock, writes a snapshot per line, and rolls the entire order back if
any single line fails — partial success is impossible. One checkout shares one
`order_reference`, so a three-product order reads as one order.

### Deferred to Phase 3

-   `single_order` still holds one row per product. The proper
    `orders` + `order_items` restructure, with one payment row per order rather
    than per line, is Phase 3.
-   The shipping address is still the one captured at registration; the address
    book is Phase 3.
-   No order status machine yet — orders have no Pending/Shipped/Delivered
    lifecycle.
-   Adding to cart does not reserve stock. Stock is authoritative at checkout,
    which is why the cart can warn "only N left" after the fact. Reservation is
    listed as optional in §10 and would let abandoned carts block real sales.

## Phase 3 --- Checkout & Orders

**STATUS: COMPLETE (2026-08-14)**

-   [x] Address management. --- Full address book (`addresses` table,
    `addresses.php`), not a single field on the user record (§13). First
    address saved becomes the default automatically; deleting the default
    promotes another.
-   [x] Checkout page. --- Extended in this phase to select from the address
    book instead of a fixed registration address.
-   [x] Order creation transaction. --- Already transactional since Phase 2;
    now writes into `orders` + `order_items` instead of `single_order`.
-   [x] Order item snapshots. --- `order_items.product_name`/`unit_price`
    preserved.
-   [x] Order number. --- `orders.order_reference`, already introduced in
    Phase 2, now the primary key customers and admins both use to find an order.
-   [x] Order status machine. --- `App\Orders\OrderStatus` defines the legal
    transition graph explicitly (§7's example table) and rejects everything
    else, enforced in `OrderRepository::transitionStatus()` regardless of what
    the UI offers.
-   [x] Order status history. --- `order_status_history`; every transition
    records from/to status, the changed_by admin (NULL for the system), an
    optional note, and a timestamp.
-   [x] Customer order tracking. --- `orderdetail.php` renders the actual
    history log as a timeline rather than a fixed progress bar, so a
    cancelled or returned order is never shown implying a future step that
    will not happen (Rule 12).
-   [x] Admin order management. --- `admin/orders.php` (filter by status,
    search, paginate) and `admin/vieworder.php` (detail + status transition,
    dropdown limited to the legal next states).

### The restructure this phase required

`single_order` (Phase 0/2) held one row per product per checkout with no
natural home for an order-level status. This phase introduced `orders` (one
row per checkout, carrying status/address snapshot) and `order_items` (one row
per product), matching §5's recommended split. `single_order` and `payments`
are preserved **untouched** as an immutable historical record (Rule 10); a PHP
migration copied their data forward into the new tables without deleting or
rewriting the originals. The former `placeSingleProductOrder()` method and
`singleorder.php` page were removed — "Buy Now" now adds one item to the cart
and goes straight to checkout, so there is exactly one order-creation path
instead of two that could drift apart (§3.1).

### Bug found and fixed during this phase

`ProductRepository::hasOrders()` still queried the legacy `single_order`
table after the restructure, so it would have wrongly reported "no order
history" for a product only ever ordered through the new checkout. Fixed to
query `order_items`. Caught by grepping for lingering references to the
legacy table before declaring the phase done — a reminder that Rule 11
("test before declaring complete") includes checking your own comments
against what the code actually does after a rename/restructure.

### Deferred to later phases

-   `payments` stays a legacy-only table; new orders carry `payment_method`
    and a minimal `payment_status` directly, with the full payment
    abstraction (`payment_transactions`, gateway verification) remaining
    Phase 4 as planned.
-   No shipment/carrier tracking (`shipments` table) — "Shipped" is a status,
    not a tracked package.
-   Editing an existing order (address/items) after placement is not
    supported; a customer can only see and the admin can only progress it.

## Phase 4 --- Payments

**STATUS: COMPLETE for the payment methods this project actually has
credentials for (2026-08-14)**

-   [x] Payment abstraction. --- `App\Payments\PaymentGateway` interface
    (`createPayment`/`verifyPayment`/`cancelPayment`/`refundPayment`).
    `OrderService` calls only this interface; it does not know or care which
    concrete gateway is behind it.
-   [x] COD. --- `CashOnDeliveryGateway`, fully working: records a pending
    transaction at checkout, settles to paid when the order is marked
    Delivered (§7's status machine and §9's payment ledger meet exactly
    there).
-   [~] bKash integration. --- **Not built.** `UnconfiguredGateway` is
    registered behind the interface and fails loudly with a clear
    configuration error if ever invoked, rather than simulating a payment
    that was never actually charged anywhere (Rule 12 "No fake success"). No
    bKash merchant account/credentials exist for this project. Swapping in a
    real implementation later means writing one class and flipping
    `config/config.php`'s `payments.methods.bkash.enabled` to true — nothing
    in the order system changes.
-   [~] Card gateway integration. --- Same situation and same reasoning as
    bKash, via the same `UnconfiguredGateway`.
-   [x] Payment transaction records. --- `payment_transactions` table: one
    row per charge attempt, with gateway, status, amount, an idempotency key,
    and an optional gateway transaction reference.
-   [~] Webhook verification. --- **N/A while no redirect-based gateway is
    connected** — there is nothing to verify a webhook against yet. The
    `verifyPayment()` method exists on the interface for when one is added;
    building a webhook receiver now would be dead code satisfying a checklist
    item rather than doing anything (the same reasoning as not building fake
    bKash success).
-   [x] Idempotency. --- `payment_transactions.idempotency_key` carries a
    UNIQUE index; `order_reference` is reused as that key, so even a retried
    checkout transaction cannot create two charge records for one order. This
    sits alongside, not instead of, the checkout page's one-time submit token.
-   [x] Refund architecture. --- Moving an order to Returned then Refunded
    calls `PaymentGateway::refundPayment()` and records the result. For COD
    this is necessarily a ledger entry only (there is no online charge to
    reverse) with an explicit message that the cash refund is a manual,
    out-of-band process — again Rule 12: the ledger says what actually
    happened, not a fabricated "refund processed."

### Why bKash/Card were not really integrated

Both were switched off in `config/config.php` from Phase 0 onward specifically
because there were no real merchant credentials to integrate against. §9's own
instructions are conditional here — "bKash integration **if required**" — and
building a simulated successful payment for either would violate Rule 12 more
directly than leaving them unbuilt. The abstraction is complete and real
integration is a contained, additive change (one new class, one config flag)
whenever credentials exist.

## Phase 5 --- Customer Experience

**STATUS: COMPLETE except email verification, deliberately deferred
(2026-08-14)**

-   [x] Profile edit. --- `profile.php`: name/phone editable; email is not
    (it is the login identity) with a note pointing to support.
-   [x] Address book. --- Delivered in Phase 3, ahead of schedule.
-   [x] Forgot/reset password. --- `forgot-password.php` / `reset-password.php`.
    Tokens are single-use and expiring; only a SHA-256 hash is ever stored
    (§19); the response is identical whether or not the email exists, the
    same anti-enumeration pattern login.php already used.
-   [~] Email verification. --- **Deferred, not built.** §19 lists this as
    "Optional" explicitly, unlike the other items on this list. Building it
    now would mean gating login on a verification step for a store that
    cannot yet guarantee real-world mail delivery (no SMTP configured) —
    doing so would risk locking a real customer out of an account they
    genuinely registered. Revisit once `mail.mailer` is set to `smtp` with
    real credentials.
-   [x] Real email notifications. --- `App\Mail\Mailer` abstraction (§20).
    Welcome email on registration, order confirmation at checkout, order
    status-change email on every admin transition, password reset link.
    Defaults to `LogMailer` (writes to `storage/logs/mail/`, the same
    convention Laravel/Symfony ship as their local "log" driver) since no
    SMTP account exists for this project; `SmtpMailer` (via PHPMailer) sends
    for real once `config.local.php` supplies credentials — no code changes
    needed to switch.
-   [x] Wishlist. --- `wishlist_items` table, `WishlistRepository`,
    `wishlist.php`, heart-toggle buttons on the shop grid and product page.
    "Move to Cart" action. Archived products never appear in the list.
-   [x] Reviews/ratings. --- `reviews` table, `ReviewRepository`,
    submission form + display on the product page, admin moderation
    (`admin/reviews.php`: hide/restore/delete). **Eligibility is strict**: a
    customer may only review a product from a **Delivered** order they
    placed — enforced server-side even against a forged POST bypassing the
    UI, not merely hidden by not rendering the form (§19 "UI hiding is not
    security"). One review per customer per product; a second submission
    updates the existing one (§14 explicitly allows this).

### Why email verification specifically was skipped

Every other Phase 5 item was buildable to a genuinely working state without
external dependencies this project lacks. Email verification is different: its
entire value proposition — proving the address is real and reachable — is void
without functioning delivery, and gating account access on an unverifiable
step would be worse than not having the feature. The password reset flow
proves the mail pipeline itself works end-to-end (a real token was generated,
captured by LogMailer, and used to reset a real password in verification
testing); verification email is the same mechanism, deferred only because
gating login on it isn't safe yet.

## Phase 6 --- Admin & Operations

-   [ ] User management.
-   [ ] Category management.
-   [ ] Inventory management.
-   [ ] Stock movement history.
-   [ ] Staff roles/permissions.
-   [ ] Review moderation.
-   [ ] Contact/support inbox.
-   [ ] Blog CMS.
-   [ ] Audit logs.

## Phase 7 --- Analytics & Growth

-   [ ] Revenue analytics.
-   [ ] Date-range filters.
-   [ ] Sales charts.
-   [ ] Best sellers.
-   [ ] Customer metrics.
-   [ ] Coupons.
-   [ ] Promotions.
-   [ ] Abandoned-cart strategy if needed.

## Phase 8 --- Production Hardening

-   [ ] Security audit.
-   [ ] Dependency audit.
-   [ ] Performance testing.
-   [ ] Load testing.
-   [ ] Backup/restore test.
-   [ ] Monitoring.
-   [ ] Error tracking.
-   [ ] Staging environment.
-   [ ] Deployment automation.
-   [ ] Production smoke test.
-   [ ] Rollback procedure.

------------------------------------------------------------------------

# 31. Definition of Done

A feature is NOT complete just because the page works.

Every feature must satisfy:

-   Database migration/schema is correct.
-   Validation exists.
-   Authorization exists.
-   CSRF protection exists where applicable.
-   Correct HTTP method is used.
-   Business logic is in the correct service/domain layer.
-   Errors are handled.
-   Success/failure states are clear.
-   Existing features are not broken.
-   Tests exist for important business logic.
-   Audit logging exists where appropriate.
-   No secrets are exposed.
-   No duplicated business logic is introduced.
-   UI is responsive and accessible.
-   Documentation is updated if architecture changed.

------------------------------------------------------------------------

# 32. Claude Agent Operating Rules

Claude Agent MUST follow these rules for every task.

## Rule 1 --- Read this file first

Before changing code: 1. Read CLAUDE_PROJECT_RULES.md. 2. Inspect the
existing implementation. 3. Identify affected modules. 4. Identify
database impact. 5. Identify security impact. 6. Identify
backward-compatibility impact.

## Rule 2 --- Do not blindly rewrite

Do not rewrite large parts of the application unless necessary.

Prefer incremental, testable refactoring.

## Rule 3 --- Inspect before editing

Before editing: - inspect related files - inspect routes - inspect
database schema/migrations - inspect authentication/authorization -
inspect existing patterns

Do not invent files, tables, columns or functions without checking the
existing project.

## Rule 4 --- Preserve existing working functionality

A new feature must not silently remove: - authentication - admin
authorization - existing product CRUD - existing order history -
security protections

If a breaking change is required, explain it and migrate safely.

## Rule 5 --- No hardcoded business rules

Do not hardcode: - payment methods - order statuses - low-stock
thresholds - role names in scattered files - URLs - credentials - tax
rates - shipping charges - configuration values

Centralize configurable business rules.

## Rule 6 --- Never trust browser values

Never trust: - price - subtotal - discount - stock - user ID - role -
payment success - order status

Recalculate/verify on the server.

## Rule 7 --- Security before convenience

Every new endpoint must be reviewed for: - authentication -
authorization - CSRF - validation - XSS - SQL injection - IDOR - rate
limiting where appropriate - sensitive-data exposure

## Rule 8 --- Database changes require migration

Do not manually assume production schema changes.

Create migration/schema changes and document them.

## Rule 9 --- Transactions for critical workflows

Use transactions for: - order creation - stock changes tied to orders -
payment state changes - refunds - multi-table destructive operations

## Rule 10 --- Historical data is sacred

Never destroy historical order/payment/audit information because a
current product/customer record was changed.

Use snapshots, soft deletes, archives, and intentional foreign keys.

## Rule 11 --- Test before declaring complete

Run relevant tests after implementation.

If tests cannot be run, clearly state why.

## Rule 12 --- No fake success

Do not show "success" unless the actual operation succeeded.

## Rule 13 --- Keep documentation current

If a structural decision changes: - update this file - update schema
documentation if present - update API/docs if applicable

## Rule 14 --- Keep changes focused

One task should not become an excuse to refactor unrelated code.

## Rule 15 --- Explain important decisions

For significant architecture decisions, add a short
comment/documentation note explaining WHY, not only WHAT.

------------------------------------------------------------------------

# 33. Claude Task Workflow

For every request, Claude should follow this sequence:

### Step A --- Understand

State: - requested feature - affected modules - assumptions

### Step B --- Inspect

Inspect: - relevant files - routes - database - authentication - related
services/components - tests

### Step C --- Plan

Create a small implementation plan.

### Step D --- Implement

Make the smallest clean change that satisfies the requirement.

### Step E --- Secure

Review authorization, validation, CSRF, SQL, XSS, file uploads and race
conditions as applicable.

### Step F --- Test

Run targeted tests first, then broader tests where appropriate.

### Step G --- Review

Check: - duplication - naming - error handling - performance - backward
compatibility - security

### Step H --- Document

Update documentation when needed.

### Step I --- Report

At the end, report: - files changed - database changes - tests run -
important security considerations - remaining TODOs

------------------------------------------------------------------------

# 34. Priority Rules

When there is a conflict, prioritize:

1.  Security
2.  Data integrity
3.  Correct business behavior
4.  Authorization/privacy
5.  Reliability
6.  Maintainability
7.  Performance
8.  UX
9.  Visual polish

Never sacrifice data integrity or security merely to make a feature
faster to implement.

------------------------------------------------------------------------

# 35. Future Expansion Compatibility

Design so the project can later support:

-   Flutter/mobile API
-   multiple payment gateways
-   multiple shipping providers
-   coupons/promotions
-   multiple warehouses
-   product variants
-   multiple admins/staff
-   customer notifications
-   external analytics
-   search engine
-   object storage/CDN
-   queue workers
-   multi-language
-   multi-currency
-   tax calculation
-   returns/refunds
-   loyalty/rewards

Do not implement these prematurely. Build clean boundaries so they can
be added later.

------------------------------------------------------------------------

# 36. Final Production Gate

The application should not be called production-ready until:

-   [ ] Cart works reliably.
-   [ ] Checkout is transactional.
-   [ ] Payment handling is verified.
-   [ ] Orders have controlled status transitions.
-   [ ] Inventory is race-condition safe.
-   [ ] Admin can manage orders.
-   [ ] Customers can track orders.
-   [ ] CSRF is implemented.
-   [ ] No state-changing GET endpoints remain.
-   [ ] IDOR/authorization issues are checked.
-   [ ] Upload validation is strong.
-   [ ] Password reset works securely.
-   [ ] Email notifications work reliably.
-   [ ] Database backups exist.
-   [ ] Restore has been tested.
-   [ ] Error logging/monitoring exists.
-   [ ] Automated tests cover critical flows.
-   [ ] Staging environment exists.
-   [ ] Deployment is repeatable.
-   [ ] Secrets are externalized.
-   [ ] HTTPS is enabled.
-   [ ] Production smoke tests pass.
-   [ ] Rollback procedure exists.

------------------------------------------------------------------------

# 37. Most Important Implementation Order

Do NOT start with reviews, wishlist, blog, charts or cosmetic
improvements.

Recommended immediate order:

1.  Architecture/code audit.
2.  CSRF + HTTP method security.
3.  Database relationship/order-history fix.
4.  Validation + upload security.
5.  Category/catalog/search/filter/pagination.
6.  Cart.
7.  Checkout.
8.  Order status/state machine.
9.  Admin order management.
10. Inventory movement + stock safety.
11. Payment abstraction.
12. COD.
13. bKash/card integration.
14. Customer profile/address/password reset.
15. Email/notifications.
16. Reviews.
17. Wishlist.
18. Blog/CMS.
19. Contact/support.
20. Roles/permissions.
21. Reports/analytics.
22. Audit logs.
23. Tests/security audit.
24. Production deployment hardening.

This order minimizes rework because checkout, orders, inventory and
payments form the core transactional architecture.

------------------------------------------------------------------------

# 38. Permanent Instruction

Claude Agent must treat this file as the project's engineering
constitution.

Before implementing any new feature, ask:

"Does this change preserve security, data integrity, modularity,
testability, and future extensibility?"

If the answer is no, redesign the implementation before coding.

When a requirement conflicts with this document, do not silently ignore
the conflict. Explain the conflict and propose the safest architecture.
