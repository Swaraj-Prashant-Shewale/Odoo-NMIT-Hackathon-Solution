<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Http\Response;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/**
 * Display pictures for accounts.
 *
 * An uploaded file is the least trustworthy thing a service handles, so what
 * arrives is treated as bytes of unknown origin until proved otherwise: the
 * extension is ignored, the real image type is read from the file itself, and
 * the stored name is generated here rather than taken from the upload. A file
 * called "photo.jpg.php" therefore cannot be stored, addressed or served.
 */
final class AvatarStorage
{
    /** What a display picture may be, by the type the image itself reports. */
    private const ALLOWED = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    private const MAX_BYTES = 3 * 1024 * 1024;

    /**
     * Stores an upload and returns the name to record against the account.
     *
     * @param array<string, mixed>|null $file One entry from $_FILES.
     */
    public function store(string $userId, ?array $file): string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::unprocessable(
                'Please choose an image to upload.',
                ['avatar' => ['No file was received.']]
            );
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_file($temporary)) {
            throw HttpException::unprocessable('That upload did not arrive intact.');
        }

        if (filesize($temporary) > self::MAX_BYTES) {
            throw HttpException::unprocessable(
                'That image is too large. Please use one under 3 MB.',
                ['avatar' => ['Maximum size is 3 MB.']]
            );
        }

        // getimagesize reads the file's own header. An extension is a claim
        // made by whoever uploaded it and is not consulted.
        $probe = @getimagesize($temporary);
        $type = is_array($probe) ? ($probe[2] ?? null) : null;

        if (!is_int($type) || !isset(self::ALLOWED[$type])) {
            throw HttpException::unprocessable(
                'That file is not an image the platform can display.',
                ['avatar' => ['Use a JPEG, PNG, GIF or WebP image.']]
            );
        }

        [$extension] = self::ALLOWED[$type];

        // Named from the account and a fresh random part: predictable enough to
        // find, unpredictable enough that one account's picture cannot be
        // guessed from another's.
        $name = $userId . '-' . Str::token(8) . '.' . $extension;
        $directory = $this->directory();

        if (!@move_uploaded_file($temporary, $directory . '/' . $name)
            && !@rename($temporary, $directory . '/' . $name)
        ) {
            throw new HttpException(500, 'The picture could not be saved.', 'storage_failed');
        }

        @chmod($directory . '/' . $name, 0640);

        return $name;
    }

    /** Serves one, inline, with the type read back from the stored bytes. */
    public function stream(string $storedName): Response
    {
        $path = $this->resolve($storedName);
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw HttpException::notFound('That picture could not be read.');
        }

        $probe = @getimagesize($path);
        $type = is_array($probe) ? ($probe[2] ?? null) : null;
        $mime = is_int($type) && isset(self::ALLOWED[$type]) ? self::ALLOWED[$type][1] : 'application/octet-stream';

        return Response::binary(200, $contents, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline',
            // Belt and braces: even if something unexpected were stored, the
            // browser is told not to guess at what it is.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Deletes one. A missing file is not an error; the goal is that it is gone. */
    public function forget(string $storedName): void
    {
        try {
            @unlink($this->resolve($storedName));
        } catch (HttpException) {
            // Already absent, or never resolvable. Either way, nothing to do.
        }
    }

    /**
     * Turns a stored name into a path inside the avatar directory.
     *
     * The name is checked against the pattern this class generates rather than
     * merely sanitised, so nothing that did not come from store() can address a
     * file at all - and the resolved path is confirmed to sit inside the
     * directory before it is opened.
     */
    private function resolve(string $storedName): string
    {
        if (preg_match('/^[0-9a-f-]{36}-[A-Za-z0-9_-]{6,64}\.(jpg|png|gif|webp)$/', $storedName) !== 1) {
            throw HttpException::notFound('That picture could not be located.');
        }

        $directory = $this->directory();
        $path = realpath($directory . '/' . $storedName);

        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), $directory . '/')) {
            throw HttpException::notFound('That picture could not be located.');
        }

        return $path;
    }

    private function directory(): string
    {
        $base = rtrim(str_replace('\\', '/', Env::get('STORAGE_PATH', '/var/www/storage')), '/');
        $path = $base . '/uploads/avatars';

        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new HttpException(500, 'File storage is not available.', 'storage_failed');
        }

        $real = realpath($path);

        if ($real === false) {
            throw new HttpException(500, 'File storage is not available.', 'storage_failed');
        }

        return rtrim(str_replace('\\', '/', $real), '/');
    }
}
