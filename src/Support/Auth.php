<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Authentication and authorization gate (PROJECT_RULES.md §19, §17).
 *
 * WHY: the role check `$_SESSION['user_role'] !== 'admin'` was duplicated in
 * admin-header.php and deleteproduct.php. Duplicated auth checks drift — one
 * gets updated, the other silently keeps the old rule. All checks now route
 * through this class so there is a single definition of "is an admin".
 *
 * UI hiding is not security: every protected page calls requireAdmin() /
 * requireCustomer() on the server, regardless of which links are rendered.
 */
final class Auth
{
    public const ROLE_ADMIN    = 'admin';
    public const ROLE_CUSTOMER = 'user';

    public static function check(): bool
    {
        return Session::get('user_id') !== null;
    }

    public static function id(): ?int
    {
        $id = Session::get('user_id');

        return $id === null ? null : (int) $id;
    }

    public static function name(): string
    {
        return (string) Session::get('user_name', '');
    }

    public static function role(): ?string
    {
        $role = Session::get('user_role');

        return $role === null ? null : (string) $role;
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ROLE_ADMIN;
    }

    public static function isCustomer(): bool
    {
        return self::role() === self::ROLE_CUSTOMER;
    }

    /**
     * Establish an authenticated session.
     *
     * Regenerating the id prevents session fixation, and rotating the CSRF
     * token stops a pre-login token from being replayed after login.
     */
    public static function login(int $id, string $name, string $role): void
    {
        Session::start();
        session_regenerate_id(true);

        Session::set('user_id', $id);
        Session::set('user_name', $name);
        Session::set('user_role', $role);

        Csrf::rotate();
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    /**
     * Require any authenticated user, else send them to the login page.
     */
    public static function requireLogin(string $loginPath = 'login.php'): void
    {
        if (!self::check()) {
            Http::redirect($loginPath);
        }
    }

    /**
     * Require an admin. Non-admins are redirected, never shown the page.
     */
    public static function requireAdmin(string $loginPath = '../login.php'): void
    {
        if (!self::check() || !self::isAdmin()) {
            Logger::warning('Blocked non-admin access to admin area', [
                'user_id' => self::id(),
                'uri'     => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            Http::redirect($loginPath);
        }
    }

    /**
     * Require a customer account. Admins are sent to their own dashboard
     * because customer pages (cart, orders) are not meaningful for them.
     */
    public static function requireCustomer(): void
    {
        if (!self::check()) {
            Http::redirect('login.php');
        }
        if (self::isAdmin()) {
            Http::redirect('admin/seller.php');
        }
    }

    /**
     * Ownership check used to prevent IDOR (§19 "No IDOR vulnerabilities").
     *
     * Any customer-scoped record must be verified against the session user
     * before it is displayed — never trust an id taken from the URL.
     */
    public static function requireOwnership(int $ownerId): void
    {
        if (self::id() !== $ownerId) {
            Logger::warning('Blocked cross-account resource access', [
                'session_user' => self::id(),
                'owner'        => $ownerId,
                'uri'          => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            http_response_code(403);
            exit('You are not allowed to view this resource.');
        }
    }
}
