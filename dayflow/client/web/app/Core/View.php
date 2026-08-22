<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renders a page.
 *
 * Views are plain PHP templates. The guarantee that matters is the helper set
 * in helpers.php: `e()` escapes, and every template uses it for anything that
 * came from the database or from a form. Nothing in this application writes an
 * unescaped value into a page.
 */
final class View
{
    private static array $shared = [];

    /**
     * Renders a template inside the application layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): void
    {
        $content = self::capture($template, $data);

        $layoutData = $data + self::$shared + [
            'content' => $content,
            'pageTitle' => $data['pageTitle'] ?? 'Dayflow',
            'breadcrumbs' => $data['breadcrumbs'] ?? [],
            'activeNav' => $data['activeNav'] ?? '',
        ];

        self::output('layouts/app', $layoutData);
        Flash::clearInput();
    }

    /**
     * Renders a template inside the bare layout used by sign-in and sign-up.
     *
     * @param array<string, mixed> $data
     */
    public static function renderAuth(string $template, array $data = []): void
    {
        $content = self::capture($template, $data);

        self::output('layouts/auth', $data + self::$shared + [
            'content' => $content,
            'pageTitle' => $data['pageTitle'] ?? 'Dayflow',
        ]);

        Flash::clearInput();
    }

    /**
     * Includes a partial from within a template.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $template, array $data = []): void
    {
        self::output('partials/' . $template, $data + self::$shared);
    }

    /** @param array<string, mixed> $data */
    public static function share(array $data): void
    {
        self::$shared = array_merge(self::$shared, $data);
    }

    /** @param array<string, mixed> $data */
    private static function capture(string $template, array $data): string
    {
        ob_start();
        self::output($template, $data + self::$shared);

        return (string) ob_get_clean();
    }

    /**
     * Loads and executes a template.
     *
     * The path is validated and resolved against the views directory so a
     * template name can never escape it, even if one were ever derived from
     * user input.
     *
     * @param array<string, mixed> $data
     */
    private static function output(string $template, array $data): void
    {
        if (preg_match('#^[A-Za-z0-9_/-]+$#', $template) !== 1) {
            throw new \InvalidArgumentException('Invalid view name.');
        }

        $base = realpath(__DIR__ . '/../Views');
        $path = realpath($base . '/' . $template . '.php');

        if ($base === false || $path === false || !str_starts_with($path, $base)) {
            http_response_code(500);
            echo '<h1>View not found</h1>';

            return;
        }

        extract($data, EXTR_SKIP);

        require $path;
    }
}
