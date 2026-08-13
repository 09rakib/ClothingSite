<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One-request flash messages.
 *
 * WHY: converting destructive actions from GET to POST means those endpoints
 * now redirect after they finish (POST/Redirect/GET), so the success or error
 * message has to survive exactly one redirect. It also stops a browser refresh
 * from re-submitting the form.
 *
 * Messages are escaped at render time, never trusted as HTML.
 */
final class Flash
{
    private const KEY = '_flash';

    public const SUCCESS = 'success';
    public const ERROR   = 'error';

    public static function success(string $message): void
    {
        self::add(self::SUCCESS, $message);
    }

    public static function error(string $message): void
    {
        self::add(self::ERROR, $message);
    }

    public static function add(string $type, string $message): void
    {
        Session::start();
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Read and clear all pending messages.
     *
     * @return array<int,array{type:string,message:string}>
     */
    public static function pull(): array
    {
        Session::start();
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        return is_array($messages) ? $messages : [];
    }

    /**
     * Render pending messages as escaped alert markup.
     */
    public static function render(): string
    {
        $html = '';
        foreach (self::pull() as $flash) {
            $class = $flash['type'] === self::SUCCESS ? 'alert-success' : 'alert-error';
            $html .= sprintf(
                '<div class="alert %s" role="alert">%s</div>',
                $class,
                htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8')
            );
        }

        return $html;
    }
}
