<?php
declare(strict_types=1);

use Hofladen\Editorial\AuthenticationException;
use Hofladen\Editorial\Config;
use Hofladen\Editorial\ConfigurationException;
use Hofladen\Editorial\ConflictException;
use Hofladen\Editorial\Domain;
use Hofladen\Editorial\Images;
use Hofladen\Editorial\Preflight;
use Hofladen\Editorial\PublicationException;
use Hofladen\Editorial\Repository;
use Hofladen\Editorial\Security;
use Hofladen\Editorial\Support;
use Hofladen\Editorial\ValidationException;

require_once dirname(__DIR__) . '/lib/bootstrap.php';

$assertions = 0;
function test_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param class-string<Throwable> $class */
function test_throws(string $class, callable $operation, string $message): void
{
    global $assertions;
    $assertions++;
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException($message . ' (falsche Ausnahme: ' . get_class($error) . ')', 0, $error);
    }
    throw new RuntimeException($message . ' (keine Ausnahme)');
}

/** @return array<string,mixed> */
function test_news(string $id, DateTimeImmutable $now, string $intent = 'approved'): array
{
    return [
        'id' => $id,
        'type' => 'news',
        'intent' => $intent,
        'title' => 'Hof-Neuigkeit',
        'body' => 'Ein klarer Text für Gäste.',
        'imageId' => null,
        'imageAlt' => '',
        'displayStart' => '2020-01-01T00:00:00+01:00',
        'approvedAt' => '2020-01-01T00:00:00+01:00',
        'createdAt' => '2020-01-01T00:00:00+01:00',
        'updatedAt' => '2020-01-01T00:00:00+01:00',
        'expiry' => '2030-01-01T00:00:00+01:00',
    ];
}

function test_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

/** @param array<string,mixed> $base @return array<string,mixed> */
function test_isolated_config(string $root, string $name, array $base): array
{
    $public = $root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'public';
    $private = $root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'private';
    $paths = [
        $public,
        $public . '/content/media',
        $private . '/data', $private . '/uploads', $private . '/ledger', $private . '/backups',
        $private . '/trash', $private . '/throttle', $private . '/cache',
    ];
    foreach ($paths as $path) {
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Isoliertes Testverzeichnis konnte nicht erstellt werden.');
        }
    }
    $config = $base;
    $config['document_root'] = $public;
    $config['private_data_dir'] = $private . '/data';
    $config['private_upload_dir'] = $private . '/uploads';
    $config['ledger_dir'] = $private . '/ledger';
    $config['backup_dir'] = $private . '/backups';
    $config['trash_dir'] = $private . '/trash';
    $config['throttle_dir'] = $private . '/throttle';
    $config['cache_dir'] = $private . '/cache';
    $config['public_media_dir'] = $public . '/content/media';
    Config::prepareStorage($config);
    return $config;
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofladen-editorial-test-' . Support::randomId(6);
$documentRoot = $root . DIRECTORY_SEPARATOR . 'public';
$private = $root . DIRECTORY_SEPARATOR . 'private';
$directories = [
    $documentRoot,
    $documentRoot . '/content/media',
    $private . '/data',
    $private . '/uploads',
    $private . '/ledger',
    $private . '/backups',
    $private . '/trash',
    $private . '/throttle',
    $private . '/cache',
];
foreach ($directories as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Testverzeichnis konnte nicht erstellt werden.');
    }
}
$config = [
    'environment' => 'test',
    'document_root' => $documentRoot,
    'admin_username' => 'owner',
    'admin_password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
    'throttle_secret' => str_repeat('a', 64),
    'private_data_dir' => $private . '/data',
    'private_upload_dir' => $private . '/uploads',
    'ledger_dir' => $private . '/ledger',
    'backup_dir' => $private . '/backups',
    'trash_dir' => $private . '/trash',
    'throttle_dir' => $private . '/throttle',
    'cache_dir' => $private . '/cache',
    'public_media_dir' => $documentRoot . '/content/media',
    'public_media_url' => '/content/media',
    'release_version' => 'test-v1',
    'available_effects' => ['winter'],
    'trusted_proxies' => [],
    'require_https' => false,
    'public_route_verified' => true,
    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 28800,
    'max_upload_bytes' => 12582912,
    'max_image_dimension' => 8000,
    'max_image_pixels' => 40000000,
    'backup_limit' => 30,
    'trash_retention_days' => 30,
    'pruning_enabled' => false,
];

