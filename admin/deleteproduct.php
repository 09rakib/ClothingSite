<?php

declare(strict_types=1);

/**
 * Admin — archive or restore a product.
 *
 * THIS NO LONGER DELETES ROWS, and that is deliberate
 * (PROJECT_RULES.md §6.1, §6.2, Rule 10 "Historical data is sacred").
 *
 * Previously this was a GET link running `DELETE FROM products`. Because
 * single_order referenced products with ON DELETE CASCADE, removing one
 * product silently destroyed every order that contained it along with the
 * matching payment rows — a customer's history could vanish because an admin
 * tidied up the catalog.
 *
 * Now: the request must be a CSRF-verified POST, and "delete" means archive.
 * The product disappears from the storefront, its orders stay intact, and the
 * action is reversible.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Catalog\ProductRepository;
use App\Support\Auth;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;

Http::requirePost();     // Method enforcement + CSRF verification.
Auth::requireAdmin();    // Same single definition of "admin" as every other page.

$productId = Http::intParam($_POST, 'product_id');
$action    = (string) ($_POST['action'] ?? 'archive');

if ($productId === null) {
    Flash::error('No product was selected.');
    Http::redirect('displayproduct.php');
}

$repository = new ProductRepository();
$product    = $repository->find($productId);

if ($product === null) {
    Flash::error('That product no longer exists.');
    Http::redirect('displayproduct.php');
}

if ($action === 'restore') {
    $repository->restore($productId);
    Logger::info('Product restored', ['product_id' => $productId, 'admin_id' => Auth::id()]);
    Flash::success(sprintf('"%s" is back on sale.', $product['name']));
} else {
    $repository->archive($productId);
    Logger::info('Product archived', ['product_id' => $productId, 'admin_id' => Auth::id()]);

    // Tell the admin plainly that history was preserved, so "delete" not
    // wiping the row is understood as intentional rather than a bug.
    Flash::success(sprintf(
        '"%s" has been archived and removed from the storefront. Past orders keep their history.',
        $product['name']
    ));
}

Http::redirect('displayproduct.php');
