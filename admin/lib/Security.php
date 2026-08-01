<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

use Throwable;

final class Security
{
    private const THROTTLE_WINDOW = 900;
    private const THROTTLE_BLOCK = 900;
    private const THROTTLE_FAILURES = 5;
    private const THROTTLE_RETENTION = 86400;
    private const THROTTLE_MAX_KEYS = 500;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function sendAdminHeaders(string $contentType = 'text/html; charset=UTF-8'): void
    {
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'");
    }

    public function startSession(): void
    {
        if (($this->config['require_https'] ?? true) === true && !$this->isSecureRequest()) {
            throw new ConfigurationException('Der Redaktionsbereich benötigt HTTPS.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('hofladen_editorial');
        foreach (['session.use_strict_mode' => '1', 'session.use_only_cookies' => '1', 'session.cookie_httponly' => '1', 'session.cookie_samesite' => 'Strict', 'session.cache_limiter' => 'nocache'] as $setting => $value) {
            if (ini_set($setting, $value) === false) {
                throw new ConfigurationException('Eine erforderliche Sitzungseinstellung ist serverseitig gesperrt.');
            }
        }
        if (!session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/admin',
            'secure' => ($this->config['require_https'] ?? true) === true || $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Strict',
        ])) {
            throw new ConfigurationException('Die sicheren Sitzungscookies konnten nicht konfiguriert werden.');
        }
        if (!session_start()) {
            throw new AuthenticationException('Die Sitzung konnte nicht gestartet werden.');
        }
        $this->expireSessionIfNeeded();
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    public function requireAuthentication(): void
    {
        if (!$this->isAuthenticated()) {
            throw new AuthenticationException('Anmeldung erforderlich.');
        }
    }

    public function attemptLogin(string $username, string $password): bool
    {
        $this->startSession();
        $networkKey = $this->networkKey();
        $now = time();
        // Reserve the attempt under the throttle lock before doing the expensive
        // verification. Parallel requests therefore cannot all pass a separate
        // pre-check before the fifth failure is recorded.
        $allowed = $this->reserveAttempt($networkKey, $now);
        $usernameOk = strlen($username) <= 200 && hash_equals((string)$this->config['admin_username'], $username);
        // Run password_verify even for a blocked/wrong-name attempt to reduce useful timing differences.
        $passwordOk = strlen($password) <= 4096 && password_verify($password, (string)$this->config['admin_password_hash']);
        if ($allowed && $usernameOk && $passwordOk) {
            $this->clearFailures($networkKey, $now);
            if (!session_regenerate_id(true)) {
                $_SESSION = [];
                throw new AuthenticationException('Die Sitzung konnte nicht sicher erneuert werden.');
            }
            $_SESSION = [
                'authenticated' => true,
                'started_at' => $now,
                'last_seen' => $now,
                'csrf' => Support::randomId(32),
            ];
            return true;
        }
        return false;
    }

    public function csrfToken(): string
    {
        $this->requireAuthentication();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || strlen($_SESSION['csrf']) < 32) {
            $_SESSION['csrf'] = Support::randomId(32);
        }
        return $_SESSION['csrf'];
    }

