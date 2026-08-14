<?php

declare(strict_types=1);

/**
 * Wishlist add/remove — one POST-only, CSRF-verified endpoint
 * (PROJECT_RULES.md §19 "Never delete/order/create via GET links"), mirroring
 * cartaction.php's shape.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Flash;
use App\Support\Http;
use App\Wishlist\WishlistRepository;

Http::requirePost();
Auth::requireCustomer();

$productId = Http::intParam($_POST, 'product_id');
$action    = (string) ($_POST['action'] ?? 'add');

$allowedReturns = ['wishlist.php', 'shop.php', 'index.php'];
$return         = (string) ($_POST['return'] ?? 'wishlist.php');

if (!in_array($return, $allowedReturns, true)) {
    $slug   = (string) ($_POST['return_slug'] ?? '');
    $return = $slug !== '' ? 'product.php?slug=' . urlencode($slug) : 'wishlist.php';
}

if ($productId !== null) {
    $wishlist = new WishlistRepository();

    if ($action === 'remove') {
        $wishlist->remove((int) Auth::id(), $productId);
        Flash::success('Removed from your wishlist.');
    } else {
        $wishlist->add((int) Auth::id(), $productId);
        Flash::success('Added to your wishlist.');
    }
}

Http::redirect($return);
