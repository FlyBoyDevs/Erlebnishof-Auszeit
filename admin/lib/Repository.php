<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * File-backed persistence boundary. Every editorial write, ledger allocation and
 * cache replacement is serialized by one process lock. Each individual file is
 * replaced atomically on its own filesystem.
 */
final class Repository
{
    private string $documentFile;
    private string $ledgerFile;
    private string $cacheFile;
    private string $dirtyFile;
    private string $lockFile;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->documentFile = rtrim((string)$config['private_data_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'editorial-v1.json';
        $this->ledgerFile = rtrim((string)$config['ledger_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'news-ledger-v1.json';
        $this->cacheFile = rtrim((string)$config['cache_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'current-v1.json';
        $this->dirtyFile = rtrim((string)$config['cache_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'publication-dirty';
        $this->lockFile = rtrim((string)$config['ledger_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'editorial.lock';
    }

    /** @return array<string,mixed> */
    public function readDocument(): array
    {
        return $this->locked(fn(): array => $this->readDocumentLocked());
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $change
     * @return array{document:array<string,mixed>,snapshot:?array<string,mixed>,publicationComplete:bool}
     */
    public function mutate(int $expectedRevision, callable $change): array
    {
        return $this->locked(function () use ($expectedRevision, $change): array {
            $current = $this->readDocumentLocked();
            if ($current['writeRevision'] !== $expectedRevision) {
                throw new ConflictException('Der Inhalt wurde inzwischen geändert. Bitte Eingaben kopieren, neu laden und abgleichen.');
            }

            $now = Support::now();
            // First reconcile the old intent at the current wall clock. Without
            // this step an expired item extended back into visibility before any
            // guest request would still look continuously visible in the ledger.
            try {
                $this->reconcileLocked($current, $now, true);
                $this->clearDirtyLocked();
            } catch (PublicationException) {
                // Ledger transitions have already been persisted. Keep the dirty
                // marker, but allow an editor to shorten/archive the content that
                // made the old public representation too large.
                $this->atomicWrite($this->dirtyFile, "pending\n", 0600);
            }

            $candidate = $change($current);
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new StorageException('Die Änderung lieferte kein gültiges Dokument.');
            }
            $candidate['schemaVersion'] = Domain::SCHEMA_VERSION;
            $candidate['writeRevision'] = $expectedRevision + 1;
            $candidate['updatedAt'] = $now->format(DateTimeInterface::RFC3339);
            $candidate['recovery']['lastBackupAt'] = $now->format(DateTimeInterface::RFC3339);
            Domain::validateDocument($candidate, $now);
            $this->assertPublishableSize($candidate, $now);

            $this->writeBackupLocked($current, $now);
            $this->atomicWrite($this->dirtyFile, "pending\n", 0600);
            $this->atomicWrite($this->documentFile, Support::encodeJson($candidate), 0600);

            try {
                $snapshot = $this->reconcileLocked($candidate, $now, true);
                $this->clearDirtyLocked();
                return ['document' => $candidate, 'snapshot' => $snapshot, 'publicationComplete' => true];
            } catch (Throwable) {
                // The editorial write is valid and durable. The marker makes the
                // next admin/public request retry publication without reallocating.
                return ['document' => $candidate, 'snapshot' => null, 'publicationComplete' => false];
            }
        });
    }

    /** @return array<string,mixed> */
    public function currentSnapshot(?DateTimeImmutable $now = null): array
    {
        return $this->locked(function () use ($now): array {
            $document = $this->readDocumentLocked();
            $snapshot = $this->reconcileLocked($document, $now ?? Support::now(), is_file($this->dirtyFile));
            $this->clearDirtyLocked();
            return $snapshot;
        });
    }

    /**
     * Restore changes only the editorial document. The independent ledger is
     * deliberately retained so a restore cannot reuse an already seen sequence.
     *
     * @return array{document:array<string,mixed>,snapshot:?array<string,mixed>,publicationComplete:bool}
     */
    public function restoreBackup(string $basename, int $expectedRevision): array
    {
        Support::safeBasename($basename);
        $path = rtrim((string)$this->config['backup_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
        if (!is_file($path) || !str_ends_with($basename, '.json')) {
            throw new ValidationException(['Die gewählte Sicherung wurde nicht gefunden.']);
        }
        $restored = Support::decodeObject($this->readFile($path));
        Domain::validateDocument($restored);
        return $this->mutate($expectedRevision, static function (array $current) use ($restored): array {
            // Asset lifecycle is physical as well as structural. Preserve the
            // current catalogue/status so a data backup cannot claim that files
            // still in image trash are active. Restore such an image first.
            $restored['assets'] = $current['assets'];
            $restored['recovery']['lastRestoreAt'] = Support::now()->format(DateTimeInterface::RFC3339);
            // mutate() owns revision and updatedAt and preserves the current ledger.
            return $restored;
        });
    }

    /** @return list<array{name:string,bytes:int,modifiedAt:string}> */
    public function listBackups(): array
    {
        $directory = rtrim((string)$this->config['backup_dir'], DIRECTORY_SEPARATOR);
        $result = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'editorial-*.json') ?: [] as $path) {
            $name = basename($path);
            $modified = filemtime($path);
            $bytes = filesize($path);
            if ($modified === false || $bytes === false) {
                continue;
            }
            $result[] = [
                'name' => $name,
                'bytes' => $bytes,
                'modifiedAt' => (new DateTimeImmutable('@' . $modified))->setTimezone(new \DateTimeZone(Support::TIMEZONE))->format(DateTimeInterface::RFC3339),
            ];
        }
        usort($result, static fn(array $a, array $b): int => $b['modifiedAt'] <=> $a['modifiedAt']);
        return $result;
    }

    /**
     * Explicit disaster action. Never use this for a routine deploy, rollback or
     * cache loss. The new generation deliberately makes all current entries unread.
     */
    public function rotateLedgerGeneration(): void
    {
        $this->locked(function (): void {
            $now = Support::now();
            if (is_file($this->ledgerFile)) {
                $name = 'ledger-disaster-' . $now->format('Ymd-His-u') . '-' . substr(Support::randomId(4), 0, 8) . '.json';
                $target = rtrim((string)$this->config['backup_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
                $this->atomicWrite($target, $this->readFile($this->ledgerFile), 0600);
            }
            $this->atomicWrite($this->dirtyFile, "pending\n", 0600);
            $ledger = $this->newLedger();
            $this->atomicWrite($this->ledgerFile, Support::encodeJson($ledger), 0600);
            $this->reconcileLocked($this->readDocumentLocked(), $now, true);
            $this->clearDirtyLocked();
        });
    }

    /** @return array{etag:string,maxAge:int,nextTransitionAt:?string} */
    public static function responseMetadata(array $snapshot, DateTimeImmutable $now): array
    {
        $maxAge = 300;
        $next = $snapshot['nextTransitionAt'] ?? null;
        if (is_string($next)) {
            try {
                $seconds = (new DateTimeImmutable($next))->getTimestamp() - $now->getTimestamp();
                $maxAge = max(0, min($maxAge, $seconds));
            } catch (Throwable) {
                $maxAge = 0;
            }
        }
        return [
            'etag' => '"' . (string)$snapshot['cacheValidator'] . '"',
            'maxAge' => $maxAge,
            'nextTransitionAt' => is_string($next) ? $next : null,
        ];
    }

    /** @return array<string,mixed> */
    private function readDocumentLocked(): array
    {
        if (!is_file($this->documentFile)) {
            $initial = Domain::initialDocument();
            Domain::validateDocument($initial);
            $this->atomicWrite($this->documentFile, Support::encodeJson($initial), 0600);
            return $initial;
        }
        $document = Support::decodeObject($this->readFile($this->documentFile));
        Domain::validateDocument($document);
        return $document;
    }

    /** @return array<string,mixed> */
    private function reconcileLocked(array $document, DateTimeImmutable $now, bool $force): array
    {
        $effects = array_values($this->config['available_effects']);
        sort($effects);
        $effectCapabilityVersion = substr(hash('sha256', Support::encodeJson($effects, false)), 0, 16);
        $releaseKey = hash('sha256', Support::encodeJson([
            'release' => (string)$this->config['release_version'],
            'privateSchema' => Domain::SCHEMA_VERSION,
            'publicSchema' => Domain::PUBLIC_SCHEMA_VERSION,
            'effectCapabilityVersion' => $effectCapabilityVersion,
        ], false));

        $cache = $this->readCacheLocked();
        $ledgerWasMissing = !is_file($this->ledgerFile);
        // The ledger is authoritative even when an apparently fresh derived
        // cache exists. Missing ledger means a new generation; corrupt ledger
        // fails closed instead of silently serving an older cached generation.
        $ledger = $this->readLedgerLocked();
        if (!$force && $cache !== null
            && !$ledgerWasMissing
            && ($cache['sourceWriteRevision'] ?? null) === $document['writeRevision']
            && hash_equals((string)($cache['releaseKey'] ?? ''), $releaseKey)
            && hash_equals((string)$ledger['generation'], (string)($cache['public']['newsVersion']['generation'] ?? ''))
            && !$this->cacheDue($cache, $now)
            && is_array($cache['public'] ?? null)
            && $this->cacheRevisionValid($cache['public'], $releaseKey)) {
            return $cache['public'];
        }

        $projection = Domain::publicProjection($document, $this->config, $now);
        $visibleIds = [];
        $publicEntries = [];
        $ledgerChanged = false;
        $maximumCurrentSequence = 0;

        foreach ($projection['entries'] as $entry) {
            $id = (string)$entry['id'];
            $fingerprint = (string)$entry['_fingerprint'];
            unset($entry['_fingerprint']);
            $previous = is_array($ledger['entries'][$id] ?? null) ? $ledger['entries'][$id] : null;
            $allocate = $previous === null
                || ($previous['visible'] ?? null) !== true
                || !hash_equals((string)($previous['fingerprint'] ?? ''), $fingerprint)
                || !hash_equals((string)($previous['generation'] ?? ''), (string)$ledger['generation']);
            if ($allocate) {
                $ledger['highWater']++;
                $previous = [
                    'visible' => true,
                    'fingerprint' => $fingerprint,
                    'generation' => $ledger['generation'],
                    'sequence' => $ledger['highWater'],
                    'assignedAt' => $now->format(DateTimeInterface::RFC3339),
                ];
                $ledger['entries'][$id] = $previous;
                $ledgerChanged = true;
            }
            $visibleIds[$id] = true;
            $maximumCurrentSequence = max($maximumCurrentSequence, (int)$previous['sequence']);
            $entry['changeVersion'] = [
                'generation' => (string)$previous['generation'],
                'sequence' => (int)$previous['sequence'],
            ];
            $publicEntries[] = $entry;
        }

        foreach ($ledger['entries'] as $id => $state) {
            if (!is_array($state) || isset($visibleIds[$id]) || ($state['visible'] ?? null) !== true) {
                continue;
            }
            $ledger['entries'][$id]['visible'] = false;
            $ledgerChanged = true;
        }
        if ($ledgerChanged || $ledgerWasMissing) {
            $ledger['updatedAt'] = $now->format(DateTimeInterface::RFC3339);
            $this->validateLedger($ledger);
            // Ledger first: a cache failure can retry without reusing a sequence.
            $this->atomicWrite($this->ledgerFile, Support::encodeJson($ledger), 0600);
        }

        $base = [
            'schemaVersion' => Domain::PUBLIC_SCHEMA_VERSION,
            'releaseVersion' => (string)$this->config['release_version'],
            'effectCapabilityVersion' => $effectCapabilityVersion,
            'nextTransitionAt' => $projection['nextTransitionAt'],
            'newsVersion' => [
                'generation' => (string)$ledger['generation'],
                'sequence' => $maximumCurrentSequence,
            ],
            'entries' => $publicEntries,
            'exceptions' => $projection['exceptions'],
            'theme' => $projection['theme'],
        ];
        $revision = substr(hash('sha256', $releaseKey . "\n" . Support::encodeJson($base, false)), 0, 32);
        $public = [
            'schemaVersion' => Domain::PUBLIC_SCHEMA_VERSION,
            'releaseVersion' => $base['releaseVersion'],
            'effectCapabilityVersion' => $base['effectCapabilityVersion'],
            'generatedAt' => $now->format(DateTimeInterface::RFC3339),
            'nextTransitionAt' => $base['nextTransitionAt'],
            'snapshotRevision' => $revision,
            'cacheValidator' => $revision,
            'newsVersion' => $base['newsVersion'],
            'entries' => $base['entries'],
            'exceptions' => $base['exceptions'],
            'theme' => $base['theme'],
        ];
        $encodedPublic = Support::encodeJson($public);
        if (strlen($encodedPublic) > Domain::MAX_PUBLIC_BYTES) {
            throw new PublicationException('Die öffentliche Antwort überschreitet die zulässige Größe.');
        }
        Domain::validatePublicSnapshot($public);

        $previousPublic = is_array($cache['public'] ?? null) ? $cache['public'] : null;
        if ($previousPublic !== null && ($previousPublic['snapshotRevision'] ?? null) === $revision) {
            // Draft-only saves do not churn validators or generatedAt.
            $public = $previousPublic;
        }
        $encodedPublic = Support::encodeJson($public);
        $wrapper = [
            'schemaVersion' => 1,
            'sourceWriteRevision' => $document['writeRevision'],
            'releaseKey' => $releaseKey,
            'dueAt' => $public['nextTransitionAt'],
            'public' => $public,
        ];
        $this->atomicWrite($this->cacheFile, Support::encodeJson($wrapper), 0600);
        return $public;
    }

    /** @return array<string,mixed> */
    private function readLedgerLocked(): array
    {
        if (!is_file($this->ledgerFile)) {
            return $this->newLedger();
        }
        $ledger = Support::decodeObject($this->readFile($this->ledgerFile));
        $this->validateLedger($ledger);
        return $ledger;
    }

    /** @return array<string,mixed> */
    private function newLedger(): array
    {
        return [
            'schemaVersion' => 1,
            'generation' => Support::randomId(16),
            'highWater' => 0,
            'updatedAt' => Support::now()->format(DateTimeInterface::RFC3339),
            'entries' => [],
        ];
    }

    /** @param array<string,mixed> $ledger */
    private function validateLedger(array $ledger): void
    {
        $keys = array_keys($ledger);
        sort($keys);
        $expected = ['entries', 'generation', 'highWater', 'schemaVersion', 'updatedAt'];
        sort($expected);
        if ($keys !== $expected
            || ($ledger['schemaVersion'] ?? null) !== 1
            || !is_string($ledger['generation'] ?? null)
            || !preg_match('/\A[a-f0-9]{32}\z/', $ledger['generation'])
            || !is_int($ledger['highWater'] ?? null)
            || $ledger['highWater'] < 0
            || !is_string($ledger['updatedAt'] ?? null)
            || !is_array($ledger['entries'] ?? null)) {
            throw new StorageException('Der Revisionsstand ist ungültig; automatische Wiederverwendung wurde verweigert.');
        }
        $seenSequences = [];
        foreach ($ledger['entries'] as $id => $state) {
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id) || !is_array($state)) {
                throw new StorageException('Der Revisionsstand enthält einen ungültigen Eintrag.');
            }
            $stateKeys = array_keys($state);
            sort($stateKeys);
            if ($stateKeys !== ['assignedAt', 'fingerprint', 'generation', 'sequence', 'visible']
                || !is_bool($state['visible'] ?? null)
                || !is_string($state['fingerprint'] ?? null)
                || !preg_match('/\A[a-f0-9]{64}\z/', $state['fingerprint'])
                || !hash_equals($ledger['generation'], (string)($state['generation'] ?? ''))
                || !is_int($state['sequence'] ?? null)
                || $state['sequence'] < 1
                || $state['sequence'] > $ledger['highWater']
                || !is_string($state['assignedAt'] ?? null)) {
                throw new StorageException('Der Revisionsstand enthält ungültige Zuordnungen.');
            }
            if (isset($seenSequences[$state['sequence']])) {
                throw new StorageException('Der Revisionsstand enthält doppelte Sequenzen.');
            }
            $seenSequences[$state['sequence']] = true;
        }
    }

    /** @return array<string,mixed>|null */
    private function readCacheLocked(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        try {
            $cache = Support::decodeObject($this->readFile($this->cacheFile));
            if (($cache['schemaVersion'] ?? null) !== 1 || !is_array($cache['public'] ?? null)) {
                return null;
            }
            Domain::validatePublicSnapshot($cache['public']);
            return $cache;
        } catch (Throwable) {
            // Cache is derived and may be rebuilt from authoritative data+ledger.
            return null;
        }
    }

    /** @param array<string,mixed> $cache */
    private function cacheDue(array $cache, DateTimeImmutable $now): bool
    {
        $due = $cache['dueAt'] ?? null;
        if ($due === null) {
            return false;
        }
        if (!is_string($due)) {
            return true;
        }
        try {
            return $now >= new DateTimeImmutable($due);
        } catch (Throwable) {
            return true;
        }
    }

    /** @param array<string,mixed> $public */
    private function cacheRevisionValid(array $public, string $releaseKey): bool
    {
        try {
            Domain::validatePublicSnapshot($public);
            $base = [
                'schemaVersion' => $public['schemaVersion'],
                'releaseVersion' => $public['releaseVersion'],
                'effectCapabilityVersion' => $public['effectCapabilityVersion'],
                'nextTransitionAt' => $public['nextTransitionAt'],
                'newsVersion' => $public['newsVersion'],
                'entries' => $public['entries'],
                'exceptions' => $public['exceptions'],
                'theme' => $public['theme'],
            ];
            $expected = substr(hash('sha256', $releaseKey . "\n" . Support::encodeJson($base, false)), 0, 32);
            return hash_equals($expected, (string)$public['snapshotRevision'])
                && hash_equals((string)$public['snapshotRevision'], (string)$public['cacheValidator']);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $document */
    private function assertPublishableSize(array $document, DateTimeImmutable $now): void
    {
        foreach ($this->publishabilityBoundaries($document, $now) as $boundary) {
            $this->assertProjectionSize($document, $boundary);
        }
    }

    /** @param array<string,mixed> $document */
    private function assertProjectionSize(array $document, DateTimeImmutable $at): void
    {
        $projection = Domain::publicProjection($document, $this->config, $at);
        $generation = str_repeat('0', 32);
        $entries = [];
        foreach ($projection['entries'] as $entry) {
            unset($entry['_fingerprint']);
            $entry['changeVersion'] = ['generation' => $generation, 'sequence' => PHP_INT_MAX];
            $entries[] = $entry;
        }
        $effects = array_values($this->config['available_effects']);
        sort($effects);
        $placeholder = [
            'schemaVersion' => Domain::PUBLIC_SCHEMA_VERSION,
            'releaseVersion' => (string)$this->config['release_version'],
            'effectCapabilityVersion' => substr(hash('sha256', Support::encodeJson($effects, false)), 0, 16),
            'generatedAt' => $at->format(DateTimeInterface::RFC3339),
            // A non-null timestamp and the longest supported theme/effect pair
            // form a safe byte upper bound for envelope fields whose values can
            // change independently of entry/exception visibility.
            'nextTransitionAt' => '9999-12-31T23:59:59+01:00',
            'snapshotRevision' => str_repeat('0', 32),
            'cacheValidator' => str_repeat('0', 32),
            'newsVersion' => ['generation' => $generation, 'sequence' => PHP_INT_MAX],
            'entries' => $entries,
            'exceptions' => $projection['exceptions'],
            'theme' => ['name' => 'christmas', 'effect' => 'christmas'],
        ];
        if (strlen(Support::encodeJson($placeholder)) > Domain::MAX_PUBLIC_BYTES) {
            throw new ValidationException(['Eine aktuelle oder geplante öffentliche Darstellung wäre größer als 256 KB. Bitte Texte oder die Anzahl gleichzeitig sichtbarer Einträge reduzieren.']);
        }
    }

    /**
     * Size can increase only when an entry becomes visible or an exception
     * enters the 366-day public horizon. Test now and each such future addition;
     * removals cannot create a larger response.
     *
     * @param array<string,mixed> $document
     * @return list<DateTimeImmutable>
     */
    private function publishabilityBoundaries(array $document, DateTimeImmutable $now): array
    {
        $zone = new \DateTimeZone(Support::TIMEZONE);
        $now = $now->setTimezone($zone);
        $boundaries = [$now->format('U.uP') => $now];
        foreach ($document['entries'] as $entry) {
            if (!is_array($entry) || ($entry['intent'] ?? null) !== 'approved') {
                continue;
            }
            $created = new DateTimeImmutable((string)$entry['createdAt']);
            $display = is_string($entry['displayStart'] ?? null) ? new DateTimeImmutable($entry['displayStart']) : null;
            $approved = is_string($entry['approvedAt'] ?? null) ? new DateTimeImmutable($entry['approvedAt']) : null;
            $start = $display ?? $approved ?? $created;
            if ($approved !== null && $approved > $start) {
                $start = $approved;
            }
            $start = $start->setTimezone($zone);
            if ($start > $now) {
                $boundaries[$start->format('U.uP')] = $start;
            }
        }
        foreach ($document['exceptions'] as $exception) {
            if (!is_array($exception) || ($exception['intent'] ?? null) !== 'approved') {
                continue;
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$exception['startDate'], $zone);
            if ($start === false) {
                continue;
            }
            $horizonEntry = $start->modify('-366 days');
            if ($horizonEntry > $now) {
                $boundaries[$horizonEntry->format('U.uP')] = $horizonEntry;
            }
        }
        uasort($boundaries, static fn(DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);
        return array_values($boundaries);
    }

    /** @param array<string,mixed> $document */
    private function writeBackupLocked(array $document, DateTimeImmutable $now): void
    {
        $name = sprintf(
            'editorial-%s-r%d-%s.json',
            $now->format('Ymd-His-u'),
            (int)$document['writeRevision'],
            substr(Support::randomId(4), 0, 8)
        );
        $path = rtrim((string)$this->config['backup_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        $this->atomicWrite($path, Support::encodeJson($document), 0600);
        if (($this->config['pruning_enabled'] ?? false) === true) {
            $this->pruneBackupsLocked((int)$this->config['backup_limit']);
        }
    }

    private function pruneBackupsLocked(int $keep): void
    {
        $files = glob(rtrim((string)$this->config['backup_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'editorial-*.json') ?: [];
        usort($files, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach (array_slice($files, max(1, $keep)) as $file) {
            @unlink($file);
        }
    }

    private function clearDirtyLocked(): void
    {
        if (is_file($this->dirtyFile) && !@unlink($this->dirtyFile)) {
            throw new StorageException('Der Veröffentlichungsmarker konnte nicht entfernt werden.');
        }
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new StorageException('Ein Speicherverzeichnis ist nicht schreibbar.');
        }
        $temporary = tempnam($directory, '.hofladen-');
        if ($temporary === false) {
            throw new StorageException('Eine temporäre Datei konnte nicht angelegt werden.');
        }
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            @unlink($temporary);
            throw new StorageException('Eine temporäre Datei konnte nicht geöffnet werden.');
        }
        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new StorageException('Eine Datei konnte nicht vollständig geschrieben werden.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new StorageException('Eine Datei konnte nicht synchronisiert werden.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new StorageException('Eine Datei konnte nicht dauerhaft synchronisiert werden.');
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        fclose($handle);
        @chmod($temporary, $mode);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new StorageException('Eine Datei konnte nicht atomar ersetzt werden.');
        }
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new StorageException('Eine erforderliche Datei konnte nicht gelesen werden.');
        }
        return $contents;
    }

    /** @template T @param callable():T $operation @return T */
    private function locked(callable $operation): mixed
    {
        $handle = @fopen($this->lockFile, 'c+');
        if ($handle === false) {
            throw new StorageException('Die Bearbeitungssperre konnte nicht geöffnet werden.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException('Die Bearbeitungssperre konnte nicht gesetzt werden.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
