<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

final class Preflight
{
    /** @param array<string,mixed> $config @return list<array{label:string,status:string,message:string}> */
    public static function checks(array $config): array
    {
        $checks = [];
        self::add($checks, 'PHP', PHP_VERSION_ID >= 80100, 'PHP 8.1 oder neuer');
        self::add($checks, 'PHP-Fehleranzeige', !filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL), 'muss in Staging und Produktion ausgeschaltet sein');
        self::add($checks, 'PHP-Startfehleranzeige', !filter_var(ini_get('display_startup_errors'), FILTER_VALIDATE_BOOL), 'muss in Staging und Produktion ausgeschaltet sein');
        foreach (['json', 'session', 'fileinfo', 'gd', 'exif'] as $extension) {
            self::add($checks, 'Erweiterung ' . $extension, extension_loaded($extension), 'muss geladen sein');
        }
        // Check every mandatory function the file store, session guard, and image pipeline
        // rely on. Shared hosts can expose an extension while selectively
        // disabling individual functions, so extension_loaded() alone is not a
        // sufficient deployment gate.
        foreach ([
            'flock', 'random_bytes', 'password_verify', 'hash_file', 'hash_hmac', 'hash_equals',
            'fopen', 'fwrite', 'fflush', 'rename', 'tempnam', 'copy', 'unlink', 'chmod', 'ini_set',
            'file_get_contents', 'file_put_contents', 'filesize', 'is_uploaded_file', 'move_uploaded_file',
            'session_status', 'session_name', 'session_set_cookie_params', 'session_start',
            'session_regenerate_id', 'session_get_cookie_params', 'session_destroy', 'setcookie',
            'getimagesize', 'imagesx', 'imagesy', 'imagedestroy',
            'imagecreatefromjpeg', 'imagecreatefrompng', 'imagecreatefromwebp',
            'imagecreatetruecolor', 'imagecopyresampled', 'imagealphablending', 'imagesavealpha',
            'imagecolorallocatealpha', 'imagecolorallocate', 'imagefilledrectangle', 'imagecopy',
            'imagewebp', 'imagejpeg', 'imageflip', 'imagerotate', 'exif_read_data',
        ] as $function) {
            self::add($checks, 'Funktion ' . $function, function_exists($function), 'muss verfügbar sein');
        }
        self::add($checks, 'Klasse finfo', class_exists('finfo'), 'muss verfügbar sein');
        $maxUpload = (int)$config['max_upload_bytes'];
        self::add($checks, 'PHP-Dateiuploads', filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOL), 'müssen aktiviert sein');
        self::add($checks, 'upload_max_filesize', self::iniBytes((string)ini_get('upload_max_filesize')) >= $maxUpload, 'mindestens so groß wie das konfigurierte Upload-Limit');
        self::add($checks, 'post_max_size', self::iniBytes((string)ini_get('post_max_size')) >= $maxUpload + 1048576, 'Upload-Limit plus Formularreserve');
        foreach (['private_data_dir', 'private_upload_dir', 'ledger_dir', 'backup_dir', 'trash_dir', 'throttle_dir', 'cache_dir'] as $key) {
            $path = (string)$config[$key];
            self::add($checks, 'Privater Speicher ' . $key, is_dir($path) && is_readable($path) && is_writable($path), 'privat, lesbar und schreibbar');
            $mode = @fileperms($path);
            self::add($checks, 'Rechte ' . $key, is_int($mode) && ($mode & 0077) === 0, 'keine Gruppen-/Gast-Rechte');
        }
        $configPath = $config['_config_path'] ?? null;
        $configMode = is_string($configPath) ? @fileperms($configPath) : false;
        self::add($checks, 'Rechte private Konfiguration', is_int($configMode) && ($configMode & 0077) === 0, 'nur für den PHP-Benutzer lesbar');
        $publicMedia = (string)$config['public_media_dir'];
        self::add($checks, 'Öffentliche Bildvarianten', is_dir($publicMedia) && is_readable($publicMedia) && is_writable($publicMedia), 'lesbar und schreibbar');
        $publicMode = @fileperms($publicMedia);
        self::add($checks, 'Rechte öffentliche Bildvarianten', is_int($publicMode) && ($publicMode & 0022) === 0 && ($publicMode & 0055) === 0055, 'web-lesbar, aber nicht für Gruppe/Gäste schreibbar');
        self::add($checks, 'Öffentliche Route', ($config['public_route_verified'] ?? false) === true, 'muss je Umgebung manuell geprüft und bestätigt sein');
        return $checks;
    }

    /** @param array<string,mixed> $config */
    public static function assertPublicRuntime(array $config): void
    {
        foreach (self::checks($config) as $check) {
            if ($check['status'] === 'error') {
                throw new ConfigurationException('Die Server-Vorprüfung ist nicht vollständig.');
            }
        }
    }

    /** @param array<string,mixed> $config */
    public static function allPassed(array $config): bool
    {
        foreach (self::checks($config) as $check) {
            if ($check['status'] === 'error') {
                return false;
            }
        }
        return true;
    }

    /** @param list<array{label:string,status:string,message:string}> $checks */
    private static function add(array &$checks, string $label, bool $passed, string $message): void
    {
        $checks[] = ['label' => $label, 'status' => $passed ? 'ok' : 'error', 'message' => $message];
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
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
}
