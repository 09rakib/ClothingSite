<?php

declare(strict_types=1);

/**
 * Cart mutations: add, update quantity, remove, clear.
 *
 * One POST-only endpoint for every cart change (PROJECT_RULES.md §19 "Never
 * delete/order/create via GET links"). It performs the action and redirects,
 * so a refresh never repeats the mutation.
 *
 * Guests may use the cart: an anonymous visitor gets a random cart token in an
 * httponly cookie, and that cart is merged into their account when they log in.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Cart\CartService;
use App\Support\Auth;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Logger;
use App\Support\Validator;

Http::requirePost();   // Method enforcement + CSRF verification.

// Admins have no storefront cart; send them to their own area.
if (Auth::check() && Auth::isAdmin()) {
    Http::redirect('admin/seller.php');
}

$cart   = new CartService();
$action = (string) ($_POST['action'] ?? '');

// Where to go afterwards. Only same-site relative paths from a fixed list are
// accepted, so this cannot be turned into an open redirect.
$allowedReturns = ['cart.php', 'shop.php', 'index.php', 'checkout.php'];
$return         = (string) ($_POST['return'] ?? 'cart.php');

if (!in_array($return, $allowedReturns, true)) {
    // A product page return carries a slug, so it is rebuilt rather than
    // taken from the request verbatim.
    $slug   = (string) ($_POST['return_slug'] ?? '');
    $return = $slug !== '' ? 'product.php?slug=' . urlencode($slug) : 'cart.php';
}

try {
    switch ($action) {
        case 'add':
            $productId = Http::intParam($_POST, 'product_id');
            if ($productId === null) {
                throw new RuntimeException('No product was selected.');
            }

            $validator = (new Validator($_POST))
                ->label('quantity', 'Quantity')
                ->integer('quantity', 1, 100);

            if ($validator->fails()) {
                throw new RuntimeException($validator->firstError());
            }

            $quantity = (int) ($validator->value('quantity') ?: 1);
            $cart->add($productId, $quantity);

            Flash::success('Added to your cart.');
            break;

        case 'update':
            $itemId = Http::intParam($_POST, 'item_id');
            if ($itemId === null) {
                throw new RuntimeException('No cart item was selected.');
            }

            $validator = (new Validator($_POST))
                ->label('quantity', 'Quantity')
                ->required('quantity')
                ->integer('quantity', 0, 100);

            if ($validator->fails()) {
                throw new RuntimeException($validator->firstError());
            }

            $quantity = (int) $validator->value('quantity');
            $cart->updateQuantity($itemId, $quantity);

            Flash::success($quantity < 1 ? 'Item removed from your cart.' : 'Cart updated.');
            break;

        case 'remove':
            $itemId = Http::intParam($_POST, 'item_id');
            if ($itemId === null) {
                throw new RuntimeException('No cart item was selected.');
            }

            $cart->remove($itemId);
            Flash::success('Item removed from your cart.');
            break;

        case 'clear':
            $cart->clear();
            Flash::success('Your cart has been emptied.');
            break;

        default:
            throw new RuntimeException('Unknown cart action.');
    }
} catch (RuntimeException $e) {
    // Messages thrown by CartService are written to be safe for customers.
    Flash::error($e->getMessage());
} catch (Throwable $e) {
    Logger::error('Cart action failed', [
        'action'  => $action,
        'user_id' => Auth::id(),
        'error'   => $e->getMessage(),
    ]);
    Flash::error('Something went wrong updating your cart. Please try again.');
}

Http::redirect($return);
