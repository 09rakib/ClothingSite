# Shirt & Pant Store

A university-level clothing e-commerce web application built with **PHP** and **MySQL**. Customers can browse products and buy them with cash-on-delivery checkout; admins can manage the product catalog from a separate seller panel.

## Tech Stack

- PHP 8 (procedural, `mysqli` with prepared statements)
- MySQL / MariaDB
- HTML5, CSS3 (single shared stylesheet, no framework)
- Vanilla JavaScript for client-side form validation

## Features

**Customer**
- Browse products on the Home and Shop pages
- Register / login (passwords stored as bcrypt hashes)
- Buy a product with one click (cash-on-delivery)
- View personal order history

**Admin (Seller Panel)**
- Dashboard with quick stats (products, orders, revenue, low stock)
- Add, update, and delete products with image upload
- View all products with category, price, and stock

**Static pages**
- About, Blog, Contact — give the site a complete, professional feel

## Project Structure

```
ClothingSite/
├── admin/                 Seller panel (product CRUD, dashboard)
├── assets/
│   ├── css/style.css      Single shared stylesheet
│   └── images/            Product images + login banner
├── includes/
│   ├── db.php              Database connection
│   ├── header.php / footer.php            Shared layout for public pages
│   └── admin-header.php / admin-footer.php  Shared layout for the admin panel
├── database.sql           Schema + demo data
├── index.php               Home page
├── shop.php                 Full product catalog
├── login.php / register.php / logout.php
├── myorder.php              Customer order history
├── singleorder.php          Buy-now handler
└── about.php / blog.php / contact.php
```

## Setup

1. Start Apache + MySQL (e.g. via XAMPP/Laragon).
2. Create an empty database, then import `database.sql` into it — this creates the `onlineshopdb` database, all tables, and demo data.
3. Check `includes/db.php` matches your MySQL credentials (default: host `localhost`, user `root`, no password).
4. Place the project folder inside your server's web root and open it in the browser.

### Demo accounts (from `database.sql`)

| Role     | Email               | Password      |
|----------|---------------------|---------------|
| Admin    | admin@shop.com       | Admin@123     |
| Customer | customer@shop.com    | Customer@123  |

## Notes on the Security Fixes

The original version of this project had two issues that are common in early student projects but worth calling out (and are now fixed):

1. **SQL Injection** — queries used to build SQL by directly concatenating `$_POST`/`$_GET` values into strings. Every query now uses **prepared statements** (`mysqli->prepare()` + `bind_param()`), so user input can never be interpreted as SQL.
2. **Plain-text passwords** — passwords used to be compared with `===`. They are now hashed with `password_hash()` on registration and checked with `password_verify()` on login. If you still have old accounts from before this change, they will need to re-register, since their stored passwords aren't hashed.

## Author

Iftakher Ahmed Rakib
