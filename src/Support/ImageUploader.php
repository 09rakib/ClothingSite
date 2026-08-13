<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Hardened product image upload (PROJECT_RULES.md §19 "Upload security", Phase 0).
 *
 * The previous implementation only checked that a filename was present and
 * then trusted `$_FILES['image']['name']`, relying on the HTML `accept`
 * attribute — which is client-side and trivially bypassed. Any file type could
 * be written into a web-served directory.
 *
 * This class instead:
 *   - verifies the PHP upload error code first;
 *   - confirms the file really was uploaded (is_uploaded_file);
 *   - enforces a maximum size;
 *   - sniffs the real MIME type from file *content* via finfo, ignoring the
 *     client-supplied type;
 *   - confirms the bytes decode as an image and fall within sane dimensions;
 *   - generates the stored filename server-side, so a hostile name such as
 *     "../../shell.php" can never influence the path or extension.
 */
final class ImageUploader
{
    /**
     * Validate and store an uploaded image.
     *
     * @param array $file One entry from $_FILES.
     * @return string The generated filename to persist in the database.
     * @throws RuntimeException with a user-safe message when validation fails.
     */
    public static function store(array $file): string
    {
        self::assertUploadOk($file);

        $maxBytes = (int) Config::get('uploads.max_bytes', 2097152);
        $size     = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new RuntimeException('The uploaded image is empty.');
        }
        if ($size > $maxBytes) {
            throw new RuntimeException(sprintf(
                'Image is too large. Maximum allowed size is %d MB.',
                (int) round($maxBytes / 1048576)
            ));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');

        // Guards against a caller being tricked into treating an arbitrary
        // server path as an upload.
        if ($tmpPath === '' || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpPath))) {
            throw new RuntimeException('The uploaded file could not be verified.');
        }

        $extension = self::resolveExtension($tmpPath);
        self::assertDimensions($tmpPath);

        // Filename is generated entirely server-side — the client name is
        // never used, so path traversal and double extensions are impossible.
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;

        $directory = (string) Config::require('uploads.product_image_dir');
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Upload directory is not available.');
        }

        $destination = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $moved = PHP_SAPI === 'cli'
            ? rename($tmpPath, $destination)
            : move_uploaded_file($tmpPath, $destination);

        if (!$moved) {
            Logger::error('Failed to move uploaded image', ['destination' => $destination]);
            throw new RuntimeException('Image upload failed. Please try again.');
        }

        // Never leave uploads executable.
        @chmod($destination, 0644);

        return $filename;
    }

    /**
     * Delete a previously stored product image.
     *
     * WHY the basename guard: the stored value comes from the database, but
     * treating it as a bare filename means a corrupted row can still never
     * cause a delete outside the uploads directory.
     */
    public static function delete(?string $filename): void
    {
        if ($filename === null || trim($filename) === '') {
            return;
        }

        $safe = basename(trim($filename));
        if ($safe === '' || $safe === '.' || $safe === '..') {
            return;
        }

        $path = rtrim((string) Config::require('uploads.product_image_dir'), '/\\')
            . DIRECTORY_SEPARATOR . $safe;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * True when the form actually carried a file (vs. an untouched file input).
     */
    public static function wasProvided(?array $file): bool
    {
        return $file !== null
            && isset($file['error'])
            && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Translate PHP's upload error codes into messages a user can act on.
     */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        throw new RuntimeException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                        => 'The image was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE                        => 'Please choose an image to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the image. Please contact support.',
            UPLOAD_ERR_EXTENSION                      => 'The upload was blocked by the server.',
            default                                   => 'The image could not be uploaded.',
        });
    }

    /**
     * Determine the extension from the file's real content, not its name.
     */
    private static function resolveExtension(string $tmpPath): string
    {
        /** @var array<string,string> $allowed */
        $allowed = (array) Config::require('uploads.allowed_mime');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('The server cannot verify image types right now.');
        }

        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!is_string($mime) || !isset($allowed[$mime])) {
            throw new RuntimeException(
                'Unsupported image type. Allowed types: ' . implode(', ', array_values($allowed)) . '.'
            );
        }

        return $allowed[$mime];
    }

    /**
     * Confirm the bytes really decode as an image of a reasonable size.
     *
     * getimagesize() returning false means the content is not a real image
     * even if the MIME sniff was satisfied.
     */
    private static function assertDimensions(string $tmpPath): void
    {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            throw new RuntimeException('The uploaded file is not a valid image.');
        }

        [$width, $height] = $info;

        $minWidth  = (int) Config::get('uploads.min_width', 1);
        $minHeight = (int) Config::get('uploads.min_height', 1);
        $maxWidth  = (int) Config::get('uploads.max_width', 10000);
        $maxHeight = (int) Config::get('uploads.max_height', 10000);

        if ($width < $minWidth || $height < $minHeight) {
            throw new RuntimeException("Image is too small. Minimum size is {$minWidth}x{$minHeight} pixels.");
        }
        if ($width > $maxWidth || $height > $maxHeight) {
            throw new RuntimeException("Image is too large. Maximum size is {$maxWidth}x{$maxHeight} pixels.");
        }
    }
}
