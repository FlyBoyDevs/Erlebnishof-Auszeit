<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

use DateTimeInterface;
use Throwable;

final class Images
{
    private const PUBLIC_WIDTHS = [320, 640, 1200];
    private const MAX_VARIANT_BYTES = 2097152;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @template T @param callable():T $operation @return T */
    public function withStatusLock(callable $operation): mixed
    {
        $path = rtrim((string)$this->config['trash_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'image-operations.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new StorageException('Die Bildbearbeitungssperre konnte nicht gesetzt werden.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $upload @return array<string,mixed> */
    public function createFromUpload(array $upload): array
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException([$this->uploadErrorMessage((int)$error)]);
        }
        $temporary = $upload['tmp_name'] ?? null;
        if (!is_string($temporary) || !is_file($temporary)) {
            throw new ValidationException(['Die hochgeladene Datei konnte nicht gelesen werden.']);
        }
        $bytes = filesize($temporary);
        if ($bytes === false || $bytes < 1) {
            throw new ValidationException(['Die hochgeladene Datei konnte nicht gelesen werden.']);
        }
        if ($bytes > (int)$this->config['max_upload_bytes']) {
            throw new ValidationException(['Das Bild ist größer als die erlaubten ' . $this->configuredMib() . ' MiB.']);
        }
        if (($this->config['environment'] ?? null) !== 'test' && !is_uploaded_file($temporary)) {
            throw new ValidationException(['Die Datei stammt nicht aus einem gültigen Upload.']);
        }
        if (!class_exists('finfo')) {
            throw new ConfigurationException('Fileinfo ist für Bild-Uploads erforderlich.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporary);
        if (!is_string($mime) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ValidationException(['Erlaubt sind ausschließlich JPEG-, PNG- und WebP-Bilder.']);
        }
        $info = @getimagesize($temporary);
        if (!is_array($info) || !isset($info[0], $info[1], $info['mime']) || $info['mime'] !== $mime) {
            throw new ValidationException(['Dateiendung, Dateityp und Bildinhalt passen nicht sicher zusammen.']);
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1
            || $width > (int)$this->config['max_image_dimension']
            || $height > (int)$this->config['max_image_dimension']
            || $width * $height > (int)$this->config['max_image_pixels']) {
            throw new ValidationException(['Das Bild überschreitet die zulässigen Abmessungen oder Pixelzahl.']);
        }
        $this->assertMemoryCapacity($width, $height);

        $image = $this->decode($temporary, $mime);
        if ($mime === 'image/jpeg') {
            $image = $this->orientJpeg($image, $temporary);
            $width = imagesx($image);
            $height = imagesy($image);
        }

        $id = Support::randomId(16);
        $hash = hash_file('sha256', $temporary);
        if (!is_string($hash)) {
            imagedestroy($image);
            throw new StorageException('Der Bildinhalt konnte nicht geprüft werden.');
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $sourceFile = $id . '.' . $extension;
        $sourceTarget = rtrim((string)$this->config['private_upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sourceFile;
        if (file_exists($sourceTarget)) {
            throw new StorageException('Eine zufällige Bild-ID kollidierte. Bitte den Upload wiederholen.');
        }
        $variants = [];
        $created = [];
        try {
            $actualWidths = array_values(array_unique(array_map(static fn(int $target): int => min($target, $width), self::PUBLIC_WIDTHS)));
            sort($actualWidths);
            foreach ($actualWidths as $targetWidth) {
                $targetHeight = max(1, (int)round($height * ($targetWidth / $width)));
                $scaled = $this->scaled($image, $targetWidth, $targetHeight, true);
                foreach (['webp', 'jpg'] as $format) {
                    $file = sprintf('%s-%s-%d.%s', $id, substr($hash, 0, 16), $targetWidth, $format);
                    $target = rtrim((string)$this->config['public_media_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
                    $bytesWritten = $this->encodeVariant($scaled, $target, $format);
                    $created[] = $target;
                    $variants[] = [
                        'file' => $file,
                        'width' => $targetWidth,
                        'height' => $targetHeight,
                        'mime' => $format === 'webp' ? 'image/webp' : 'image/jpeg',
                        'bytes' => $bytesWritten,
                    ];
                }
                imagedestroy($scaled);
            }
            $sourceTemporary = tempnam(dirname($sourceTarget), '.source-');
            if ($sourceTemporary === false) {
                throw new StorageException('Der private Bildspeicher ist nicht verfügbar.');
            }
            $moved = ($this->config['environment'] ?? null) === 'test'
                ? @copy($temporary, $sourceTemporary)
                : @move_uploaded_file($temporary, $sourceTemporary);
            if (!$moved) {
                @unlink($sourceTemporary);
                throw new StorageException('Das Quellbild konnte nicht privat gespeichert werden.');
            }
            @chmod($sourceTemporary, 0600);
            if (!@rename($sourceTemporary, $sourceTarget)) {
                @unlink($sourceTemporary);
                throw new StorageException('Das Quellbild konnte nicht atomar gespeichert werden.');
            }
            $created[] = $sourceTarget;
        } catch (Throwable $error) {
            foreach ($created as $path) {
                @unlink($path);
            }
            imagedestroy($image);
            throw $error;
        }
        imagedestroy($image);

        return [
            'id' => $id,
            'status' => 'active',
            'sourceFile' => $sourceFile,
            'sourceMime' => $mime,
            'hash' => $hash,
            'width' => $width,
            'height' => $height,
            'variants' => $variants,
            'createdAt' => Support::now()->format(DateTimeInterface::RFC3339),
            'trashedAt' => null,
        ];
    }

    /** @param array<string,mixed> $asset */
    public function discardCreated(array $asset): void
    {
        foreach ($this->activePaths($asset) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Copy every file to its future location while the authoritative document
     * still points at the old complete set. Commit removes the old copies only
     * after CAS succeeds; rollback removes staged copies. This avoids a partially
     * renamed asset when one of several filesystem operations fails.
     *
     * @param array<string,mixed> $asset
     * @return array{pairs:list<array{from:string,to:string}>}
     */
    public function stageStatusChange(array $asset, bool $toTrash): array
    {
        $pairs = $this->assetPathPairs($asset, $toTrash);
        // A process may have died after staging copies, or after the document
        // commit but before removing the old copies. The persisted asset status
        // tells us that `from` is authoritative for this operation. Identical
        // stale copies on `to` are therefore safe to discard and recreate. A
        // differing copy is never guessed away.
        foreach ($pairs as $pair) {
            if (is_file($pair['from']) && is_file($pair['to'])) {
                if (!$this->sameFileContents($pair['from'], $pair['to'])) {
                    throw new StorageException('Widersprüchliche Bildkopien erfordern eine manuelle Wiederherstellung.');
                }
                if (!@unlink($pair['to'])) {
                    throw new StorageException('Eine verwaiste Bildkopie konnte nicht bereinigt werden.');
                }
            }
        }
        foreach ($pairs as $pair) {
            if (!is_file($pair['from']) || file_exists($pair['to'])) {
                throw new StorageException('Das Bild kann nicht vollständig für den Statuswechsel vorbereitet werden.');
            }
        }
        $created = [];
        try {
            foreach ($pairs as $pair) {
                $this->atomicCopy($pair['from'], $pair['to']);
                $created[] = $pair['to'];
            }
        } catch (Throwable $error) {
            foreach (array_reverse($created) as $path) {
                @unlink($path);
            }
            throw $error;
        }
        return ['pairs' => $pairs];
    }

    /** @param array{pairs:list<array{from:string,to:string}>} $transaction */
    public function commitStatusChange(array $transaction): bool
    {
        $complete = true;
        foreach ($transaction['pairs'] as $pair) {
            if (is_file($pair['from']) && !@unlink($pair['from'])) {
                // Duplicate old copies are a bounded storage leak, not a broken
                // public/editorial reference. Report it for operator cleanup.
                $complete = false;
            }
        }
        return $complete;
    }

    /** @param array{pairs:list<array{from:string,to:string}>} $transaction */
    public function rollbackStatusChange(array $transaction): void
    {
        $complete = true;
        foreach (array_reverse($transaction['pairs']) as $pair) {
            if (is_file($pair['to']) && !@unlink($pair['to'])) {
                $complete = false;
            }
        }
        if (!$complete) {
            throw new StorageException('Vorbereitete Bildkopien konnten nicht vollständig zurückgerollt werden.');
        }
    }

    /** @param array<string,mixed> $asset */
    public function sourcePath(array $asset): string
    {
        $file = Support::safeBasename((string)($asset['sourceFile'] ?? ''));
        $base = ($asset['status'] ?? null) === 'trashed'
            ? $this->trashAssetDirectory($asset) . DIRECTORY_SEPARATOR . 'source'
            : rtrim((string)$this->config['private_upload_dir'], DIRECTORY_SEPARATOR);
        $path = $base . DIRECTORY_SEPARATOR . $file;
        if (!Support::pathInside($path, $base) || !is_file($path)) {
            throw new ValidationException(['Das private Vorschaubild wurde nicht gefunden.']);
        }
        return $path;
    }

    /** @param array<string,mixed> $asset @return array{path:string,mime:string} */
    public function thumbnailPath(array $asset): array
    {
        $variants = is_array($asset['variants'] ?? null) ? $asset['variants'] : [];
        usort($variants, static function (mixed $a, mixed $b): int {
            $width = (int)($a['width'] ?? 0) <=> (int)($b['width'] ?? 0);
            if ($width !== 0) {
                return $width;
            }
            return (($a['mime'] ?? null) === 'image/webp' ? 0 : 1) <=> (($b['mime'] ?? null) === 'image/webp' ? 0 : 1);
        });
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $file = Support::safeBasename((string)($variant['file'] ?? ''));
            $base = ($asset['status'] ?? null) === 'trashed'
                ? $this->trashAssetDirectory($asset) . DIRECTORY_SEPARATOR . 'variants'
                : rtrim((string)$this->config['public_media_dir'], DIRECTORY_SEPARATOR);
            $path = $base . DIRECTORY_SEPARATOR . $file;
            if (Support::pathInside($path, $base) && is_file($path)) {
                return ['path' => $path, 'mime' => (string)$variant['mime']];
            }
        }
        throw new ValidationException(['Das Vorschaubild wurde nicht gefunden.']);
    }

    private function decode(string $path, string $mime): mixed
    {
        $function = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };
        if ($function === null || !function_exists($function)) {
            throw new ConfigurationException('Der benötigte Bilddecoder ist nicht verfügbar.');
        }
        $image = @$function($path);
        if ($image === false) {
            throw new ValidationException(['Das Bild konnte nicht sicher dekodiert werden.']);
        }
        return $image;
    }

    private function orientJpeg(mixed $image, string $path): mixed
    {
        if (!function_exists('exif_read_data')) {
            throw new ConfigurationException('Exif-Unterstützung ist für korrekte JPEG-Ausrichtung erforderlich.');
        }
        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = is_array($exif) ? (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1) : 1;
        $flipHorizontal = static function (mixed $resource): void {
            if (!function_exists('imageflip') || !imageflip($resource, IMG_FLIP_HORIZONTAL)) {
                throw new ConfigurationException('Die Bildausrichtung kann auf diesem Server nicht verarbeitet werden.');
            }
        };
        $flipVertical = static function (mixed $resource): void {
            if (!function_exists('imageflip') || !imageflip($resource, IMG_FLIP_VERTICAL)) {
                throw new ConfigurationException('Die Bildausrichtung kann auf diesem Server nicht verarbeitet werden.');
            }
        };
        if ($orientation === 2) {
            $flipHorizontal($image);
        } elseif ($orientation === 3) {
            $image = $this->rotate($image, 180);
        } elseif ($orientation === 4) {
            $flipVertical($image);
        } elseif ($orientation === 5) {
            $image = $this->rotate($image, -90);
            $flipHorizontal($image);
        } elseif ($orientation === 6) {
            $image = $this->rotate($image, -90);
        } elseif ($orientation === 7) {
            $image = $this->rotate($image, 90);
            $flipHorizontal($image);
        } elseif ($orientation === 8) {
            $image = $this->rotate($image, 90);
        }
        return $image;
    }

    private function rotate(mixed $image, int $degrees): mixed
    {
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            throw new ValidationException(['Die Bildausrichtung konnte nicht verarbeitet werden.']);
        }
        imagedestroy($image);
        return $rotated;
    }

    private function scaled(mixed $source, int $width, int $height, bool $alpha): mixed
    {
        $target = imagecreatetruecolor($width, $height);
        if ($target === false) {
            throw new StorageException('Eine Bildvariante konnte nicht angelegt werden.');
        }
        if ($alpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
            imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        }
        if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
            imagedestroy($target);
            throw new StorageException('Eine Bildvariante konnte nicht skaliert werden.');
        }
        return $target;
    }

    private function encodeVariant(mixed $image, string $target, string $format): int
    {
        $directory = dirname($target);
        if (file_exists($target)) {
            throw new StorageException('Eine Bildvariante mit diesem opaken Namen existiert bereits.');
        }
        $temporary = tempnam($directory, '.variant-');
        if ($temporary === false) {
            throw new StorageException('Eine Bildvariante konnte nicht vorbereitet werden.');
        }
        if ($format === 'webp') {
            if (!function_exists('imagewebp') || !@imagewebp($image, $temporary, 82)) {
                @unlink($temporary);
                throw new ConfigurationException('WebP-Kodierung ist auf diesem Server nicht verfügbar.');
            }
        } else {
            $flattened = imagecreatetruecolor(imagesx($image), imagesy($image));
            $white = imagecolorallocate($flattened, 255, 255, 255);
            imagefilledrectangle($flattened, 0, 0, imagesx($image), imagesy($image), $white);
            imagealphablending($flattened, true);
            imagecopy($flattened, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $ok = @imagejpeg($flattened, $temporary, 86);
            imagedestroy($flattened);
            if (!$ok) {
                @unlink($temporary);
                throw new ConfigurationException('JPEG-Kodierung ist auf diesem Server nicht verfügbar.');
            }
        }
        $bytes = filesize($temporary);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_VARIANT_BYTES) {
            @unlink($temporary);
            throw new ValidationException(['Die erzeugte Bildvariante ist zu groß. Bitte ein kleineres oder weniger detailreiches Bild wählen.']);
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new StorageException('Eine Bildvariante konnte nicht atomar veröffentlicht werden.');
        }
        return $bytes;
    }

    /** @param array<string,mixed> $asset @return list<array{from:string,to:string}> */
    private function assetPathPairs(array $asset, bool $toTrash): array
    {
        $pairs = [];
        $trash = $this->trashAssetDirectory($asset);
        $trashSource = $trash . DIRECTORY_SEPARATOR . 'source';
        $trashVariants = $trash . DIRECTORY_SEPARATOR . 'variants';
        Support::ensureDirectory($trashSource, 0700);
        Support::ensureDirectory($trashVariants, 0700);
        $sourceFile = Support::safeBasename((string)$asset['sourceFile']);
        $pairs[] = $toTrash
            ? ['from' => rtrim((string)$this->config['private_upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sourceFile, 'to' => $trashSource . DIRECTORY_SEPARATOR . $sourceFile]
            : ['from' => $trashSource . DIRECTORY_SEPARATOR . $sourceFile, 'to' => rtrim((string)$this->config['private_upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sourceFile];
        foreach ($asset['variants'] as $variant) {
            $file = Support::safeBasename((string)$variant['file']);
            $pairs[] = $toTrash
                ? ['from' => rtrim((string)$this->config['public_media_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file, 'to' => $trashVariants . DIRECTORY_SEPARATOR . $file]
                : ['from' => $trashVariants . DIRECTORY_SEPARATOR . $file, 'to' => rtrim((string)$this->config['public_media_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file];
        }
        return $pairs;
    }

    private function atomicCopy(string $from, string $to): void
    {
        $directory = dirname($to);
        $temporary = tempnam($directory, '.asset-copy-');
        if ($temporary === false || !@copy($from, $temporary)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new StorageException('Eine Bilddatei konnte nicht sicher kopiert werden.');
        }
        $sourceHash = hash_file('sha256', $from);
        $copyHash = hash_file('sha256', $temporary);
        if (!is_string($sourceHash) || !is_string($copyHash) || !hash_equals($sourceHash, $copyHash)) {
            @unlink($temporary);
            throw new StorageException('Eine kopierte Bilddatei bestand die Integritätsprüfung nicht.');
        }
        $public = Support::pathInside($to, (string)$this->config['public_media_dir']);
        @chmod($temporary, $public ? 0644 : 0600);
        if (file_exists($to) || !@rename($temporary, $to)) {
            @unlink($temporary);
            throw new StorageException('Eine Bilddatei konnte nicht atomar bereitgestellt werden.');
        }
    }

    private function sameFileContents(string $left, string $right): bool
    {
        $leftSize = @filesize($left);
        $rightSize = @filesize($right);
        if (!is_int($leftSize) || !is_int($rightSize) || $leftSize !== $rightSize) {
            return false;
        }
        $leftHash = @hash_file('sha256', $left);
        $rightHash = @hash_file('sha256', $right);
        return is_string($leftHash) && is_string($rightHash) && hash_equals($leftHash, $rightHash);
    }

    private function assertMemoryCapacity(int $width, int $height): void
    {
        $limit = $this->iniBytes((string)ini_get('memory_limit'));
        if ($limit < 0) {
            return;
        }
        $targetWidth = min(max(self::PUBLIC_WIDTHS), $width);
        $targetHeight = max(1, (int)round($height * ($targetWidth / $width)));
        $estimated = ($width * $height * 8) + ($targetWidth * $targetHeight * 12) + 16 * 1024 * 1024;
        if (memory_get_usage(true) + $estimated > (int)floor($limit * 0.85)) {
            throw new ValidationException(['Das Bild passt trotz zulässiger Pixelzahl nicht sicher in den verfügbaren Serverspeicher. Bitte vorher verkleinern.']);
        }
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        return match ($unit) {
            'g' => (int)($number * 1024 * 1024 * 1024),
            'm' => (int)($number * 1024 * 1024),
            'k' => (int)($number * 1024),
            default => (int)$number,
        };
    }

    /** @param array<string,mixed> $asset @return list<string> */
    private function activePaths(array $asset): array
    {
        $paths = [rtrim((string)$this->config['private_upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Support::safeBasename((string)$asset['sourceFile'])];
        foreach ($asset['variants'] as $variant) {
            $paths[] = rtrim((string)$this->config['public_media_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Support::safeBasename((string)$variant['file']);
        }
        return $paths;
    }

    /** @param array<string,mixed> $asset */
    private function trashAssetDirectory(array $asset): string
    {
        $id = (string)($asset['id'] ?? '');
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new ValidationException(['Ungültige Bild-ID.']);
        }
        return rtrim((string)$this->config['trash_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $id;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Das Bild ist zu groß.',
            UPLOAD_ERR_PARTIAL => 'Das Bild wurde nur teilweise übertragen.',
            UPLOAD_ERR_NO_FILE => 'Bitte ein Bild auswählen.',
            default => 'Der Bild-Upload ist fehlgeschlagen.',
        };
    }

    private function configuredMib(): string
    {
        $mib = (int)$this->config['max_upload_bytes'] / 1048576;
        return rtrim(rtrim(number_format($mib, 2, '.', ''), '0'), '.');
    }
}
