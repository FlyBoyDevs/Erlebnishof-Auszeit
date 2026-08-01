<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class Support
{
    public const TIMEZONE = 'Europe/Berlin';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    public static function randomId(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function encodeJson(mixed $value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags) . "\n";
    }

    /** @return array<string,mixed> */
    public static function decodeObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new StorageException('JSON konnte nicht gelesen werden.', 0, $error);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new StorageException('JSON-Wurzel muss ein Objekt sein.');
        }
        return $value;
    }

    public static function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    public static function ensureDirectory(string $path, int $mode = 0700): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new ConfigurationException('Ein erforderliches Verzeichnis konnte nicht erstellt werden.');
        }
        if (!is_readable($path) || !is_writable($path)) {
            throw new ConfigurationException('Ein erforderliches Verzeichnis ist nicht les- und schreibbar.');
        }
    }

    public static function pathInside(string $path, string $parent): bool
    {
        $path = self::normalizedAbsolute($path);
        $parent = rtrim(self::normalizedAbsolute($parent), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path . (is_dir($path) ? DIRECTORY_SEPARATOR : ''), $parent);
    }

    public static function lexicalPathInside(string $path, string $parent): bool
    {
        $path = self::normalizedLexicalAbsolute($path);
        $parent = rtrim(self::normalizedLexicalAbsolute($parent), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path . DIRECTORY_SEPARATOR, $parent);
    }

    public static function normalizedAbsolute(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim($real, DIRECTORY_SEPARATOR);
        }
        return self::normalizedLexicalAbsolute($path);
    }

    public static function normalizedLexicalAbsolute(string $path): string
    {
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
            throw new ConfigurationException('Alle Speicherpfade müssen absolut sein.');
        }
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    public static function assertNoSymlinkComponents(string $path): void
    {
        $normalized = self::normalizedLexicalAbsolute($path);
        $current = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, ltrim($normalized, DIRECTORY_SEPARATOR)) as $part) {
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new ConfigurationException('Konfigurations- und Speicherpfade dürfen keine symbolischen Verknüpfungen enthalten.');
            }
            if (!file_exists($current)) {
                // No deeper component can exist before its parent does.
                break;
            }
        }
    }

    public static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function safeBasename(string $name): string
    {
        if ($name === '' || basename($name) !== $name || !preg_match('/\A[a-zA-Z0-9._-]+\z/', $name)) {
            throw new ValidationException(['Ungültiger Dateiname.']);
        }
        return $name;
    }
}