try {
    Config::prepareStorage($config);
    $now = new DateTimeImmutable('2026-07-23T12:00:00+02:00');

    $loadConfig = test_isolated_config($root, 'config-load', $config);
    $loadAdmin = $loadConfig['document_root'] . '/admin';
    mkdir($loadAdmin, 0700, true);
    $loadConfigPath = $root . '/config-load/runtime.php';
    file_put_contents($loadConfigPath, "<?php\nreturn " . var_export($loadConfig, true) . ";\n");
    chmod($loadConfigPath, 0600);
    $locatorPath = $loadAdmin . '/.hofladen-config-path';
    file_put_contents($locatorPath, $loadConfigPath . "\n");
    chmod($locatorPath, 0600);
    putenv('HOFLADEN_CONFIG');
    $loadedConfig = Config::load($loadAdmin);
    test_assert($loadedConfig['document_root'] === $loadConfig['document_root'], 'Der nicht ausführbare IONOS-Locator lädt nur die externe Konfiguration.');

    $unsafeMarker = $root . '/config-load/unsafe-executed';
    $unsafeConfigPath = $loadConfig['document_root'] . '/unsafe-config.php';
    file_put_contents($unsafeConfigPath, "<?php\nfile_put_contents(" . var_export($unsafeMarker, true) . ", 'executed');\nreturn [];\n");
    chmod($unsafeConfigPath, 0600);
    putenv('HOFLADEN_CONFIG=' . $unsafeConfigPath);
    test_throws(ConfigurationException::class, static fn() => Config::load($loadAdmin), 'Eine Konfiguration im Dokumentstamm wird vor der Ausführung abgelehnt.');
    test_assert(!is_file($unsafeMarker), 'Unsicher lokalisierter PHP-Code wurde nicht ausgeführt.');

    $insecureConfig = $loadConfig;
    $insecureConfig['environment'] = 'production';
    $insecureConfig['require_https'] = false;
    $insecureConfigPath = $root . '/config-load/insecure.php';
    file_put_contents($insecureConfigPath, "<?php\nreturn " . var_export($insecureConfig, true) . ";\n");
    chmod($insecureConfigPath, 0600);
    putenv('HOFLADEN_CONFIG=' . $insecureConfigPath);
    test_throws(ConfigurationException::class, static fn() => Config::load($loadAdmin), 'Produktion kann HTTPS nicht abschalten.');

    $wrongMediaConfig = $loadConfig;
    $wrongMediaConfig['public_media_dir'] = $loadConfig['document_root'] . '/editorial-media';
    $wrongMediaPath = $root . '/config-load/wrong-media.php';
    file_put_contents($wrongMediaPath, "<?php\nreturn " . var_export($wrongMediaConfig, true) . ";\n");
    chmod($wrongMediaPath, 0600);
    putenv('HOFLADEN_CONFIG=' . $wrongMediaPath);
    test_throws(ConfigurationException::class, static fn() => Config::load($loadAdmin), 'Medienpfad und öffentliche URL können nicht auseinanderlaufen.');

    putenv('HOFLADEN_CONFIG');
    chmod($loadedConfig['public_media_dir'], 0777);
    $permissionChecks = Preflight::checks($loadedConfig);
    $mediaPermission = array_values(array_filter($permissionChecks, static fn(array $check): bool => $check['label'] === 'Rechte öffentliche Bildvarianten'));
    test_assert(count($mediaPermission) === 1 && $mediaPermission[0]['status'] === 'error', 'Weltbeschreibbare öffentliche Bildvarianten bestehen die Vorprüfung nicht.');
    chmod($loadedConfig['public_media_dir'], 0700);

    $initial = Domain::initialDocument($now);
    Domain::validateDocument($initial, $now);
    test_assert($initial['writeRevision'] === 0, 'Initialrevision ist null.');

    $unknown = $initial;
    $unknown['surprise'] = true;
    test_throws(ValidationException::class, static fn() => Domain::validateDocument($unknown, $now), 'Unbekannte Felder werden abgelehnt.');

    $repository = new Repository($config);
    $stored = $repository->readDocument();
    $entryId = '11111111111111111111111111111111';
    $entry = test_news($entryId, $now);
    $created = $repository->mutate(0, static function (array $document) use ($entry): array {
        $document['entries'][] = $entry;
        return $document;
    });
    test_assert($created['publicationComplete'] === true, 'Erste Veröffentlichung wird vollständig abgeglichen.');
    $snapshot1 = $repository->currentSnapshot($now);
    Domain::validatePublicSnapshot($snapshot1);
    test_assert(array_keys($snapshot1) === ['schemaVersion', 'releaseVersion', 'effectCapabilityVersion', 'generatedAt', 'nextTransitionAt', 'snapshotRevision', 'cacheValidator', 'newsVersion', 'entries', 'exceptions', 'theme'], 'Laufzeit-Snapshot hat exakt die versionierte Hülle.');
    test_assert($snapshot1['cacheValidator'] === $snapshot1['snapshotRevision'], 'Cache-Validator entspricht der Snapshot-Revision.');
    test_assert(count($snapshot1['entries']) === 1, 'Aktuelle Neuigkeit ist öffentlich.');
    test_assert($snapshot1['newsVersion']['sequence'] === 1, 'Erste Sichtbarkeit erhält Sequenz eins.');
    test_assert($snapshot1['entries'][0]['changeVersion']['sequence'] === 1, 'Eintrag trägt dieselbe Sequenz.');
    test_assert(!str_contains(Support::encodeJson($snapshot1), 'approvedAt'), 'Private Freigabefelder fehlen öffentlich.');

    test_throws(ConflictException::class, static fn() => $repository->mutate(0, static fn(array $document): array => $document), 'Veraltete Revision wird nicht überschrieben.');

    $revision1 = $snapshot1['snapshotRevision'];
    $draft = test_news('22222222222222222222222222222222', $now, 'draft');
    $draft['title'] = 'Geheime Vorschau';
    $draftSave = $repository->mutate(1, static function (array $document) use ($draft): array {
        $document['entries'][] = $draft;
        return $document;
    });
    test_assert($draftSave['publicationComplete'] === true, 'Entwurf wird sicher gespeichert.');
    $snapshotDraft = $repository->currentSnapshot($now);
    test_assert($snapshotDraft['snapshotRevision'] === $revision1, 'Entwurf ändert öffentlichen Validator nicht.');
    test_assert(!str_contains(Support::encodeJson($snapshotDraft), 'Geheime Vorschau'), 'Entwurf ist nicht abrufbar.');

    $edited = $repository->mutate(2, static function (array $document) use ($entryId): array {
        foreach ($document['entries'] as $position => $entry) {
            if ($entry['id'] === $entryId) {
                $document['entries'][$position]['title'] = 'Sichtbar geändert';
            }
        }
        return $document;
    });
    test_assert($edited['snapshot']['newsVersion']['sequence'] === 2, 'Sichtbare Materialänderung erhält neue Sequenz.');

    $windowOnly = $repository->mutate(3, static function (array $document) use ($entryId): array {
        foreach ($document['entries'] as $position => $entry) {
            if ($entry['id'] === $entryId) {
                $document['entries'][$position]['displayStart'] = '2021-01-01T00:00:00+01:00';
            }
        }
        return $document;
    });
    test_assert($windowOnly['snapshot']['newsVersion']['sequence'] === 2, 'Reine Fensteränderung bei fortbestehender Sichtbarkeit erhält keine Neu-Sequenz.');
    test_assert($windowOnly['snapshot']['snapshotRevision'] !== $edited['snapshot']['snapshotRevision'], 'Fensteränderung aktualisiert dennoch den allgemeinen Snapshot.');

    $archived = $repository->mutate(4, static function (array $document) use ($entryId): array {
        foreach ($document['entries'] as $position => $entry) {
            if ($entry['id'] === $entryId) {
                $document['entries'][$position]['intent'] = 'archived';
            }
        }
        return $document;
    });
    test_assert($archived['snapshot']['entries'] === [], 'Archivierter Eintrag verschwindet.');
    test_assert($archived['snapshot']['newsVersion']['sequence'] === 0, 'Abwesender Eintrag hinterlässt keinen Neu-Punkt.');

    $restored = $repository->mutate(5, static function (array $document) use ($entryId): array {
        foreach ($document['entries'] as $position => $entry) {
            if ($entry['id'] === $entryId) {
                $document['entries'][$position]['intent'] = 'approved';
                $document['entries'][$position]['approvedAt'] = Support::now()->modify('-1 second')->format(DateTimeInterface::RFC3339);
            }
        }
        return $document;
    });
    test_assert($restored['snapshot']['newsVersion']['sequence'] === 3, 'Wiederveröffentlichung erhält genau eine neue Sequenz.');
    unlink($config['cache_dir'] . '/current-v1.json');
    $afterCacheLoss = $repository->currentSnapshot($now);
    test_assert($afterCacheLoss['newsVersion']['sequence'] === 3, 'Cacheverlust verwendet keine Sequenz erneut.');

    $generationBeforeLedgerLoss = $afterCacheLoss['newsVersion']['generation'];
    unlink($config['ledger_dir'] . '/news-ledger-v1.json');
    $afterLedgerLoss = $repository->currentSnapshot($now);
    test_assert($afterLedgerLoss['newsVersion']['generation'] !== $generationBeforeLedgerLoss, 'Fehlender Ledger rotiert trotz scheinbar frischem Cache die Generation.');
    test_assert($afterLedgerLoss['newsVersion']['sequence'] === 1, 'Aktuelle Einträge werden nach Ledgerverlust in neuer Generation markiert.');

    $oldGeneration = $afterLedgerLoss['newsVersion']['generation'];
    $repository->rotateLedgerGeneration();
    $afterDisaster = $repository->currentSnapshot($now);
    test_assert($afterDisaster['newsVersion']['generation'] !== $oldGeneration, 'Unbeweisbarer Disaster-Stand rotiert die Generation.');
    test_assert($afterDisaster['newsVersion']['sequence'] === 1, 'Aktuelle Einträge werden in neuer Generation sichtbar markiert.');

    $eventDocument = Domain::initialDocument($now);
    $eventDocument['entries'][] = [
        'id' => '44444444444444444444444444444444', 'type' => 'event', 'intent' => 'approved',
        'title' => 'Abendtermin', 'body' => 'Findet heute statt.', 'imageId' => null, 'imageAlt' => '',
        'displayStart' => $now->modify('-1 day')->format(DateTimeInterface::RFC3339),
        'eventStart' => '2026-07-23T18:00:00+02:00', 'eventEnd' => null,
        'approvedAt' => $now->modify('-1 day')->format(DateTimeInterface::RFC3339),
        'createdAt' => $now->modify('-1 day')->format(DateTimeInterface::RFC3339),
        'updatedAt' => $now->modify('-1 day')->format(DateTimeInterface::RFC3339),
    ];
    Domain::validateDocument($eventDocument, $now);
    $eventProjection = Domain::publicProjection($eventDocument, $config, new DateTimeImmutable('2026-07-23T23:30:00+02:00'));
    test_assert(count($eventProjection['entries']) === 1, 'Termin ohne Ende bleibt bis Mitternacht sichtbar.');
    test_assert($eventProjection['entries'][0]['eventEnd'] === null, 'Abgeleitete Mitternacht wird nicht als erfundenes Terminende ausgegeben.');
    test_assert(count(Domain::publicProjection($eventDocument, $config, new DateTimeImmutable('2026-07-24T00:00:00+02:00'))['entries']) === 0, 'Termin endet an der nächsten lokalen Mitternacht.');
    $invalidDisplay = $eventDocument;
    $invalidDisplay['entries'][0]['displayStart'] = '2026-07-24T00:00:00+02:00';
    test_throws(ValidationException::class, static fn() => Domain::validateDocument($invalidDisplay, $now), 'Termin-Sichtbarkeit darf nicht erst am effektiven Ende beginnen.');

    $expiredNews = Domain::initialDocument($now);
    $expiredNews['entries'][] = test_news('77777777777777777777777777777777', $now);
    $expiredNews['entries'][0]['expiry'] = '2026-07-01T00:00:00+02:00';
    // Historical approvedAt remains structurally valid after natural expiry.
    Domain::validateDocument($expiredNews, $now);
    $expiredNews['entries'][0]['approvedAt'] = $now->format(DateTimeInterface::RFC3339);
    test_throws(ValidationException::class, static fn() => Domain::validateDocument($expiredNews, $now), 'Neu-Freigabe nach dem Ablauf wird abgelehnt.');

    $expiredEvent = $eventDocument;
    $expiredEvent['entries'][0]['eventStart'] = '2026-07-01T18:00:00+02:00';
    $expiredEvent['entries'][0]['displayStart'] = '2026-06-30T12:00:00+02:00';
    $expiredEvent['entries'][0]['approvedAt'] = '2026-06-30T12:00:00+02:00';
    Domain::validateDocument($expiredEvent, $now);
    $expiredEvent['entries'][0]['approvedAt'] = $now->format(DateTimeInterface::RFC3339);
    test_throws(ValidationException::class, static fn() => Domain::validateDocument($expiredEvent, $now), 'Neu-Freigabe nach dem Terminende wird abgelehnt.');

    $capDocument = Domain::initialDocument($now);
    for ($index = 0; $index < 51; $index++) {
        $date = $now->modify('+' . ($index + 1) . ' days')->format('Y-m-d');
        $capDocument['exceptions'][] = [
            'id' => str_pad(dechex($index + 100), 32, '0', STR_PAD_LEFT), 'intent' => 'approved', 'target' => 'cafe',
            'startDate' => $date, 'endDate' => $date, 'closed' => true, 'opens' => null, 'closes' => null, 'note' => '',
            'createdAt' => $now->format(DateTimeInterface::RFC3339), 'updatedAt' => $now->format(DateTimeInterface::RFC3339),
        ];
    }
    test_throws(ValidationException::class, static fn() => Domain::validateDocument($capDocument, $now), 'Die 51. freigegebene Ausnahme wird abgelehnt.');

    $boundaryConfig = test_isolated_config($root, 'boundary', $config);
    $boundaryRepository = new Repository($boundaryConfig);
    $boundaryNow = Support::now();
    $shortEntry = test_news('55555555555555555555555555555555', $boundaryNow);
    $shortEntry['expiry'] = $boundaryNow->modify('+2 seconds')->format(DateTimeInterface::RFC3339);
    $firstBoundary = $boundaryRepository->mutate(0, static function (array $document) use ($shortEntry): array {
        $document['entries'][] = $shortEntry;
        return $document;
    });
    test_assert($firstBoundary['snapshot']['newsVersion']['sequence'] === 1, 'Kurz sichtbarer Eintrag erhält erste Sequenz.');
    sleep(3);
    $extendedBoundary = $boundaryRepository->mutate(1, static function (array $document): array {
        $document['entries'][0]['expiry'] = '2030-01-01T00:00:00+01:00';
        return $document;
    });
    test_assert($extendedBoundary['snapshot']['newsVersion']['sequence'] === 2, 'Abgelaufener Eintrag wird vor Verlängerung abgeglichen und erhält genau eine neue Sequenz.');

    $oversizeConfig = test_isolated_config($root, 'oversize', $config);
    $oversizeDocument = Domain::initialDocument(Support::now());
    for ($index = 0; $index < 30; $index++) {
        $large = test_news(str_pad(dechex($index + 1000), 32, '0', STR_PAD_LEFT), Support::now());
        $large['title'] = 'Großer Test ' . $index;
        $large['body'] = str_repeat('😀', 3000);
        $oversizeDocument['entries'][] = $large;
    }
    Domain::validateDocument($oversizeDocument);
    file_put_contents($oversizeConfig['private_data_dir'] . '/editorial-v1.json', Support::encodeJson($oversizeDocument), LOCK_EX);
    $oversizeRepository = new Repository($oversizeConfig);
    test_throws(PublicationException::class, static fn() => $oversizeRepository->currentSnapshot(), 'Übergroßer Alt-Snapshot wird nicht ausgeliefert.');
    $corrected = $oversizeRepository->mutate(0, static function (array $document): array {
        foreach ($document['entries'] as $position => $entry) {
            $document['entries'][$position]['intent'] = 'archived';
        }
        return $document;
    });
    test_assert($corrected['publicationComplete'] === true && $corrected['snapshot']['entries'] === [], 'Korrekturedit bleibt trotz übergroßem Alt-Snapshot möglich.');

    $futureOversizeConfig = test_isolated_config($root, 'future-oversize', $config);
    $futureOversizeRepository = new Repository($futureOversizeConfig);
    $futureStart = Support::now()->modify('+1 day')->format(DateTimeInterface::RFC3339);
    $futureEntries = [];
    for ($index = 0; $index < 30; $index++) {
        $large = test_news(str_pad(dechex($index + 2000), 32, '0', STR_PAD_LEFT), Support::now());
        $large['title'] = 'Geplanter großer Test ' . $index;
        $large['body'] = str_repeat('😀', 3000);
        $large['displayStart'] = $futureStart;
        $futureEntries[] = $large;
    }
    test_throws(ValidationException::class, static fn() => $futureOversizeRepository->mutate(0, static function (array $document) use ($futureEntries): array {
        $document['entries'] = $futureEntries;
        return $document;
    }), 'Ein erst an einer Zukunftsgrenze zu großer Snapshot wird bereits beim Speichern abgelehnt.');

    $fractionalConfig = test_isolated_config($root, 'fractional-oversize', $config);
    $fractionalRepository = new Repository($fractionalConfig);
    $futureSecond = Support::now()->modify('+2 days')->format('Y-m-d\TH:i:s');
    $fractionalEntries = [];
    // Later starts come first so a second-only boundary key would be
    // overwritten by the earlier group and miss the combined state.
    foreach (['.900000', '.100000'] as $fraction) {
        for ($index = 0; $index < 15; $index++) {
            $number = count($fractionalEntries) + 3000;
            $large = test_news(str_pad(dechex($number), 32, '0', STR_PAD_LEFT), Support::now());
            $large['title'] = 'Teilsekunden-Test ' . $number;
            $large['body'] = str_repeat('😀', 3000);
            $large['displayStart'] = $futureSecond . $fraction . '+02:00';
            $fractionalEntries[] = $large;
        }
    }
    test_throws(ValidationException::class, static fn() => $fractionalRepository->mutate(0, static function (array $document) use ($fractionalEntries): array {
        $document['entries'] = $fractionalEntries;
        return $document;
    }), 'Teilsekunden-Grenzen im selben Zeitpunkt-Sekundenfeld werden getrennt auf Größe geprüft.');

    if (extension_loaded('gd')
        && extension_loaded('fileinfo')
        && function_exists('imagepng')
        && function_exists('imagecreatefrompng')
        && function_exists('imagewebp')) {
        $uploadConfig = test_isolated_config($root, 'upload', $config);
        $uploadPath = $root . '/upload-test.png';
        $canvas = imagecreatetruecolor(8, 6);
        $colour = imagecolorallocate($canvas, 32, 160, 96);
        imagefilledrectangle($canvas, 0, 0, 7, 5, $colour);
        imagepng($canvas, $uploadPath);
        imagedestroy($canvas);

        $uploadImages = new Images($uploadConfig);
        $uploaded = $uploadImages->createFromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $uploadPath]);
        test_assert($uploaded['sourceMime'] === 'image/png' && count($uploaded['variants']) === 2, 'Gültiges PNG wird dekodiert und als begrenztes WebP/JPEG-Paar neu kodiert.');
        test_assert(is_file($uploadConfig['private_upload_dir'] . '/' . $uploaded['sourceFile']), 'Das Quellbild bleibt im privaten Speicher.');
        foreach ($uploaded['variants'] as $variant) {
            $variantPath = $uploadConfig['public_media_dir'] . '/' . $variant['file'];
            $variantInfo = getimagesize($variantPath);
            test_assert(is_array($variantInfo) && $variantInfo['mime'] === $variant['mime'] && filesize($variantPath) === $variant['bytes'], 'Öffentliche Variante ist ein wirklich dekodierbares, vermessenes Rasterbild.');
        }
        $uploadImages->discardCreated($uploaded);
        test_assert(!is_file($uploadConfig['private_upload_dir'] . '/' . $uploaded['sourceFile']), 'Fehlgeschlagene Folgeschritte können einen neuen Upload vollständig verwerfen.');

        $fakeImage = $root . '/renamed-image.png';
        file_put_contents($fakeImage, 'kein bild');
        test_throws(ValidationException::class, static fn() => $uploadImages->createFromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $fakeImage]), 'Umbenannter Nicht-Bildinhalt wird abgelehnt.');

        $smallByteConfig = $uploadConfig;
        $smallByteConfig['max_upload_bytes'] = 4;
        test_throws(ValidationException::class, static fn() => (new Images($smallByteConfig))->createFromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $uploadPath]), 'Konfiguriertes Upload-Bytelimit wird durchgesetzt.');
        $smallDimensionConfig = $uploadConfig;
        $smallDimensionConfig['max_image_dimension'] = 4;
        test_throws(ValidationException::class, static fn() => (new Images($smallDimensionConfig))->createFromUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $uploadPath]), 'Konfiguriertes Bildmaßlimit wird vor dem Dekodieren durchgesetzt.');
    }

    $restoreConfig = test_isolated_config($root, 'restore', $config);
    $restoreRepository = new Repository($restoreConfig);
    $asset = [
        'id' => '66666666666666666666666666666666', 'status' => 'active', 'sourceFile' => '66666666666666666666666666666666.jpg',
        'sourceMime' => 'image/jpeg', 'hash' => str_repeat('6', 64), 'width' => 320, 'height' => 240,
        'variants' => [['file' => '66666666666666666666666666666666-6666666666666666-320.jpg', 'width' => 320, 'height' => 240, 'mime' => 'image/jpeg', 'bytes' => 10]],
        'createdAt' => Support::now()->format(DateTimeInterface::RFC3339), 'trashedAt' => null,
    ];
    file_put_contents($restoreConfig['private_upload_dir'] . '/' . $asset['sourceFile'], 'source-bytes');
    file_put_contents($restoreConfig['public_media_dir'] . '/' . $asset['variants'][0]['file'], 'variant-bytes');
    $imageService = new Images($restoreConfig);
    $imageService->withStatusLock(static function () use ($imageService, $asset, $restoreConfig): void {
        $transaction = $imageService->stageStatusChange($asset, true);
        test_assert(is_file($restoreConfig['private_upload_dir'] . '/' . $asset['sourceFile']), 'Bildstatus-Staging lässt den autoritativen alten Satz vollständig.');
        $imageService->rollbackStatusChange($transaction);
        test_assert(is_file($restoreConfig['private_upload_dir'] . '/' . $asset['sourceFile']), 'Bildstatus-Rollback erhält die Quelle.');
    });
    $imageService->withStatusLock(static function () use ($imageService, $asset, $restoreConfig): void {
        $transaction = $imageService->stageStatusChange($asset, true);
        // Simulate a worker dying after copies were staged but before CAS.
        $retry = $imageService->stageStatusChange($asset, true);
        test_assert(is_file($restoreConfig['private_upload_dir'] . '/' . $asset['sourceFile']), 'Wiederholtes Staging erhält den autoritativen aktiven Satz.');
        $imageService->rollbackStatusChange($retry);
        test_assert(!is_file($restoreConfig['trash_dir'] . '/assets/' . $asset['id'] . '/source/' . $asset['sourceFile']), 'Wiederholtes Staging bereinigt verwaiste identische Kopien.');
        unset($transaction);
    });
    $restoreRepository->mutate(0, static function (array $document) use ($asset): array {
        $document['assets'][] = $asset;
        return $document;
    });
    $restoreRepository->mutate(1, static function (array $document): array {
        $document['assets'][0]['status'] = 'trashed';
        $document['assets'][0]['trashedAt'] = Support::now()->format(DateTimeInterface::RFC3339);
        return $document;
    });
    // Simulate a committed trash transition whose final old-copy cleanup failed:
    // both sides exist while the document authoritatively says "trashed".
    $trashSource = $restoreConfig['trash_dir'] . '/assets/' . $asset['id'] . '/source/' . $asset['sourceFile'];
    $trashVariant = $restoreConfig['trash_dir'] . '/assets/' . $asset['id'] . '/variants/' . $asset['variants'][0]['file'];
    if (!is_dir(dirname($trashSource))) {
        mkdir(dirname($trashSource), 0700, true);
    }
    if (!is_dir(dirname($trashVariant))) {
        mkdir(dirname($trashVariant), 0700, true);
    }
    copy($restoreConfig['private_upload_dir'] . '/' . $asset['sourceFile'], $trashSource);
    copy($restoreConfig['public_media_dir'] . '/' . $asset['variants'][0]['file'], $trashVariant);
    $trashedAsset = $asset;
    $trashedAsset['status'] = 'trashed';
    $trashedAsset['trashedAt'] = Support::now()->format(DateTimeInterface::RFC3339);
    $imageService->withStatusLock(static function () use ($imageService, $trashedAsset, $restoreConfig): void {
        $restoreFiles = $imageService->stageStatusChange($trashedAsset, false);
        test_assert(is_file($restoreConfig['trash_dir'] . '/assets/' . $trashedAsset['id'] . '/source/' . $trashedAsset['sourceFile']), 'Wiederherstellungs-Staging behält den autoritativen Papierkorb-Satz.');
        test_assert($imageService->commitStatusChange($restoreFiles), 'Wiederherstellung kann identische Altduplikate selbst heilen.');
    });
    $activeBackup = null;
    foreach ($restoreRepository->listBackups() as $backup) {
        if (str_contains($backup['name'], '-r1-')) {
            $activeBackup = $backup['name'];
            break;
        }
    }
    test_assert(is_string($activeBackup), 'Sicherung mit aktivem Bildstatus wurde angelegt.');
    $restoredBackup = $restoreRepository->restoreBackup((string)$activeBackup, 2);
    test_assert($restoredBackup['document']['assets'][0]['status'] === 'trashed', 'Daten-Restore reaktiviert keine Bildakte ohne physische Wiederherstellung.');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $security = new Security($config);
    $security->startSession();
    $sessionBeforeLogin = session_id();
    test_assert($security->attemptLogin('owner', 'wrong password') === false, 'Falsches Passwort wird abgelehnt.');
    $throttleJson = file_get_contents($config['throttle_dir'] . '/login-throttle-v1.json');
    test_assert(is_string($throttleJson) && !str_contains($throttleJson, '203.0.113.77'), 'Drosselung speichert keine rohe IP-Adresse.');
    test_assert($security->attemptLogin('owner', 'correct horse battery staple') === true, 'Gültige Anmeldung funktioniert.');
    test_assert(session_id() !== $sessionBeforeLogin, 'Sitzungs-ID wechselt bei erfolgreicher Anmeldung.');
    $token = $security->csrfToken();
    test_throws(AuthenticationException::class, static fn() => $security->assertPostWithCsrf('wrong'), 'Ungültiges CSRF-Token wird abgelehnt.');
    test_assert($security->isAuthenticated(), 'Ungültiges CSRF-Token meldet ab, ändert die Anmeldung aber nicht selbst.');
    $security->assertPostWithCsrf($token);
    test_assert(true, 'Gültiges CSRF-Token wird akzeptiert.');
    $security->logout();

    $_SERVER['REMOTE_ADDR'] = '198.51.100.91';
    $blockedSecurity = new Security($config);
    $blockedSecurity->startSession();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        test_assert($blockedSecurity->attemptLogin('owner', 'wrong-' . $attempt) === false, 'Fehlversuch wird abgelehnt.');
    }
    test_assert($blockedSecurity->attemptLogin('owner', 'correct horse battery staple') === false, 'Sechster Versuch ist während des begrenzten Sperrfensters blockiert.');

    $privateFixture = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/editorial-v1.json'), true, 64, JSON_THROW_ON_ERROR);
    Domain::validateDocument($privateFixture);
    test_assert($privateFixture['schemaVersion'] === 1, 'Private Fixture entspricht dem Laufzeitmodell.');
    $fixture = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/public-v1.json'), true, 64, JSON_THROW_ON_ERROR);
    Domain::validatePublicSnapshot($fixture);
    test_assert(array_keys($fixture) === ['schemaVersion', 'releaseVersion', 'effectCapabilityVersion', 'generatedAt', 'nextTransitionAt', 'snapshotRevision', 'cacheValidator', 'newsVersion', 'entries', 'exceptions', 'theme'], 'Öffentliche Fixture hat exakt die vereinbarte Hülle.');

    fwrite(STDOUT, "OK — {$assertions} Prüfungen\n");
} finally {
    putenv('HOFLADEN_CONFIG');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    test_remove_tree($root);
}