    public function assertPostWithCsrf(mixed $submitted): void
    {
        $this->requireAuthentication();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new AuthenticationException('Diese Aktion ist nur per POST erlaubt.');
        }
        $expected = $_SESSION['csrf'] ?? null;
        if (!is_string($submitted) || !is_string($expected) || !hash_equals($expected, $submitted)) {
            throw new AuthenticationException('Die Sicherheitsprüfung ist abgelaufen. Bitte neu laden.');
        }
    }

    public function logout(): void
    {
        $this->requireAuthentication();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Strict',
            ]);
        }
        session_destroy();
    }

    private function expireSessionIfNeeded(): void
    {
        if (!$this->isAuthenticated()) {
            return;
        }
        $now = time();
        $started = is_int($_SESSION['started_at'] ?? null) ? $_SESSION['started_at'] : 0;
        $lastSeen = is_int($_SESSION['last_seen'] ?? null) ? $_SESSION['last_seen'] : 0;
        $absolute = (int)$this->config['session_absolute_seconds'];
        $idle = (int)$this->config['session_idle_seconds'];
        if ($started <= 0 || $lastSeen <= 0 || $now - $started > $absolute || $now - $lastSeen > $idle) {
            $_SESSION = [];
            if (!session_regenerate_id(true)) {
                session_destroy();
                throw new AuthenticationException('Die abgelaufene Sitzung konnte nicht sicher erneuert werden.');
            }
            return;
        }
        $_SESSION['last_seen'] = $now;
    }

    private function isSecureRequest(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (in_array($remote, $this->config['trusted_proxies'], true)) {
            return strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
        }
        return false;
    }

    private function networkKey(): string
    {
        $address = $this->clientAddress();
        return hash_hmac('sha256', $address, (string)$this->config['throttle_secret']);
    }

    private function clientAddress(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!in_array($remote, $this->config['trusted_proxies'], true)) {
            return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
        }
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        // Accept only one syntactically valid client address. Ambiguous chains fall
        // back to the trusted proxy key and cannot nominate an arbitrary victim.
        if ($forwarded !== '' && !str_contains($forwarded, ',') && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
    }

    private function reserveAttempt(string $key, int $now): bool
    {
        return $this->withThrottleStore(function (array &$store) use ($key, $now): bool {
            $this->pruneThrottleStore($store, $now);
            $entry = is_array($store['keys'][$key] ?? null) ? $store['keys'][$key] : ['failures' => [], 'blockedUntil' => 0, 'lastSeen' => $now];
            if ((int)($entry['blockedUntil'] ?? 0) > $now) {
                return false;
            }
            $failures = is_array($entry['failures'] ?? null) ? $entry['failures'] : [];
            $failures = array_values(array_filter($failures, static fn(mixed $time): bool => is_int($time) && $time >= $now - self::THROTTLE_WINDOW));
            $failures[] = $now;
            if (count($failures) >= self::THROTTLE_FAILURES) {
                $entry['blockedUntil'] = $now + self::THROTTLE_BLOCK;
            }
            $entry['failures'] = array_slice($failures, -self::THROTTLE_FAILURES);
            $entry['lastSeen'] = $now;
            $store['keys'][$key] = $entry;
            $this->boundThrottleStore($store);
            return true;
        });
    }

    private function clearFailures(string $key, int $now): void
    {
        $this->withThrottleStore(function (array &$store) use ($key, $now): mixed {
            $this->pruneThrottleStore($store, $now);
            unset($store['keys'][$key]);
            return null;
        });
    }

    /** @param array<string,mixed> $store */
    private function pruneThrottleStore(array &$store, int $now): void
    {
        if (!isset($store['keys']) || !is_array($store['keys'])) {
            $store = ['schemaVersion' => 1, 'keys' => []];
            return;
        }
        foreach ($store['keys'] as $key => $entry) {
            if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key) || !is_array($entry) || (int)($entry['lastSeen'] ?? 0) < $now - self::THROTTLE_RETENTION) {
                unset($store['keys'][$key]);
            }
        }
    }

    /** @param array<string,mixed> $store */
    private function boundThrottleStore(array &$store): void
    {
        if (count($store['keys']) <= self::THROTTLE_MAX_KEYS) {
            return;
        }
        uasort($store['keys'], static fn(mixed $a, mixed $b): int => (int)($b['lastSeen'] ?? 0) <=> (int)($a['lastSeen'] ?? 0));
        $store['keys'] = array_slice($store['keys'], 0, self::THROTTLE_MAX_KEYS, true);
    }

    /** @template T @param callable(array<string,mixed>&):T $operation @return T */
    private function withThrottleStore(callable $operation): mixed
    {
        $directory = rtrim((string)$this->config['throttle_dir'], DIRECTORY_SEPARATOR);
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'login-throttle.lock';
        $dataPath = $directory . DIRECTORY_SEPARATOR . 'login-throttle-v1.json';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new AuthenticationException('Die Anmeldung ist vorübergehend nicht verfügbar.');
        }
        try {
            $store = ['schemaVersion' => 1, 'keys' => []];
            if (is_file($dataPath)) {
                try {
                    $read = file_get_contents($dataPath);
                    if ($read !== false) {
                        $decoded = Support::decodeObject($read);
                        if (($decoded['schemaVersion'] ?? null) === 1 && is_array($decoded['keys'] ?? null)) {
                            $store = $decoded;
                        }
                    }
                } catch (Throwable) {
                    // A corrupt throttle file must fail toward temporary denial,
                    // not silently allow unlimited guesses.
                    throw new AuthenticationException('Die Anmeldung ist vorübergehend nicht verfügbar.');
                }
            }
            $result = $operation($store);
            $temporary = tempnam($directory, '.throttle-');
            if ($temporary === false || file_put_contents($temporary, Support::encodeJson($store), LOCK_EX) === false) {
                if (is_string($temporary)) {
                    @unlink($temporary);
                }
                throw new AuthenticationException('Die Anmeldung ist vorübergehend nicht verfügbar.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $dataPath)) {
                @unlink($temporary);
                throw new AuthenticationException('Die Anmeldung ist vorübergehend nicht verfügbar.');
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
