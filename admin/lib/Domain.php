<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Validation and time-dependent projection for the deliberately small editorial
 * domain. The persisted intent is kept separate from all derived UI/public state.
 */
final class Domain
{
    public const SCHEMA_VERSION = 1;
    public const PUBLIC_SCHEMA_VERSION = 1;
    public const MAX_ENTRIES = 500;
    public const MAX_VISIBLE_ENTRIES = 50;
    public const MAX_EXCEPTIONS = 100;
    public const MAX_ASSETS = 500;
    public const MAX_PUBLIC_EXCEPTIONS = 50;
    public const MAX_TITLE = 120;
    public const MAX_BODY = 3000;
    public const MAX_IMAGE_ALT = 300;
    public const MAX_PUBLIC_BYTES = 262144;

    private const INTENTS = ['draft', 'approved', 'archived', 'trashed'];
    private const TYPES = ['news', 'event'];
    private const TARGETS = ['cafe', 'shop', 'both'];
    private const THEME_MODES = ['automatic', 'off', 'spring', 'summer', 'autumn', 'christmas', 'winter'];

    /** @return array<string,mixed> */
    public static function initialDocument(?DateTimeImmutable $now = null): array
    {
        $now ??= Support::now();
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'writeRevision' => 0,
            'updatedAt' => $now->format(DateTimeInterface::RFC3339),
            'entries' => [],
            'exceptions' => [],
            'themes' => [
                'mode' => 'automatic',
                'windows' => ['spring' => null, 'summer' => null, 'autumn' => null],
            ],
            'assets' => [],
            'recovery' => ['lastBackupAt' => null, 'lastRestoreAt' => null],
        ];
    }

    /** @param array<string,mixed> $document */
    public static function validateDocument(array $document, ?DateTimeImmutable $now = null): void
    {
        $errors = [];
        self::assertKeys(
            $document,
            ['schemaVersion', 'writeRevision', 'updatedAt', 'entries', 'exceptions', 'themes', 'assets', 'recovery'],
            'Dokument',
            $errors
        );
        if (($document['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'Die private Schemaversion wird von dieser Version nicht unterstützt.';
        }
        if (!is_int($document['writeRevision'] ?? null) || $document['writeRevision'] < 0) {
            $errors[] = 'writeRevision muss eine nichtnegative Ganzzahl sein.';
        }
        self::validateInstant($document['updatedAt'] ?? null, 'updatedAt', false, $errors);

        $entries = self::listValue($document['entries'] ?? null, 'entries', $errors);
        $exceptions = self::listValue($document['exceptions'] ?? null, 'exceptions', $errors);
        $assets = self::listValue($document['assets'] ?? null, 'assets', $errors);
        if (count($entries) > self::MAX_ENTRIES) {
            $errors[] = 'Es dürfen höchstens ' . self::MAX_ENTRIES . ' Einträge aufbewahrt werden.';
        }
        if (count($exceptions) > self::MAX_EXCEPTIONS) {
            $errors[] = 'Es dürfen höchstens ' . self::MAX_EXCEPTIONS . ' Ausnahmen aufbewahrt werden.';
        }
        if (count($assets) > self::MAX_ASSETS) {
            $errors[] = 'Es dürfen höchstens ' . self::MAX_ASSETS . ' Bilder aufbewahrt werden.';
        }

        $entryIds = [];
        foreach ($entries as $position => $entry) {
            self::validateEntry($entry, $position, $entryIds, $errors);
        }
        $exceptionIds = [];
        foreach ($exceptions as $position => $exception) {
            self::validateException($exception, $position, $exceptionIds, $errors);
        }
        $assetIds = [];
        foreach ($assets as $position => $asset) {
            self::validateAsset($asset, $position, $assetIds, $errors);
        }
        $sourceFiles = [];
        $variantFiles = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || array_is_list($asset)) {
                continue;
            }
            $sourceFile = $asset['sourceFile'] ?? null;
            if (is_string($sourceFile)) {
                if (isset($sourceFiles[$sourceFile])) {
                    $errors[] = 'Private Quellbild-Dateinamen müssen eindeutig sein.';
                }
                $sourceFiles[$sourceFile] = true;
            }
            foreach (is_array($asset['variants'] ?? null) ? $asset['variants'] : [] as $variant) {
                $file = is_array($variant) ? ($variant['file'] ?? null) : null;
                if (!is_string($file)) {
                    continue;
                }
                if (isset($variantFiles[$file])) {
                    $errors[] = 'Öffentliche Bildvarianten-Dateinamen müssen eindeutig sein.';
                }
                $variantFiles[$file] = true;
            }
        }

        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                continue;
            }
            $imageId = $entry['imageId'] ?? null;
            if ($imageId !== null && (!isset($assetIds[$imageId]) || $assetIds[$imageId] !== 'active')) {
                $errors[] = 'Ein Eintrag verweist auf ein fehlendes oder gelöschtes Bild.';
            }
        }

        self::validateThemes($document['themes'] ?? null, $errors);
        self::validateRecovery($document['recovery'] ?? null, $errors);
        self::validateExceptionConflicts($exceptions, $errors);
        self::validatePublicCaps($entries, $exceptions, $now ?? Support::now(), $errors);

        if ($errors !== []) {
            throw new ValidationException(array_values(array_unique($errors)));
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $config
     * @return array{entries:list<array<string,mixed>>,exceptions:list<array<string,mixed>>,theme:array{name:?string,effect:?string},nextTransitionAt:?string}
     */
    public static function publicProjection(array $document, array $config, DateTimeImmutable $now): array
    {
        self::validateDocument($document, $now);
        $zone = new DateTimeZone(Support::TIMEZONE);
        $now = $now->setTimezone($zone);
        $assets = [];
        foreach ($document['assets'] as $asset) {
            if (is_array($asset) && ($asset['status'] ?? null) === 'active') {
                $assets[(string)$asset['id']] = $asset;
            }
        }

        $entries = [];
        $transitions = [];
        foreach ($document['entries'] as $entry) {
            if (!is_array($entry) || ($entry['intent'] ?? null) !== 'approved') {
                continue;
            }
            [$start, $end] = self::entryWindow($entry);
            self::addFutureTransition($transitions, $start, $now);
            self::addFutureTransition($transitions, $end, $now);
            if (!self::withinWindow($now, $start, $end)) {
                continue;
            }

            $image = self::publicImage($entry, $assets, $config);
            $public = [
                'id' => (string)$entry['id'],
                'type' => (string)$entry['type'],
                'title' => (string)$entry['title'],
                'body' => (string)$entry['body'],
                'publishedAt' => self::publishedAt($entry)->format(DateTimeInterface::RFC3339),
                'eventStart' => $entry['type'] === 'event' ? (string)$entry['eventStart'] : null,
                // Keep an omitted editorial end omitted in the public contract.
                // The derived next-midnight end is used only for visibility and
                // nextTransitionAt, so the UI never displays an invented time.
                'eventEnd' => $entry['type'] === 'event' ? ($entry['eventEnd'] ?? null) : null,
                'image' => $image,
            ];
            // Display-window fields and the derived publication instant affect the
            // snapshot, but ADR 0018 deliberately excludes them from the unread
            // revision unless they cause a non-visible -> visible transition.
            $public['_fingerprint'] = hash('sha256', Support::encodeJson([
                'type' => $public['type'],
                'title' => $public['title'],
                'body' => $public['body'],
                'image' => $public['image'],
                'eventStart' => $public['eventStart'],
                'eventEnd' => $public['eventEnd'],
            ], false));
            $entries[] = $public;
        }
        usort($entries, static fn(array $left, array $right): int => self::comparePublicEntries($left, $right, $now));

        $today = $now->format('Y-m-d');
        $horizonEnd = $now->setTime(0, 0)->modify('+366 days')->format('Y-m-d');
        $exceptions = [];
        foreach ($document['exceptions'] as $exception) {
            if (!is_array($exception) || ($exception['intent'] ?? null) !== 'approved') {
                continue;
            }
            $startDate = (string)$exception['startDate'];
            $endDate = (string)$exception['endDate'];
            $start = self::localDate($startDate);
            $afterEnd = self::localDate($endDate)->modify('+1 day');
            self::addFutureTransition($transitions, $start, $now);
            self::addFutureTransition($transitions, $afterEnd, $now);

            if ($startDate > $horizonEnd) {
                self::addFutureTransition($transitions, $start->modify('-366 days'), $now);
            }
            if ($endDate < $today || $startDate > $horizonEnd) {
                continue;
            }
            $exceptions[] = [
                'id' => (string)$exception['id'],
                'target' => (string)$exception['target'],
                'startDate' => $startDate,
                'endDate' => $endDate,
                'closed' => (bool)$exception['closed'],
                'opens' => $exception['closed'] ? null : (string)$exception['opens'],
                'closes' => $exception['closed'] ? null : (string)$exception['closes'],
                'note' => (string)$exception['note'],
            ];
        }
        usort($exceptions, static fn(array $a, array $b): int =>
            [$a['startDate'], $a['endDate'], $a['id']] <=> [$b['startDate'], $b['endDate'], $b['id']]
        );

        [$theme, $themeTransitions] = self::resolveTheme($document['themes'], $config, $now);
        foreach ($themeTransitions as $transition) {
            self::addFutureTransition($transitions, $transition, $now);
        }
        usort($transitions, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $next = $transitions[0] ?? null;

        return [
            'entries' => $entries,
            'exceptions' => $exceptions,
            'theme' => $theme,
            'nextTransitionAt' => $next?->format(DateTimeInterface::RFC3339),
        ];
    }

    /** @param array<string,mixed> $snapshot */
    public static function validatePublicSnapshot(array $snapshot): void
    {
        $errors = [];
        self::assertKeys($snapshot, ['schemaVersion', 'releaseVersion', 'effectCapabilityVersion', 'generatedAt', 'nextTransitionAt', 'snapshotRevision', 'cacheValidator', 'newsVersion', 'entries', 'exceptions', 'theme'], 'Öffentlicher Snapshot', $errors);
        if (($snapshot['schemaVersion'] ?? null) !== self::PUBLIC_SCHEMA_VERSION) {
            $errors[] = 'Ungültige öffentliche Schemaversion.';
        }
        self::validateInstant($snapshot['generatedAt'] ?? null, 'generatedAt', false, $errors);
        self::validateInstant($snapshot['nextTransitionAt'] ?? null, 'nextTransitionAt', true, $errors);
        self::validateText($snapshot['releaseVersion'] ?? null, 'releaseVersion', 64, true, $errors);
        if (!is_string($snapshot['releaseVersion'] ?? null) || !preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $snapshot['releaseVersion'])) {
            $errors[] = 'Ungültige Release-Version.';
        }
        if (!is_string($snapshot['effectCapabilityVersion'] ?? null) || !preg_match('/\A[a-f0-9]{16}\z/', $snapshot['effectCapabilityVersion'])) {
            $errors[] = 'Ungültige Effekt-Fähigkeitsversion.';
        }
        if (!is_string($snapshot['snapshotRevision'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $snapshot['snapshotRevision'])) {
            $errors[] = 'Ungültige Snapshot-Revision.';
        }
        if (!is_string($snapshot['cacheValidator'] ?? null)
            || !preg_match('/\A[a-f0-9]{32}\z/', $snapshot['cacheValidator'])
            || !is_string($snapshot['snapshotRevision'] ?? null)
            || !hash_equals($snapshot['snapshotRevision'], $snapshot['cacheValidator'])) {
            $errors[] = 'Ungültiger Cache-Validator.';
        }
        self::validatePublicVersion($snapshot['newsVersion'] ?? null, 'newsVersion', true, $errors);

        $entries = self::listValue($snapshot['entries'] ?? null, 'Öffentliche Einträge', $errors);
        if (count($entries) > self::MAX_VISIBLE_ENTRIES) {
            $errors[] = 'Zu viele öffentliche Einträge.';
        }
        $ids = [];
        $maximumSequence = 0;
        foreach ($entries as $position => $entry) {
            $label = 'Öffentlicher Eintrag ' . ($position + 1);
            if (!is_array($entry) || array_is_list($entry)) {
                $errors[] = $label . ' muss ein Objekt sein.';
                continue;
            }
            self::assertKeys($entry, ['id', 'type', 'title', 'body', 'publishedAt', 'eventStart', 'eventEnd', 'image', 'changeVersion'], $label, $errors);
            self::validateId($entry['id'] ?? null, $label . ': ID', $ids, $errors, 'public');
            self::validateText($entry['title'] ?? null, $label . ': Titel', self::MAX_TITLE, true, $errors);
            self::validateText($entry['body'] ?? null, $label . ': Text', self::MAX_BODY, true, $errors);
            self::validateInstant($entry['publishedAt'] ?? null, $label . ': Veröffentlichung', false, $errors);
            self::validatePublicVersion($entry['changeVersion'] ?? null, $label . ': Änderung', false, $errors);
            if (is_array($entry['changeVersion'] ?? null)) {
                if (($entry['changeVersion']['generation'] ?? null) !== ($snapshot['newsVersion']['generation'] ?? null)) {
                    $errors[] = $label . ': Generation stimmt nicht mit newsVersion überein.';
                }
                if (is_int($entry['changeVersion']['sequence'] ?? null)) {
                    $maximumSequence = max($maximumSequence, $entry['changeVersion']['sequence']);
                }
            }
            if (($entry['type'] ?? null) === 'news') {
                if (($entry['eventStart'] ?? null) !== null || ($entry['eventEnd'] ?? null) !== null) {
                    $errors[] = $label . ': Neuigkeiten dürfen keine Termindaten enthalten.';
                }
            } elseif (($entry['type'] ?? null) === 'event') {
                self::validateInstant($entry['eventStart'] ?? null, $label . ': Terminbeginn', false, $errors);
                self::validateInstant($entry['eventEnd'] ?? null, $label . ': Terminende', true, $errors);
                self::validateOrderedInstants($entry['eventStart'] ?? null, $entry['eventEnd'] ?? null, $label, $errors);
            } else {
                $errors[] = $label . ': ungültiger Typ.';
            }
            $image = $entry['image'] ?? null;
            if ($image !== null) {
                if (!is_array($image) || array_is_list($image)) {
                    $errors[] = $label . ': Bild muss ein Objekt sein.';
                } else {
                    self::assertKeys($image, ['url', 'width', 'height', 'alt'], $label . ': Bild', $errors);
                    $url = $image['url'] ?? null;
                    if (!is_string($url) || !str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '..') || preg_match('/[?#\\\x00-\x20]/', $url)) {
                        $errors[] = $label . ': ungültige öffentliche Bild-URL.';
                    }
                    if (!is_int($image['width'] ?? null) || $image['width'] < 1 || !is_int($image['height'] ?? null) || $image['height'] < 1) {
                        $errors[] = $label . ': ungültige Bildabmessungen.';
                    }
                    self::validateText($image['alt'] ?? null, $label . ': Bildbeschreibung', self::MAX_IMAGE_ALT, true, $errors);
                }
            }
        }
        if (is_array($snapshot['newsVersion'] ?? null)
            && is_int($snapshot['newsVersion']['sequence'] ?? null)
            && $snapshot['newsVersion']['sequence'] !== $maximumSequence) {
            $errors[] = 'newsVersion.sequence muss der höchsten aktuell sichtbaren Eintragssequenz entsprechen.';
        }

        $exceptions = self::listValue($snapshot['exceptions'] ?? null, 'Öffentliche Ausnahmen', $errors);
        if (count($exceptions) > self::MAX_PUBLIC_EXCEPTIONS) {
            $errors[] = 'Zu viele öffentliche Ausnahmen.';
        }
        $exceptionIds = [];
        foreach ($exceptions as $position => $exception) {
            $label = 'Öffentliche Ausnahme ' . ($position + 1);
            if (!is_array($exception) || array_is_list($exception)) {
                $errors[] = $label . ' muss ein Objekt sein.';
                continue;
            }
            self::assertKeys($exception, ['id', 'target', 'startDate', 'endDate', 'closed', 'opens', 'closes', 'note'], $label, $errors);
            self::validateId($exception['id'] ?? null, $label . ': ID', $exceptionIds, $errors, 'public');
            if (!in_array($exception['target'] ?? null, self::TARGETS, true) || !is_bool($exception['closed'] ?? null)) {
                $errors[] = $label . ': Ziel oder Schließungsstatus ist ungültig.';
            }
            self::validateDate($exception['startDate'] ?? null, $label . ': Beginn', $errors);
            self::validateDate($exception['endDate'] ?? null, $label . ': Ende', $errors);
            if (is_string($exception['startDate'] ?? null) && is_string($exception['endDate'] ?? null) && $exception['endDate'] < $exception['startDate']) {
                $errors[] = $label . ': Ende liegt vor Beginn.';
            }
            self::validateText($exception['note'] ?? null, $label . ': Hinweis', self::MAX_TITLE, false, $errors);
            if (($exception['closed'] ?? null) === true) {
                if (($exception['opens'] ?? null) !== null || ($exception['closes'] ?? null) !== null) {
                    $errors[] = $label . ': Schließung enthält Ersatzzeiten.';
                }
            } else {
                self::validateTime($exception['opens'] ?? null, $label . ': Öffnet', $errors);
                self::validateTime($exception['closes'] ?? null, $label . ': Schließt', $errors);
            }
        }

        $theme = $snapshot['theme'] ?? null;
        if (!is_array($theme) || array_is_list($theme)) {
            $errors[] = 'Öffentliches Thema muss ein Objekt sein.';
        } else {
            self::assertKeys($theme, ['name', 'effect'], 'Öffentliches Thema', $errors);
            $name = $theme['name'] ?? null;
            $effect = $theme['effect'] ?? null;
            if ($name !== null && !in_array($name, ['spring', 'summer', 'autumn', 'christmas', 'winter'], true)) {
                $errors[] = 'Unbekanntes öffentliches Thema.';
            }
            if ($effect !== null && (!is_string($effect) || $effect !== $name)) {
                $errors[] = 'Der Bewegungseffekt muss dem Thema entsprechen.';
            }
        }
        if (strlen(Support::encodeJson($snapshot)) > self::MAX_PUBLIC_BYTES) {
            $errors[] = 'Der öffentliche Snapshot ist zu groß.';
        }
        if ($errors !== []) {
            throw new ValidationException(array_values(array_unique($errors)));
        }
    }

    /** @param array<string,mixed> $entry */
    public static function derivedEntryState(array $entry, DateTimeImmutable $now): string
    {
        return match ($entry['intent'] ?? '') {
            'draft' => 'Entwurf',
            'archived' => 'Archiviert',
            'trashed' => 'Papierkorb',
            'approved' => self::derivedApprovedState($entry, $now),
            default => 'Ungültig',
        };
    }

    /** @param array<string,mixed> $entry */
    public static function isEntryVisible(array $entry, DateTimeImmutable $now): bool
    {
        if (($entry['intent'] ?? null) !== 'approved') {
            return false;
        }
        [$start, $end] = self::entryWindow($entry);
        return self::withinWindow($now->setTimezone(new DateTimeZone(Support::TIMEZONE)), $start, $end);
    }

    /** @param array<string,mixed> $entry */
    private static function derivedApprovedState(array $entry, DateTimeImmutable $now): string
    {
        [$start, $end] = self::entryWindow($entry);
        $now = $now->setTimezone(new DateTimeZone(Support::TIMEZONE));
        if ($start !== null && $now < $start) {
            return 'Geplant';
        }
        if ($end !== null && $now >= $end) {
            return 'Abgelaufen';
        }
        return 'Veröffentlicht';
    }

    /** @param array<string,mixed> $entry @return array{?DateTimeImmutable,?DateTimeImmutable} */
    private static function entryWindow(array $entry): array
    {
        $displayStart = self::optionalInstant($entry['displayStart'] ?? null);
        $approvedAt = self::optionalInstant($entry['approvedAt'] ?? null);
        $start = $displayStart ?? $approvedAt ?? self::instant((string)$entry['createdAt']);
        if ($approvedAt !== null && $approvedAt > $start) {
            $start = $approvedAt;
        }
        if (($entry['type'] ?? null) === 'event') {
            return [$start, self::eventEffectiveEnd($entry)];
        }
        return [$start, self::optionalInstant($entry['expiry'] ?? null)];
    }

    /** @param array<string,mixed> $entry */
    private static function eventEffectiveEnd(array $entry): DateTimeImmutable
    {
        $explicit = self::optionalInstant($entry['eventEnd'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        $start = self::instant((string)$entry['eventStart'])->setTimezone(new DateTimeZone(Support::TIMEZONE));
        return $start->setTime(0, 0)->modify('+1 day');
    }

    /** @param array<string,mixed> $entry */
    private static function publishedAt(array $entry): DateTimeImmutable
    {
        $displayStart = self::optionalInstant($entry['displayStart'] ?? null);
        $approvedAt = self::optionalInstant($entry['approvedAt'] ?? null);
        if ($displayStart !== null && $approvedAt !== null) {
            return $displayStart > $approvedAt ? $displayStart : $approvedAt;
        }
        return $displayStart ?? $approvedAt ?? self::instant((string)$entry['createdAt']);
    }

    /**
     * @param array<string,array<string,mixed>> $assets
     * @param array<string,mixed> $config
     * @return array{url:string,width:int,height:int,alt:string}|null
     */
    private static function publicImage(array $entry, array $assets, array $config): ?array
    {
        $imageId = $entry['imageId'] ?? null;
        if (!is_string($imageId) || !isset($assets[$imageId])) {
            return null;
        }
        $asset = $assets[$imageId];
        $variants = is_array($asset['variants'] ?? null) ? $asset['variants'] : [];
        usort($variants, static function (mixed $a, mixed $b): int {
            $aWebp = is_array($a) && ($a['mime'] ?? null) === 'image/webp' ? 0 : 1;
            $bWebp = is_array($b) && ($b['mime'] ?? null) === 'image/webp' ? 0 : 1;
            if ($aWebp !== $bWebp) {
                return $aWebp <=> $bWebp;
            }
            return (int)($b['width'] ?? 0) <=> (int)($a['width'] ?? 0);
        });
        foreach ($variants as $variant) {
            if (!is_array($variant) || !isset($variant['file'], $variant['width'], $variant['height'])) {
                continue;
            }
            $file = (string)$variant['file'];
            try {
                Support::safeBasename($file);
            } catch (ValidationException) {
                continue;
            }
            $absolute = rtrim((string)$config['public_media_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
            if (!is_file($absolute)) {
                continue;
            }
            return [
                // Encode each path segment while preserving directory separators.
                // Encoding the complete path would turn `news/photo.webp` into
                // `news%2Fphoto.webp`, which many IONOS web servers do not map
                // back to the nested public media file.
                'url' => rtrim((string)$config['public_media_url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $file))),
                'width' => (int)$variant['width'],
                'height' => (int)$variant['height'],
                'alt' => (string)$entry['imageAlt'],
            ];
        }
        return null;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function comparePublicEntries(array $left, array $right, DateTimeImmutable $now): int
    {
        $rank = static function (array $entry) use ($now): array {
            if ($entry['type'] === 'event') {
                $start = self::instant((string)$entry['eventStart']);
                if ($start <= $now) {
                    $end = is_string($entry['eventEnd'])
                        ? self::instant($entry['eventEnd'])
                        : $start->setTime(0, 0)->modify('+1 day');
                    return [0, $end->getTimestamp(), (string)$entry['id']];
                }
                return [1, $start->getTimestamp(), (string)$entry['id']];
            }
            // Reverse ISO instant lexicographically by using a timestamp.
            return [2, -self::instant((string)$entry['publishedAt'])->getTimestamp(), (string)$entry['id']];
        };
        return $rank($left) <=> $rank($right);
    }

    /**
     * @param array<string,mixed> $themes
     * @param array<string,mixed> $config
     * @return array{array{name:?string,effect:?string},list<DateTimeImmutable>}
     */
    private static function resolveTheme(array $themes, array $config, DateTimeImmutable $now): array
    {
        $mode = (string)$themes['mode'];
        $transitions = self::themeTransitions($themes, $now);
        if ($mode === 'off') {
            return [['name' => null, 'effect' => null], $transitions];
        }
        if ($mode !== 'automatic') {
            return [[
                'name' => $mode,
                'effect' => in_array($mode, $config['available_effects'], true) ? $mode : null,
            ], $transitions];
        }

        $name = null;
        $date = $now->format('Y-m-d');
        $year = (int)$now->format('Y');
        if ($date >= sprintf('%04d-12-01', $year) || $date <= sprintf('%04d-01-06', $year)) {
            $name = 'christmas';
        } elseif ($date >= sprintf('%04d-01-07', $year) && $date <= sprintf('%04d-02-%02d', $year, self::lastFebruaryDay($year))) {
            $name = 'winter';
        } else {
            foreach (['spring', 'summer', 'autumn'] as $candidate) {
                $startString = $themes['windows'][$candidate] ?? null;
                if (!is_string($startString)) {
                    continue;
                }
                $endString = self::localDate($startString)->modify('+13 days')->format('Y-m-d');
                if ($date >= $startString && $date <= $endString) {
                    $name = $candidate;
                    break;
                }
            }
        }
        return [[
            'name' => $name,
            'effect' => $name !== null && in_array($name, $config['available_effects'], true) ? $name : null,
        ], $transitions];
    }

    /** @param array<string,mixed> $themes @return list<DateTimeImmutable> */
    private static function themeTransitions(array $themes, DateTimeImmutable $now): array
    {
        $transitions = [];
        $year = (int)$now->format('Y');
        for ($candidateYear = $year - 1; $candidateYear <= $year + 2; $candidateYear++) {
            $fixed = [
                sprintf('%04d-01-07', $candidateYear),
                sprintf('%04d-03-01', $candidateYear),
                sprintf('%04d-12-01', $candidateYear),
                sprintf('%04d-01-07', $candidateYear + 1),
            ];
            foreach ($fixed as $date) {
                $transitions[] = self::localDate($date);
            }
        }
        foreach (['spring', 'summer', 'autumn'] as $candidate) {
            $start = $themes['windows'][$candidate] ?? null;
            if (is_string($start)) {
                $transitions[] = self::localDate($start);
                $transitions[] = self::localDate($start)->modify('+14 days');
            }
        }
        return $transitions;
    }

    /** @param list<array<string,mixed>> $entries @param list<array<string,mixed>> $exceptions @param list<string> $errors */
    private static function validatePublicCaps(array $entries, array $exceptions, DateTimeImmutable $now, array &$errors): void
    {
        $boundaries = [$now];
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['intent'] ?? null) !== 'approved') {
                continue;
            }
            try {
                [$start, $end] = self::entryWindow($entry);
                if ($start !== null) {
                    $boundaries[] = $start;
                }
                if ($end !== null) {
                    $boundaries[] = $end->modify('-1 second');
                }
            } catch (Throwable) {
                // The field-level error is more useful and is already recorded.
            }
        }
        foreach ($boundaries as $boundary) {
            $visible = 0;
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                try {
                    if (self::isEntryVisible($entry, $boundary)) {
                        $visible++;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
            if ($visible > self::MAX_VISIBLE_ENTRIES) {
                $errors[] = 'Zu einem Zeitpunkt wären mehr als ' . self::MAX_VISIBLE_ENTRIES . ' Einträge gleichzeitig öffentlich.';
                break;
            }
        }

        $today = $now->setTimezone(new DateTimeZone(Support::TIMEZONE))->format('Y-m-d');
        $approvedNonExpired = 0;
        foreach ($exceptions as $exception) {
            if (is_array($exception) && ($exception['intent'] ?? null) === 'approved' && (string)($exception['endDate'] ?? '') >= $today) {
                $approvedNonExpired++;
            }
        }
        if ($approvedNonExpired > self::MAX_PUBLIC_EXCEPTIONS) {
            $errors[] = 'Höchstens ' . self::MAX_PUBLIC_EXCEPTIONS . ' nicht abgelaufene Ausnahmen dürfen freigegeben sein.';
        }
    }

    /** @param mixed $value @param array<string,string> $seen @param list<string> $errors */
    private static function validateEntry(mixed $value, int $position, array &$seen, array &$errors): void
    {
        $label = 'Eintrag ' . ($position + 1);
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $label . ' muss ein Objekt sein.';
            return;
        }
        $type = $value['type'] ?? null;
        $keys = ['id', 'type', 'intent', 'title', 'body', 'imageId', 'imageAlt', 'displayStart', 'approvedAt', 'createdAt', 'updatedAt'];
        if ($type === 'news') {
            $keys[] = 'expiry';
        } elseif ($type === 'event') {
            $keys[] = 'eventStart';
            $keys[] = 'eventEnd';
        }
        self::assertKeys($value, $keys, $label, $errors);
        self::validateId($value['id'] ?? null, $label . ': ID', $seen, $errors, (string)($value['intent'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            $errors[] = $label . ': Typ muss Neuigkeit oder Termin sein.';
        }
        if (!in_array($value['intent'] ?? null, self::INTENTS, true)) {
            $errors[] = $label . ': ungültige redaktionelle Absicht.';
        }
        self::validateText($value['title'] ?? null, $label . ': Titel', self::MAX_TITLE, false, $errors);
        self::validateText($value['body'] ?? null, $label . ': Text', self::MAX_BODY, false, $errors);
        self::validateNullableId($value['imageId'] ?? null, $label . ': Bild', $errors);
        self::validateText($value['imageAlt'] ?? null, $label . ': Bildbeschreibung', self::MAX_IMAGE_ALT, false, $errors);
        self::validateInstant($value['displayStart'] ?? null, $label . ': Sichtbar ab', true, $errors);
        self::validateInstant($value['approvedAt'] ?? null, $label . ': Freigabezeit', true, $errors);
        self::validateInstant($value['createdAt'] ?? null, $label . ': Erstellzeit', false, $errors);
        self::validateInstant($value['updatedAt'] ?? null, $label . ': Änderungszeit', false, $errors);

        if ($type === 'news') {
            self::validateInstant($value['expiry'] ?? null, $label . ': Ablauf', true, $errors);
            self::validateOrderedInstants($value['displayStart'] ?? null, $value['expiry'] ?? null, $label, $errors);
            if (($value['intent'] ?? null) === 'approved' && is_string($value['expiry'] ?? null)) {
                try {
                    $effectiveStart = self::effectiveStart($value);
                    if ($effectiveStart >= self::instant($value['expiry'])) {
                        $errors[] = $label . ': Freigabe/Sichtbarkeit muss vor dem Ablauf liegen.';
                    }
                } catch (Throwable) {
                    // Field-level validation reports malformed instants.
                }
            }
        }
        if ($type === 'event') {
            self::validateInstant($value['eventStart'] ?? null, $label . ': Terminbeginn', false, $errors);
            self::validateInstant($value['eventEnd'] ?? null, $label . ': Terminende', true, $errors);
            self::validateOrderedInstants($value['eventStart'] ?? null, $value['eventEnd'] ?? null, $label, $errors);
            if (is_string($value['displayStart'] ?? null) && is_string($value['eventStart'] ?? null)) {
                try {
                    $effectiveEnd = is_string($value['eventEnd'] ?? null)
                        ? self::instant($value['eventEnd'])
                        : self::instant($value['eventStart'])->setTime(0, 0)->modify('+1 day');
                    if (self::instant($value['displayStart']) >= $effectiveEnd) {
                        $errors[] = $label . ': Sichtbar ab muss vor dem Terminende liegen.';
                    }
                } catch (Throwable) {
                    // Field-level validation reports malformed instants.
                }
            }
            if (($value['intent'] ?? null) === 'approved' && is_string($value['eventStart'] ?? null)) {
                try {
                    $effectiveEnd = is_string($value['eventEnd'] ?? null)
                        ? self::instant($value['eventEnd'])
                        : self::instant($value['eventStart'])->setTime(0, 0)->modify('+1 day');
                    if (self::effectiveStart($value) >= $effectiveEnd) {
                        $errors[] = $label . ': Freigabe/Sichtbarkeit muss vor dem Terminende liegen.';
                    }
                } catch (Throwable) {
                    // Field-level validation reports malformed instants.
                }
            }
        }
        if (($value['intent'] ?? null) === 'approved') {
            if (!is_string($value['title'] ?? null) || trim((string)$value['title']) === '' || !is_string($value['body'] ?? null) || trim((string)$value['body']) === '') {
                $errors[] = $label . ': Freigabe erfordert Titel und aussagekräftigen Text.';
            }
            if (($value['imageId'] ?? null) !== null && trim((string)($value['imageAlt'] ?? '')) === '') {
                $errors[] = $label . ': Ein freigegebenes Bild braucht eine Bildbeschreibung.';
            }
            if (!is_string($value['approvedAt'] ?? null)) {
                $errors[] = $label . ': Ein freigegebener Eintrag braucht eine Freigabezeit.';
            }
        }
    }

    /** @param mixed $value @param array<string,string> $seen @param list<string> $errors */
    private static function validateException(mixed $value, int $position, array &$seen, array &$errors): void
    {
        $label = 'Ausnahme ' . ($position + 1);
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $label . ' muss ein Objekt sein.';
            return;
        }
        self::assertKeys($value, ['id', 'intent', 'target', 'startDate', 'endDate', 'closed', 'opens', 'closes', 'note', 'createdAt', 'updatedAt'], $label, $errors);
        self::validateId($value['id'] ?? null, $label . ': ID', $seen, $errors, (string)($value['intent'] ?? ''));
        if (!in_array($value['intent'] ?? null, self::INTENTS, true)) {
            $errors[] = $label . ': ungültige redaktionelle Absicht.';
        }
        if (!in_array($value['target'] ?? null, self::TARGETS, true)) {
            $errors[] = $label . ': Ziel muss Hofcafé, Hofladen oder beide sein.';
        }
        self::validateDate($value['startDate'] ?? null, $label . ': Beginn', $errors);
        self::validateDate($value['endDate'] ?? null, $label . ': Ende', $errors);
        if (is_string($value['startDate'] ?? null) && is_string($value['endDate'] ?? null) && $value['endDate'] < $value['startDate']) {
            $errors[] = $label . ': Das Ende darf nicht vor dem Beginn liegen.';
        }
        if (!is_bool($value['closed'] ?? null)) {
            $errors[] = $label . ': Geschlossen muss Ja oder Nein sein.';
        }
        self::validateText($value['note'] ?? null, $label . ': Hinweis', self::MAX_TITLE, false, $errors);
        self::validateInstant($value['createdAt'] ?? null, $label . ': Erstellzeit', false, $errors);
        self::validateInstant($value['updatedAt'] ?? null, $label . ': Änderungszeit', false, $errors);
        if (($value['closed'] ?? null) === true) {
            if (($value['opens'] ?? null) !== null || ($value['closes'] ?? null) !== null) {
                $errors[] = $label . ': Eine Schließung darf keine Ersatzzeiten enthalten.';
            }
        } else {
            self::validateTime($value['opens'] ?? null, $label . ': Öffnet', $errors);
            self::validateTime($value['closes'] ?? null, $label . ': Schließt', $errors);
            if (is_string($value['opens'] ?? null) && is_string($value['closes'] ?? null) && $value['closes'] <= $value['opens']) {
                $errors[] = $label . ': Die Schließzeit muss nach der Öffnungszeit liegen.';
            }
        }
    }

    /** @param mixed $value @param array<string,string> $seen @param list<string> $errors */
    private static function validateAsset(mixed $value, int $position, array &$seen, array &$errors): void
    {
        $label = 'Bild ' . ($position + 1);
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $label . ' muss ein Objekt sein.';
            return;
        }
        self::assertKeys($value, ['id', 'status', 'sourceFile', 'sourceMime', 'hash', 'width', 'height', 'variants', 'createdAt', 'trashedAt'], $label, $errors);
        self::validateId($value['id'] ?? null, $label . ': ID', $seen, $errors, (string)($value['status'] ?? ''));
        if (!in_array($value['status'] ?? null, ['active', 'trashed'], true)) {
            $errors[] = $label . ': ungültiger Bildstatus.';
        }
        try {
            Support::safeBasename(is_string($value['sourceFile'] ?? null) ? $value['sourceFile'] : '');
        } catch (ValidationException) {
            $errors[] = $label . ': ungültiger privater Dateiname.';
        }
        if (!in_array($value['sourceMime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $errors[] = $label . ': ungültiger Quelltyp.';
        }
        if (!is_string($value['hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $value['hash'])) {
            $errors[] = $label . ': ungültiger Inhalts-Hash.';
        }
        foreach (['width', 'height'] as $dimension) {
            if (!is_int($value[$dimension] ?? null) || $value[$dimension] < 1 || $value[$dimension] > 8000) {
                $errors[] = $label . ': ungültige Abmessungen.';
                break;
            }
        }
        $variants = self::listValue($value['variants'] ?? null, $label . ': Varianten', $errors);
        if ($variants === []) {
            $errors[] = $label . ': mindestens eine öffentliche Variante fehlt.';
        }
        foreach ($variants as $variant) {
            if (!is_array($variant) || array_is_list($variant)) {
                $errors[] = $label . ': ungültige Variante.';
                continue;
            }
            self::assertKeys($variant, ['file', 'width', 'height', 'mime', 'bytes'], $label . ': Variante', $errors);
            try {
                Support::safeBasename(is_string($variant['file'] ?? null) ? $variant['file'] : '');
            } catch (ValidationException) {
                $errors[] = $label . ': ungültiger Variantenname.';
            }
            if (!in_array($variant['mime'] ?? null, ['image/jpeg', 'image/webp'], true)) {
                $errors[] = $label . ': ungültiger Variantentyp.';
            }
            foreach (['width', 'height', 'bytes'] as $integer) {
                if (!is_int($variant[$integer] ?? null) || $variant[$integer] < 1) {
                    $errors[] = $label . ': ungültige Variantengröße.';
                    break;
                }
            }
        }
        self::validateInstant($value['createdAt'] ?? null, $label . ': Erstellzeit', false, $errors);
        self::validateInstant($value['trashedAt'] ?? null, $label . ': Papierkorbzeit', true, $errors);
    }

    /** @param mixed $value @param list<string> $errors */
    private static function validateThemes(mixed $value, array &$errors): void
    {
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = 'Themen müssen ein Objekt sein.';
            return;
        }
        self::assertKeys($value, ['mode', 'windows'], 'Themen', $errors);
        if (!in_array($value['mode'] ?? null, self::THEME_MODES, true)) {
            $errors[] = 'Ungültiger Themenmodus.';
        }
        $windows = $value['windows'] ?? null;
        if (!is_array($windows) || array_is_list($windows)) {
            $errors[] = 'Themenfenster müssen ein Objekt sein.';
            return;
        }
        self::assertKeys($windows, ['spring', 'summer', 'autumn'], 'Themenfenster', $errors);
        $ranges = [];
        foreach (['spring', 'summer', 'autumn'] as $name) {
            $start = $windows[$name] ?? null;
            if ($start === null) {
                continue;
            }
            self::validateDate($start, 'Themenfenster ' . $name, $errors);
            if (is_string($start) && self::validDate($start)) {
                $ranges[$name] = [$start, self::localDate($start)->modify('+13 days')->format('Y-m-d')];
            }
        }
        $names = array_keys($ranges);
        for ($i = 0; $i < count($names); $i++) {
            for ($j = $i + 1; $j < count($names); $j++) {
                if (self::rangesOverlap($ranges[$names[$i]], $ranges[$names[$j]])) {
                    $errors[] = 'Die bearbeitbaren Themenfenster dürfen sich nicht überschneiden.';
                }
            }
        }
        foreach ($ranges as [$start, $end]) {
            $year = (int)substr($start, 0, 4);
            for ($candidate = $year - 1; $candidate <= $year + 1; $candidate++) {
                $fixed = [
                    [sprintf('%04d-01-07', $candidate), sprintf('%04d-02-%02d', $candidate, self::lastFebruaryDay($candidate))],
                    [sprintf('%04d-12-01', $candidate), sprintf('%04d-01-06', $candidate + 1)],
                ];
                foreach ($fixed as $fixedRange) {
                    if (self::rangesOverlap([$start, $end], $fixedRange)) {
                        $errors[] = 'Ein bearbeitbares Themenfenster überschneidet Weihnachten oder Winter.';
                    }
                }
            }
        }
    }

    /** @param mixed $value @param list<string> $errors */
    private static function validateRecovery(mixed $value, array &$errors): void
    {
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = 'Wiederherstellungsdaten müssen ein Objekt sein.';
            return;
        }
        self::assertKeys($value, ['lastBackupAt', 'lastRestoreAt'], 'Wiederherstellung', $errors);
        self::validateInstant($value['lastBackupAt'] ?? null, 'Letzte Sicherung', true, $errors);
        self::validateInstant($value['lastRestoreAt'] ?? null, 'Letzte Wiederherstellung', true, $errors);
    }

    /** @param list<string> $errors */
    private static function validatePublicVersion(mixed $value, string $label, bool $allowZero, array &$errors): void
    {
        if (!is_array($value) || array_is_list($value)) {
            $errors[] = $label . ' muss ein Objekt sein.';
            return;
        }
        self::assertKeys($value, ['generation', 'sequence'], $label, $errors);
        if (!is_string($value['generation'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $value['generation'])) {
            $errors[] = $label . ': ungültige Generation.';
        }
        $minimum = $allowZero ? 0 : 1;
        if (!is_int($value['sequence'] ?? null) || $value['sequence'] < $minimum) {
            $errors[] = $label . ': ungültige Sequenz.';
        }
    }

    /** @param list<array<string,mixed>> $exceptions @param list<string> $errors */
    private static function validateExceptionConflicts(array $exceptions, array &$errors): void
    {
        $approved = array_values(array_filter($exceptions, static fn(mixed $item): bool => is_array($item) && ($item['intent'] ?? null) === 'approved'));
        for ($i = 0; $i < count($approved); $i++) {
            for ($j = $i + 1; $j < count($approved); $j++) {
                $left = $approved[$i];
                $right = $approved[$j];
                if (!isset($left['startDate'], $left['endDate'], $left['target'], $right['startDate'], $right['endDate'], $right['target'])) {
                    continue;
                }
                $targetsOverlap = $left['target'] === 'both' || $right['target'] === 'both' || $left['target'] === $right['target'];
                $datesOverlap = (string)$left['startDate'] <= (string)$right['endDate'] && (string)$right['startDate'] <= (string)$left['endDate'];
                if ($targetsOverlap && $datesOverlap) {
                    $errors[] = 'Freigegebene Öffnungs-Ausnahmen dürfen sich für dasselbe Ziel nicht überschneiden.';
                    return;
                }
            }
        }
    }

    /** @param array<string,mixed> $object @param list<string> $allowed @param list<string> $errors */
    private static function assertKeys(array $object, array $allowed, string $label, array &$errors): void
    {
        $missing = array_diff($allowed, array_keys($object));
        $unknown = array_diff(array_keys($object), $allowed);
        if ($missing !== []) {
            $errors[] = $label . ': Pflichtfelder fehlen (' . implode(', ', $missing) . ').';
        }
        if ($unknown !== []) {
            $errors[] = $label . ': unbekannte Felder (' . implode(', ', $unknown) . ').';
        }
    }

    /** @param list<string> $errors @return list<array<string,mixed>> */
    private static function listValue(mixed $value, string $label, array &$errors): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = $label . ' muss eine Liste sein.';
            return [];
        }
        return $value;
    }

    /** @param array<string,string> $seen @param list<string> $errors */
    private static function validateId(mixed $value, string $label, array &$seen, array &$errors, string $state): void
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{32}\z/', $value)) {
            $errors[] = $label . ' ist ungültig.';
            return;
        }
        if (isset($seen[$value])) {
            $errors[] = $label . ' ist doppelt.';
        }
        $seen[$value] = $state;
    }

    /** @param list<string> $errors */
    private static function validateNullableId(mixed $value, string $label, array &$errors): void
    {
        if ($value !== null && (!is_string($value) || !preg_match('/\A[a-f0-9]{32}\z/', $value))) {
            $errors[] = $label . ' ist ungültig.';
        }
    }

    /** @param list<string> $errors */
    private static function validateText(mixed $value, string $label, int $maximum, bool $required, array &$errors): void
    {
        if (!is_string($value) || !preg_match('//u', $value)) {
            $errors[] = $label . ' muss gültiger Text sein.';
            return;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
        if (!is_int($length)) {
            $errors[] = $label . ' konnte nicht sicher gezählt werden.';
            return;
        }
        if ($length > $maximum) {
            $errors[] = $label . ' darf höchstens ' . $maximum . ' Zeichen lang sein.';
        }
        if ($required && trim($value) === '') {
            $errors[] = $label . ' darf nicht leer sein.';
        }
    }

    /** @param list<string> $errors */
    private static function validateInstant(mixed $value, string $label, bool $nullable, array &$errors): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if (!is_string($value) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/', $value, $matches)) {
            $errors[] = $label . ' muss ein RFC-3339-Zeitpunkt mit Zeitzone sein.';
            return;
        }
        if (!checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
            $errors[] = $label . ' ist kein mögliches Kalenderdatum.';
            return;
        }
        try {
            new DateTimeImmutable($value);
        } catch (Throwable) {
            $errors[] = $label . ' ist kein gültiger Zeitpunkt.';
        }
    }

    /** @param list<string> $errors */
    private static function validateOrderedInstants(mixed $start, mixed $end, string $label, array &$errors): void
    {
        if (!is_string($start) || !is_string($end)) {
            return;
        }
        try {
            if (self::instant($end) <= self::instant($start)) {
                $errors[] = $label . ': Das Ende muss nach dem Beginn liegen.';
            }
        } catch (Throwable) {
            // Field-level validation reports the malformed value.
        }
    }

    /** @param list<string> $errors */
    private static function validateDate(mixed $value, string $label, array &$errors): void
    {
        if (!is_string($value) || !self::validDate($value)) {
            $errors[] = $label . ' muss ein echtes Datum im Format JJJJ-MM-TT sein.';
        }
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(Support::TIMEZONE));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value;
    }

    /** @param list<string> $errors */
    private static function validateTime(mixed $value, string $label, array &$errors): void
    {
        if (!is_string($value) || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $value)) {
            $errors[] = $label . ' muss eine Uhrzeit im Format HH:MM sein.';
        }
    }

    private static function instant(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(Support::TIMEZONE));
    }

    private static function optionalInstant(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? self::instant($value) : null;
    }

    /** @param array<string,mixed> $entry */
    private static function effectiveStart(array $entry): DateTimeImmutable
    {
        $displayStart = self::optionalInstant($entry['displayStart'] ?? null);
        $approvedAt = self::optionalInstant($entry['approvedAt'] ?? null);
        $start = $displayStart ?? $approvedAt ?? self::instant((string)$entry['createdAt']);
        return $approvedAt !== null && $approvedAt > $start ? $approvedAt : $start;
    }

    private static function localDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(Support::TIMEZONE));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException(['Ungültiges lokales Datum.']);
        }
        return $date;
    }

    private static function withinWindow(DateTimeImmutable $now, ?DateTimeImmutable $start, ?DateTimeImmutable $end): bool
    {
        return ($start === null || $now >= $start) && ($end === null || $now < $end);
    }

    /** @param list<DateTimeImmutable> $transitions */
    private static function addFutureTransition(array &$transitions, ?DateTimeImmutable $candidate, DateTimeImmutable $now): void
    {
        if ($candidate !== null && $candidate > $now) {
            $transitions[$candidate->format('U.uP')] = $candidate;
        }
    }

    /** @param array{string,string} $left @param array{string,string} $right */
    private static function rangesOverlap(array $left, array $right): bool
    {
        return $left[0] <= $right[1] && $right[0] <= $left[1];
    }

    private static function lastFebruaryDay(int $year): int
    {
        return (int)self::localDate(sprintf('%04d-02-01', $year))->format('t');
    }
}
