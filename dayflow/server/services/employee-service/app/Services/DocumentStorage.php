<?php

declare(strict_types=1);

namespace App\Services;

use Dayflow\Kernel\Http\HttpException;
use Dayflow\Kernel\Support\Env;
use Dayflow\Kernel\Support\Str;

/**
 * Accepts, validates and stores uploaded files.
 *
 * An upload endpoint is the easiest way to get code onto a server, so every
 * one of these checks is load bearing:
 *
 *  - the extension must be on a short allow-list;
 *  - the real content type is sniffed with finfo and must agree with that
 *    extension, which rejects a script renamed to .png;
 *  - images are decoded far enough to confirm they really are images;
 *  - an Office document is opened as the ZIP container it actually is and must
 *    contain the part that makes it a document rather than an arbitrary
 *    archive;
 *  - the file is written under STORAGE_PATH, which sits outside the document
 *    root and is refused by the web server, under a generated name that
 *    carries no user-supplied text at all.
 *
 * The original name survives only as a database column, for display.
 */
final class DocumentStorage
{
    public const DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /**
     * Content types accepted for each permitted extension.
     *
     * A .docx is a ZIP archive, so finfo reports it as one on many systems.
     * That ambiguity is closed by inspecting the archive itself below rather
     * than by widening what counts as a document.
     *
     * @var array<string, list<string>>
     */
    private const EXTENSION_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => [self::DOCX_MIME, 'application/zip'],
    ];

    /** The type recorded for a file, regardless of what finfo happened to say. */
    private const CANONICAL_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'docx' => self::DOCX_MIME,
    ];

    private const IMAGE_SIGNATURES = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
    ];

    public function maxBytes(): int
    {
        return max(1, Env::int('MAX_UPLOAD_BYTES', 5_242_880));
    }

    /**
     * Validates one uploaded file and moves it into permanent storage.
     *
     * @param array<string, mixed>|null $file      One entry from $_FILES.
     * @param list<string>              $permitted Extensions this endpoint accepts.
     * @return array{original_filename: string, stored_filename: string, mime_type: string, size_bytes: int, checksum: string}
     */
    public function store(?array $file, array $permitted): array
    {
        $temporaryPath = $this->assertUsableUpload($file);
        $originalName = $this->safeOriginalName((string) ($file['name'] ?? ''));
        $extension = $this->assertPermittedExtension($originalName, $permitted);

        $this->assertWithinSizeLimit((int) ($file['size'] ?? 0), $temporaryPath);
        $this->assertContentMatchesExtension($temporaryPath, $extension);

        $checksum = hash_file('sha256', $temporaryPath);
        if ($checksum === false) {
            throw HttpException::unprocessable('The uploaded file could not be read.');
        }

        $storedName = Str::uuid() . '.' . $extension;
        $destination = $this->directory() . '/' . $storedName;

        if (!move_uploaded_file($temporaryPath, $destination)) {
            throw new HttpException(500, 'The uploaded file could not be saved.', 'storage_failed');
        }

        // Readable by the web server account only; nothing needs to execute it.
        chmod($destination, 0640);

        return [
            'original_filename' => $originalName,
            'stored_filename' => $storedName,
            'mime_type' => self::CANONICAL_TYPES[$extension],
            'size_bytes' => (int) filesize($destination),
            'checksum' => $checksum,
        ];
    }

    /** Returns the bytes of a stored file for streaming to an authorised caller. */
    public function read(string $storedFilename): string
    {
        $path = $this->resolve($storedFilename);

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new HttpException(500, 'The stored file could not be read.', 'storage_failed');
        }

        return $contents;
    }

    /**
     * Removes a stored file.
     *
     * A missing file is not an error: the database row is the record of truth
     * and deleting it must not be blocked by a file that has already gone.
     */
    public function remove(string $storedFilename): void
    {
        try {
            $path = $this->resolve($storedFilename);
        } catch (HttpException) {
            return;
        }

        unlink($path);
    }

    /**
     * Maps a stored filename onto a real path inside the upload directory.
     *
     * The name is generated by this class, but it is re-validated on the way
     * out so that a tampered database row still cannot reach another
     * directory.
     */
    private function resolve(string $storedFilename): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]{2,5}$/', $storedFilename) !== 1) {
            throw HttpException::notFound('The stored file could not be located.');
        }

        $base = $this->directory();
        $path = $base . '/' . $storedFilename;
        $real = realpath($path);

        if ($real === false || !str_starts_with(str_replace('\\', '/', $real), $base . '/')) {
            throw HttpException::notFound('The stored file could not be located.');
        }

        return $real;
    }

    /** @param array<string, mixed>|null $file */
    private function assertUsableUpload(?array $file): string
    {
        if ($file === null || !isset($file['tmp_name'], $file['error']) || is_array($file['tmp_name'])) {
            throw HttpException::unprocessable('A file must be attached to this request.', [
                'file' => ['A file is required.'],
            ]);
        }

        $error = (int) $file['error'];

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::unprocessable(sprintf(
                'That file is larger than the %d byte limit.',
                $this->maxBytes()
            ));
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::unprocessable('The file did not upload correctly. Please try again.');
        }

        $temporaryPath = (string) $file['tmp_name'];

        // Refuses anything that was not delivered by PHP as a real upload,
        // which is what stops an arbitrary server path being named instead.
        if (!is_uploaded_file($temporaryPath)) {
            throw HttpException::badRequest('The upload could not be verified.');
        }

        return $temporaryPath;
    }

    private function safeOriginalName(string $name): string
    {
        // Only the leaf name is kept, and only characters that are safe in a
        // Content-Disposition header and in a log line.
        $leaf = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', $leaf) ?? '';
        $clean = trim($clean);

        if ($clean === '' || $clean === '.' || $clean === '..') {
            throw HttpException::unprocessable('The file needs a usable name.');
        }

        return mb_substr($clean, 0, 200);
    }

    /** @param list<string> $permitted */
    private function assertPermittedExtension(string $originalName, array $permitted): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === '' || !in_array($extension, $permitted, true)) {
            throw HttpException::unprocessable(sprintf(
                'Only %s files are accepted here.',
                implode(', ', $permitted)
            ), ['file' => ['Unsupported file type.']]);
        }

        return $extension;
    }

    private function assertWithinSizeLimit(int $reportedSize, string $path): void
    {
        $actual = filesize($path);
        $size = $actual === false ? $reportedSize : $actual;

        if ($size <= 0) {
            throw HttpException::unprocessable('The file is empty.');
        }

        if ($size > $this->maxBytes()) {
            throw HttpException::unprocessable(sprintf(
                'That file is %d bytes; the limit is %d.',
                $size,
                $this->maxBytes()
            ));
        }
    }

    private function assertContentMatchesExtension(string $path, string $extension): void
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new HttpException(500, 'The uploaded file could not be inspected.', 'storage_failed');
        }

        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);

        if ($detected === false || !in_array($detected, self::EXTENSION_TYPES[$extension], true)) {
            throw HttpException::unprocessable(
                'The contents of that file do not match its name.',
                ['file' => ['Unsupported file type.']]
            );
        }

        if (isset(self::IMAGE_SIGNATURES[$extension])) {
            $this->assertRealImage($path, self::IMAGE_SIGNATURES[$extension]);
        }

        if ($extension === 'docx') {
            $this->assertRealWordDocument($path);
        }
    }

    private function assertRealImage(string $path, int $expectedType): void
    {
        $info = @getimagesize($path);

        if ($info === false || ($info[2] ?? 0) !== $expectedType) {
            throw HttpException::unprocessable(
                'That image could not be read.',
                ['file' => ['Unsupported file type.']]
            );
        }
    }

    /**
     * Confirms a .docx really is a word-processing package.
     *
     * finfo cannot tell a document from any other ZIP, so the container is
     * opened and the parts that define the format are looked for by name.
     */
    private function assertRealWordDocument(string $path): void
    {
        $archive = new \ZipArchive();

        if ($archive->open($path, \ZipArchive::RDONLY) !== true) {
            throw HttpException::unprocessable(
                'That document could not be read.',
                ['file' => ['Unsupported file type.']]
            );
        }

        $hasContentTypes = $archive->locateName('[Content_Types].xml') !== false;
        $hasDocumentPart = $archive->locateName('word/document.xml') !== false;
        $archive->close();

        if (!$hasContentTypes || !$hasDocumentPart) {
            throw HttpException::unprocessable(
                'The contents of that file do not match its name.',
                ['file' => ['Unsupported file type.']]
            );
        }
    }

    /** Creates the upload directory on first use and returns its real path. */
    private function directory(): string
    {
        $base = rtrim(str_replace('\\', '/', Env::get('STORAGE_PATH', '/var/www/storage')), '/');
        $path = $base . '/uploads/documents';

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
