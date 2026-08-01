<?php
declare(strict_types=1);

if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
}
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

use Hofladen\Editorial\AuthenticationException;
use Hofladen\Editorial\Config;
use Hofladen\Editorial\ConflictException;
use Hofladen\Editorial\Domain;
use Hofladen\Editorial\Images;
use Hofladen\Editorial\Preflight;
use Hofladen\Editorial\Repository;
use Hofladen\Editorial\Security;
use Hofladen\Editorial\Support;
use Hofladen\Editorial\ValidationException;
require_once __DIR__ . '/lib/bootstrap.php';

/** @return never */
function hof_redirect(string $location): void
{
    header('Location: ' . $location, true, 303);
    exit;
}

function hof_h(mixed $value): string
{
    return Support::html(is_scalar($value) ? (string)$value : '');
}

function hof_post(string $key, string $fallback = ''): string
{
    $value = $_POST[$key] ?? $fallback;
    return is_string($value) ? $value : $fallback;
}

function hof_expected_revision(): int
{
    $value = hof_post('expectedRevision');
    if (!preg_match('/\A\d+\z/', $value)) {
        throw new ValidationException(['Der erwartete Bearbeitungsstand fehlt. Bitte neu laden.']);
    }
    return (int)$value;
}

/** @return never */
function hof_storage_unavailable(Throwable $error): void
{
    $reference = Support::randomId(4);
    if (function_exists('error_log')) {
        @error_log('[hofladen-admin ' . $reference . '] ' . get_class($error));
    }
    http_response_code(503);
    ?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redaktionsdaten nicht verfügbar</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="login-shell"><section class="panel"><h1>Redaktionsdaten nicht verfügbar</h1><p>Der Redaktionsbereich hat keine verifizierbaren Daten geladen und nimmt keine Änderung vor. Bitte die Server-Vorprüfung ausführen und bei Bedarf eine geprüfte Sicherung wiederherstellen.</p><p>Fehlerkennung: <?= hof_h($reference) ?></p></section></main></body></html>
<?php
    exit;
}

/** @return array<string,mixed> */
function hof_read_document_or_fail(Repository $repository): array
{
    try {
        return $repository->readDocument();
    } catch (Throwable $error) {
        hof_storage_unavailable($error);
    }
}

/** @return list<array{name:string,modifiedAt:string,bytes:int}> */
function hof_list_backups_or_fail(Repository $repository): array
{
    try {
        return $repository->listBackups();
    } catch (Throwable $error) {
        hof_storage_unavailable($error);
    }
}

function hof_local_instant(string $value, bool $nullable, string $label): ?string
{
    if ($value === '' && $nullable) {
        return null;
    }
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value)) {
        throw new ValidationException([$label . ' ist ungültig.']);
    }
    $zone = new DateTimeZone(Support::TIMEZONE);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
    $parseErrors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $value) {
        throw new ValidationException([$label . ' ist wegen Zeitumstellung oder Eingabefehler nicht eindeutig gültig.']);
    }
    // Reject the repeated wall-clock interval at the autumn DST transition.
    $wall = (int)(DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'))?->getTimestamp() ?? 0);
    $transitions = $zone->getTransitions($date->getTimestamp() - 10800, $date->getTimestamp() + 10800);
    $previousOffset = null;
    foreach ($transitions as $transition) {
        $offset = (int)$transition['offset'];
        if ($previousOffset !== null && $offset < $previousOffset) {
            $repeatedStart = (int)$transition['ts'] + $offset;
            $repeatedEnd = (int)$transition['ts'] + $previousOffset;
            if ($wall >= $repeatedStart && $wall < $repeatedEnd) {
                throw new ValidationException([$label . ' liegt in einer doppelt vorkommenden Stunde der Zeitumstellung. Bitte eine andere Uhrzeit wählen.']);
            }
        }
        $previousOffset = $offset;
    }
    return $date->format(DateTimeInterface::RFC3339);
}

function hof_datetime_local(mixed $value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $value)) {
        return $value;
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(Support::TIMEZONE))->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}

/** @param list<array<string,mixed>> $items @return array<string,mixed>|null */
function hof_find(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && ($item['id'] ?? null) === $id) {
            return $item;
        }
    }
    return null;
}

/** @param array<string,mixed> $document @param array<string,mixed>|null $preserved @return array<string,mixed> */
function hof_posted_entry(array $document, ?array &$preserved = null): array
{
    $id = hof_post('id');
    $existing = preg_match('/\A[a-f0-9]{32}\z/', $id) ? hof_find($document['entries'], $id) : null;
    if ($existing === null) {
        $id = Support::randomId(16);
    }
    $type = hof_post('type');
    $intent = hof_post('intent');
    // Preserve raw browser values before timezone parsing can reject a DST gap
    // or repeated hour. The conflict/error page must not lose the owner's text.
    $preserved = [
        'id' => $id,
        'type' => $type,
        'intent' => $intent,
        'title' => hof_post('title'),
        'body' => hof_post('body'),
        'imageId' => hof_post('imageId') === '' ? null : hof_post('imageId'),
        'imageAlt' => hof_post('imageAlt'),
        'displayStart' => hof_post('displayStart'),
        'expiry' => hof_post('expiry'),
        'eventStart' => hof_post('eventStart'),
        'eventEnd' => hof_post('eventEnd'),
    ];
    if (!in_array($type, ['news', 'event'], true) || !in_array($intent, ['draft', 'approved', 'archived', 'trashed'], true)) {
        throw new ValidationException(['Typ oder redaktionelle Absicht ist ungültig.']);
    }
    $now = Support::now()->format(DateTimeInterface::RFC3339);
    $wasApproved = is_array($existing) && ($existing['intent'] ?? null) === 'approved';
    $entry = [
        'id' => $id,
        'type' => $type,
        'intent' => $intent,
        'title' => trim(hof_post('title')),
        'body' => trim(hof_post('body')),
        'imageId' => hof_post('imageId') === '' ? null : hof_post('imageId'),
        'imageAlt' => trim(hof_post('imageAlt')),
        'displayStart' => hof_local_instant(hof_post('displayStart'), true, 'Sichtbar ab'),
        'approvedAt' => $intent === 'approved' ? ($wasApproved ? $existing['approvedAt'] : $now) : ($existing['approvedAt'] ?? null),
        'createdAt' => $existing['createdAt'] ?? $now,
        'updatedAt' => $now,
    ];
    if ($type === 'news') {
        $entry['expiry'] = hof_local_instant(hof_post('expiry'), true, 'Ablauf');
    } else {
        $entry['eventStart'] = hof_local_instant(hof_post('eventStart'), false, 'Terminbeginn');
        $entry['eventEnd'] = hof_local_instant(hof_post('eventEnd'), true, 'Terminende');
    }
    return $entry;
}

/** @param array<string,mixed> $document @return array<string,mixed> */
function hof_posted_exception(array $document): array
{
    $id = hof_post('id');
    $existing = preg_match('/\A[a-f0-9]{32}\z/', $id) ? hof_find($document['exceptions'], $id) : null;
    if ($existing === null) {
        $id = Support::randomId(16);
    }
    $intent = hof_post('intent');
    $target = hof_post('target');
    if (!in_array($intent, ['draft', 'approved', 'archived', 'trashed'], true) || !in_array($target, ['cafe', 'shop', 'both'], true)) {
        throw new ValidationException(['Absicht oder Ziel der Ausnahme ist ungültig.']);
    }
    $closed = hof_post('closed') === '1';
    $now = Support::now()->format(DateTimeInterface::RFC3339);
    return [
        'id' => $id,
        'intent' => $intent,
        'target' => $target,
        'startDate' => hof_post('startDate'),
        'endDate' => hof_post('endDate'),
        'closed' => $closed,
        'opens' => $closed ? null : hof_post('opens'),
        'closes' => $closed ? null : hof_post('closes'),
        'note' => trim(hof_post('note')),
        'createdAt' => $existing['createdAt'] ?? $now,
        'updatedAt' => $now,
    ];
}

$fatalConfiguration = false;
$errors = [];
$notice = null;
$preservedEntry = null;
$preservedException = null;

try {
    $config = Config::load(__DIR__);
    Config::prepareStorage($config);
    $security = new Security($config);
    $security->sendAdminHeaders();
    $security->startSession();
    $repository = new Repository($config);
    $images = new Images($config);
} catch (Throwable) {
    $fatalConfiguration = true;
}

if ($fatalConfiguration):
    http_response_code(503);
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redaktionsbereich nicht verfügbar</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="login-shell"><section class="panel"><h1>Redaktionsbereich nicht verfügbar</h1><p>Die private Konfiguration oder ein Speicherpfad ist noch nicht sicher eingerichtet. Bitte die Server-Vorprüfung über die Kommandozeile ausführen.</p></section></main></body></html>
<?php exit; endif;

if (!$security->isAuthenticated()) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && hof_post('action') === 'login') {
        try {
            if ($security->attemptLogin(hof_post('username'), hof_post('password'))) {
                hof_redirect('index.php');
            }
            $errors[] = 'Anmeldung nicht möglich. Zugangsdaten prüfen und gegebenenfalls später erneut versuchen.';
        } catch (Throwable) {
            $errors[] = 'Anmeldung vorübergehend nicht möglich. Bitte später erneut versuchen.';
        }
    }
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anmeldung · Redaktionsbereich</title><link rel="stylesheet" href="admin.css"></head>
<body><main class="login-shell"><section class="panel login-panel"><p class="eyebrow">Erlebnishof Auszeit</p><h1>Redaktionsbereich</h1>
<?php if ($errors !== []): ?><div class="error-summary" role="alert"><h2>Anmeldung fehlgeschlagen</h2><p><?= hof_h($errors[0]) ?></p></div><?php endif; ?>
<form method="post" autocomplete="on"><input type="hidden" name="action" value="login"><label>Benutzername<input name="username" autocomplete="username" required maxlength="200"></label><label>Passwort<input type="password" name="password" autocomplete="current-password" required maxlength="4096"></label><button type="submit">Anmelden</button></form>
</section></main></body></html>
<?php exit;
}

$csrf = $security->csrfToken();
if (isset($_SESSION['notice']) && is_string($_SESSION['notice'])) {
    $notice = $_SESSION['notice'];
    unset($_SESSION['notice']);
}

try {
    $document = hof_read_document_or_fail($repository);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        $postLimit = Preflight::iniBytes((string)ini_get('post_max_size'));
        if (is_int($contentLength) && $contentLength > 0 && $contentLength > $postLimit) {
            throw new ValidationException(['Die Anfrage war größer als das serverseitige POST-Limit. Das Bild wurde nicht gespeichert.']);
        }
        $security->assertPostWithCsrf($_POST['csrf'] ?? null);
        $action = hof_post('action');
        if ($action === 'logout') {
            $security->logout();
            hof_redirect('index.php');
        }
        if (!Preflight::allPassed($config)) {
            throw new ValidationException(['Änderungen sind gesperrt, bis alle Server-Prüfungen erfolgreich sind.']);
        }
        $result = null;
        $cleanupWarning = false;
        $redirect = 'index.php';
        if ($action === 'save_entry') {
            $entry = hof_posted_entry($document, $preservedEntry);
            $result = $repository->mutate(hof_expected_revision(), static function (array $candidate) use ($entry): array {
                $found = false;
                foreach ($candidate['entries'] as $position => $existing) {
                    if (($existing['id'] ?? null) === $entry['id']) {
                        $candidate['entries'][$position] = $entry;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $candidate['entries'][] = $entry;
                }
                return $candidate;
            });
            $redirect = 'index.php?edit_entry=' . rawurlencode((string)$entry['id']) . '#eintrag-editor';
        } elseif ($action === 'entry_intent') {
            $id = hof_post('id');
            $intent = hof_post('intent');
            if (!preg_match('/\A[a-f0-9]{32}\z/', $id) || !in_array($intent, ['draft', 'approved', 'archived', 'trashed'], true)) {
                throw new ValidationException(['Ungültige Eintragsaktion.']);
            }
            $result = $repository->mutate(hof_expected_revision(), static function (array $candidate) use ($id, $intent): array {
                foreach ($candidate['entries'] as $position => $entry) {
                    if (($entry['id'] ?? null) !== $id) {
                        continue;
                    }
                    if ($intent === 'approved' && ($entry['intent'] ?? null) !== 'approved') {
                        $candidate['entries'][$position]['approvedAt'] = Support::now()->format(DateTimeInterface::RFC3339);
                    }
                    $candidate['entries'][$position]['intent'] = $intent;
                    $candidate['entries'][$position]['updatedAt'] = Support::now()->format(DateTimeInterface::RFC3339);
                    return $candidate;
                }
                throw new ValidationException(['Der Eintrag wurde nicht gefunden.']);
            });
            $redirect = 'index.php#eintraege';
        } elseif ($action === 'save_exception') {
            $preservedException = hof_posted_exception($document);
            $exception = $preservedException;
            $result = $repository->mutate(hof_expected_revision(), static function (array $candidate) use ($exception): array {
                $found = false;
                foreach ($candidate['exceptions'] as $position => $existing) {
                    if (($existing['id'] ?? null) === $exception['id']) {
                        $candidate['exceptions'][$position] = $exception;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $candidate['exceptions'][] = $exception;
                }
                return $candidate;
            });
            $redirect = 'index.php?edit_exception=' . rawurlencode((string)$exception['id']) . '#ausnahme-editor';
        } elseif ($action === 'exception_intent') {
            $id = hof_post('id');
            $intent = hof_post('intent');
            if (!preg_match('/\A[a-f0-9]{32}\z/', $id) || !in_array($intent, ['draft', 'approved', 'archived', 'trashed'], true)) {
                throw new ValidationException(['Ungültige Ausnahmeaktion.']);
            }
            $result = $repository->mutate(hof_expected_revision(), static function (array $candidate) use ($id, $intent): array {
                foreach ($candidate['exceptions'] as $position => $exception) {
                    if (($exception['id'] ?? null) === $id) {
                        $candidate['exceptions'][$position]['intent'] = $intent;
                        $candidate['exceptions'][$position]['updatedAt'] = Support::now()->format(DateTimeInterface::RFC3339);
                        return $candidate;
                    }
                }
                throw new ValidationException(['Die Ausnahme wurde nicht gefunden.']);
            });
            $redirect = 'index.php#ausnahmen';
        } elseif ($action === 'save_theme') {
            $mode = hof_post('themeMode');
            if (!in_array($mode, ['automatic', 'off', 'spring', 'summer', 'autumn', 'christmas', 'winter'], true)) {
                throw new ValidationException(['Ungültiger Themenmodus.']);
            }
            $themes = ['mode' => $mode, 'windows' => [
                'spring' => hof_post('spring') === '' ? null : hof_post('spring'),
                'summer' => hof_post('summer') === '' ? null : hof_post('summer'),
                'autumn' => hof_post('autumn') === '' ? null : hof_post('autumn'),
            ]];
            $result = $repository->mutate(hof_expected_revision(), static function (array $candidate) use ($themes): array {
                $candidate['themes'] = $themes;
                return $candidate;
            });
            $redirect = 'index.php#themen';
        } elseif ($action === 'upload_asset') {
            $asset = $images->createFromUpload(is_array($_FILES['image'] ?? null) ? $_FILES['image'] : []);
            try {
                $result = $repository->mutate(hof_expected_revision(), static function (array $candidate) use ($asset): array {
                    $candidate['assets'][] = $asset;
                    return $candidate;
                });
            } catch (Throwable $error) {
                $images->discardCreated($asset);
                throw $error;
            }
            $redirect = 'index.php#bilder';
        } elseif ($action === 'asset_intent') {
            $id = hof_post('id');
            $targetStatus = hof_post('status');
            $asset = hof_find($document['assets'], $id);
            if ($asset === null || !in_array($targetStatus, ['active', 'trashed'], true) || $targetStatus === ($asset['status'] ?? null)) {
                throw new ValidationException(['Ungültige Bildaktion.']);
            }
            if ($targetStatus === 'trashed') {
                foreach ($document['entries'] as $entry) {
                    if (($entry['imageId'] ?? null) === $id) {
                        throw new ValidationException(['Das Bild wird noch von einem Eintrag verwendet und kann nicht gelöscht werden.']);
                    }
                }
            }
            $expectedRevision = hof_expected_revision();
            [$result, $cleanupWarning] = $images->withStatusLock(static function () use ($images, $repository, $asset, $targetStatus, $expectedRevision, $id): array {
                $fileTransaction = $images->stageStatusChange($asset, $targetStatus === 'trashed');
                try {
                    $result = $repository->mutate($expectedRevision, static function (array $candidate) use ($id, $targetStatus): array {
                        foreach ($candidate['assets'] as $position => $existing) {
                            if (($existing['id'] ?? null) === $id) {
                                $candidate['assets'][$position]['status'] = $targetStatus;
                                $candidate['assets'][$position]['trashedAt'] = $targetStatus === 'trashed' ? Support::now()->format(DateTimeInterface::RFC3339) : null;
                                return $candidate;
                            }
                        }
                        throw new ValidationException(['Das Bild wurde nicht gefunden.']);
                    });
                } catch (Throwable $error) {
                    $images->rollbackStatusChange($fileTransaction);
                    throw $error;
                }
                return [$result, !$images->commitStatusChange($fileTransaction)];
            });
            $redirect = 'index.php#bilder';
        } elseif ($action === 'restore_backup') {
            $result = $repository->restoreBackup(hof_post('backup'), hof_expected_revision());
            $redirect = 'index.php#wiederherstellung';
        } else {
            throw new ValidationException(['Unbekannte Aktion.']);
        }
        $_SESSION['notice'] = is_array($result) && ($result['publicationComplete'] ?? false)
            ? 'Änderung gespeichert und veröffentlicht.'
            : 'Änderung gespeichert. Die Veröffentlichung ist unvollständig und wird automatisch erneut versucht.';
        if ($cleanupWarning) {
            $_SESSION['notice'] .= ' Eine alte Bildkopie konnte nicht entfernt werden; der aktive Stand bleibt dennoch vollständig.';
        }
        hof_redirect($redirect);
    }
} catch (ValidationException $error) {
    $errors = $error->errors;
    $document = hof_read_document_or_fail($repository);
} catch (ConflictException $error) {
    $errors = [$error->getMessage()];
    $document = hof_read_document_or_fail($repository);
} catch (AuthenticationException) {
    $errors = ['Die Sicherheitsprüfung ist abgelaufen. Es wurde nichts geändert; bitte die Seite neu laden.'];
    $document = hof_read_document_or_fail($repository);
} catch (Throwable) {
    $errors = ['Die Aktion ist sicher fehlgeschlagen. Der vorherige gültige Stand bleibt erhalten.'];
    $document = hof_read_document_or_fail($repository);
}

$revision = (int)$document['writeRevision'];
$now = Support::now();
$entryId = is_string($_GET['edit_entry'] ?? null) ? $_GET['edit_entry'] : '';
$entry = $preservedEntry ?? hof_find($document['entries'], $entryId) ?? [
    'id' => '', 'type' => 'news', 'intent' => 'draft', 'title' => '', 'body' => '', 'imageId' => null,
    'imageAlt' => '', 'displayStart' => null, 'expiry' => null, 'eventStart' => null, 'eventEnd' => null,
];
$exceptionId = is_string($_GET['edit_exception'] ?? null) ? $_GET['edit_exception'] : '';
$exception = $preservedException ?? hof_find($document['exceptions'], $exceptionId) ?? [
    'id' => '', 'intent' => 'draft', 'target' => 'both', 'startDate' => '', 'endDate' => '', 'closed' => true,
    'opens' => '09:00', 'closes' => '18:00', 'note' => '',
];
$checks = Preflight::checks($config);
$backups = hof_list_backups_or_fail($repository);
$activeAssets = array_values(array_filter($document['assets'], static fn(mixed $asset): bool => is_array($asset) && ($asset['status'] ?? null) === 'active'));
$allAssets = $document['assets'];
usort($allAssets, static fn(array $a, array $b): int => (string)$b['createdAt'] <=> (string)$a['createdAt']);
$assetPageSize = 20;
$assetPages = max(1, (int)ceil(count($allAssets) / $assetPageSize));
$assetPage = max(1, min($assetPages, filter_input(INPUT_GET, 'asset_page', FILTER_VALIDATE_INT) ?: 1));
$visibleAssets = array_slice($allAssets, ($assetPage - 1) * $assetPageSize, $assetPageSize);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Redaktionsbereich · Erlebnishof Auszeit</title>
  <link rel="stylesheet" href="admin.css">
  <script src="admin.js" defer></script>
</head>
<body>
<header class="topbar"><div><p class="eyebrow">Erlebnishof Auszeit</p><h1>Redaktionsbereich</h1><p>Bearbeitungsstand <?= $revision ?></p></div>
<form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><button class="secondary" type="submit">Abmelden</button></form></header>
<nav class="section-nav" aria-label="Bereiche"><a href="#eintraege">Aktuelles</a><a href="#ausnahmen">Öffnungszeiten</a><a href="#themen">Themen</a><a href="#bilder">Bilder</a><a href="#system">System</a></nav>
<main class="admin-shell">
<?php if ($notice !== null): ?><div class="notice" role="status"><?= hof_h($notice) ?></div><?php endif; ?>
<?php if ($errors !== []): ?><section id="error-summary" class="error-summary" role="alert" tabindex="-1"><h2>Bitte prüfen</h2><ul><?php foreach ($errors as $error): ?><li><?= hof_h($error) ?></li><?php endforeach; ?></ul><p>Bei einem veralteten Stand: Text kopieren, Seite neu laden und Änderungen abgleichen.</p></section><?php endif; ?>

<section id="eintraege" class="panel"><div class="section-heading"><div><p class="eyebrow">Neuigkeiten und Termine</p><h2>Aktuelles</h2></div><a class="button secondary" href="index.php#eintrag-editor">Neuer Eintrag</a></div>
<?php if ($document['entries'] === []): ?><p>Noch keine Einträge.</p><?php else: ?><div class="item-list">
<?php foreach ($document['entries'] as $item): ?><article class="item-row"><div><strong><?= hof_h($item['title'] !== '' ? $item['title'] : 'Ohne Titel') ?></strong><span><?= hof_h($item['type'] === 'event' ? 'Termin' : 'Neuigkeit') ?> · <?= hof_h(Domain::derivedEntryState($item, $now)) ?></span></div><div class="row-actions"><a class="button secondary" href="?edit_entry=<?= hof_h($item['id']) ?>#eintrag-editor">Bearbeiten</a>
<form method="post"><input type="hidden" name="action" value="entry_intent"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="id" value="<?= hof_h($item['id']) ?>">
<?php if ($item['intent'] === 'trashed'): ?><button name="intent" value="draft" class="secondary">Wiederherstellen</button><?php elseif ($item['intent'] === 'archived'): ?><button name="intent" value="approved" class="secondary">Erneut freigeben</button><button name="intent" value="trashed" class="danger" data-confirm="In den Papierkorb verschieben?">Papierkorb</button><?php else: ?><button name="intent" value="archived" class="secondary">Archivieren</button><button name="intent" value="trashed" class="danger" data-confirm="In den Papierkorb verschieben?">Papierkorb</button><?php endif; ?>
</form></div></article><?php endforeach; ?></div><?php endif; ?>
</section>

<section id="eintrag-editor" class="panel"><p class="eyebrow">Bearbeiten</p><h2><?= $entry['id'] === '' ? 'Neuer Eintrag' : 'Eintrag bearbeiten' ?></h2>
<form method="post" class="editor-form" data-dirty-form><input type="hidden" name="action" value="save_entry"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="id" value="<?= hof_h($entry['id']) ?>">
<div class="form-grid"><label>Art<select name="type" data-entry-type><option value="news"<?= $entry['type'] === 'news' ? ' selected' : '' ?>>Neuigkeit</option><option value="event"<?= $entry['type'] === 'event' ? ' selected' : '' ?>>Öffentlicher Termin</option></select></label><label>Redaktionelle Absicht<select name="intent" data-entry-intent><option value="draft"<?= $entry['intent'] === 'draft' ? ' selected' : '' ?>>Entwurf</option><option value="approved"<?= $entry['intent'] === 'approved' ? ' selected' : '' ?>>Freigegeben</option><option value="archived"<?= $entry['intent'] === 'archived' ? ' selected' : '' ?>>Archiviert</option><option value="trashed"<?= $entry['intent'] === 'trashed' ? ' selected' : '' ?>>Papierkorb</option></select></label></div>
<label>Titel <span class="hint">Für Entwürfe optional; vor Freigabe erforderlich. Maximal 120 Zeichen.</span><input name="title" maxlength="120" value="<?= hof_h($entry['title']) ?>" data-preview-title-source></label>
<label>Text <span class="hint">Für Entwürfe optional; vor Freigabe erforderlich. Nur Text, maximal 3.000 Zeichen.</span><textarea name="body" maxlength="3000" rows="8" data-preview-body-source><?= hof_h($entry['body']) ?></textarea></label>
<div class="form-grid"><label>Sichtbar ab <span class="hint">optional</span><input type="datetime-local" name="displayStart" value="<?= hof_h(hof_datetime_local($entry['displayStart'])) ?>"></label><label data-news-field>Ablauf <span class="hint">optional, exklusiv</span><input type="datetime-local" name="expiry" value="<?= hof_h(hof_datetime_local($entry['expiry'] ?? null)) ?>"></label><label data-event-field>Terminbeginn<input type="datetime-local" name="eventStart" value="<?= hof_h(hof_datetime_local($entry['eventStart'] ?? null)) ?>"></label><label data-event-field>Terminende <span class="hint">optional; sonst nächste lokale Mitternacht</span><input type="datetime-local" name="eventEnd" value="<?= hof_h(hof_datetime_local($entry['eventEnd'] ?? null)) ?>"></label></div>
<div class="form-grid"><label>Beitragsbild<select name="imageId" data-entry-image><option value="">Kein Bild</option><?php foreach ($activeAssets as $asset): ?><option value="<?= hof_h($asset['id']) ?>"<?= $entry['imageId'] === $asset['id'] ? ' selected' : '' ?>>Bild <?= hof_h(substr($asset['id'], 0, 8)) ?> · <?= (int)$asset['width'] ?>×<?= (int)$asset['height'] ?></option><?php endforeach; ?></select></label><label>Bildbeschreibung <span class="hint">für Gäste mit Screenreader</span><input name="imageAlt" maxlength="300" value="<?= hof_h($entry['imageAlt']) ?>" data-entry-image-alt></label></div>
<button type="submit">Eintrag speichern</button></form>
<aside class="preview" aria-label="Sichere Textvorschau"><p class="eyebrow">Mobile Karten-Vorschau</p><h3 data-preview-title><?= hof_h($entry['title'] !== '' ? $entry['title'] : 'Titel') ?></h3><p data-preview-body><?= hof_h($entry['body'] !== '' ? $entry['body'] : 'Der Text erscheint hier als reiner Text.') ?></p></aside>
</section>

<section id="ausnahmen" class="panel"><div class="section-heading"><div><p class="eyebrow">Abweichende Zeiten</p><h2>Öffnungs-Ausnahmen</h2></div><a class="button secondary" href="index.php#ausnahme-editor">Neue Ausnahme</a></div>
<?php if ($document['exceptions'] === []): ?><p>Noch keine Ausnahmen.</p><?php else: ?><div class="item-list"><?php foreach ($document['exceptions'] as $item): ?><article class="item-row"><div><strong><?= hof_h($item['startDate']) ?> bis <?= hof_h($item['endDate']) ?></strong><span><?= hof_h(['cafe' => 'Hofcafé', 'shop' => 'Hofladen', 'both' => 'Beide'][$item['target']] ?? '') ?> · <?= hof_h($item['intent']) ?> · <?= $item['closed'] ? 'geschlossen' : hof_h($item['opens'] . '–' . $item['closes']) ?></span></div><div class="row-actions"><a class="button secondary" href="?edit_exception=<?= hof_h($item['id']) ?>#ausnahme-editor">Bearbeiten</a><form method="post"><input type="hidden" name="action" value="exception_intent"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="id" value="<?= hof_h($item['id']) ?>"><?php if ($item['intent'] === 'trashed'): ?><button name="intent" value="draft" class="secondary">Wiederherstellen</button><?php else: ?><button name="intent" value="archived" class="secondary">Archivieren</button><button name="intent" value="trashed" class="danger" data-confirm="In den Papierkorb verschieben?">Papierkorb</button><?php endif; ?></form></div></article><?php endforeach; ?></div><?php endif; ?>
</section>

<section id="ausnahme-editor" class="panel"><p class="eyebrow">Bearbeiten</p><h2><?= $exception['id'] === '' ? 'Neue Ausnahme' : 'Ausnahme bearbeiten' ?></h2><form method="post" class="editor-form" data-dirty-form><input type="hidden" name="action" value="save_exception"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="id" value="<?= hof_h($exception['id']) ?>">
<div class="form-grid"><label>Ziel<select name="target"><option value="cafe"<?= $exception['target'] === 'cafe' ? ' selected' : '' ?>>Hofcafé</option><option value="shop"<?= $exception['target'] === 'shop' ? ' selected' : '' ?>>Hofladen</option><option value="both"<?= $exception['target'] === 'both' ? ' selected' : '' ?>>Beide</option></select></label><label>Absicht<select name="intent"><option value="draft"<?= $exception['intent'] === 'draft' ? ' selected' : '' ?>>Entwurf</option><option value="approved"<?= $exception['intent'] === 'approved' ? ' selected' : '' ?>>Freigegeben</option><option value="archived"<?= $exception['intent'] === 'archived' ? ' selected' : '' ?>>Archiviert</option><option value="trashed"<?= $exception['intent'] === 'trashed' ? ' selected' : '' ?>>Papierkorb</option></select></label><label>Erster Tag<input type="date" name="startDate" value="<?= hof_h($exception['startDate']) ?>" required></label><label>Letzter Tag <span class="hint">einschließlich</span><input type="date" name="endDate" value="<?= hof_h($exception['endDate']) ?>" required></label></div>
<fieldset><legend>Regel</legend><label class="inline-choice"><input type="radio" name="closed" value="1"<?= $exception['closed'] ? ' checked' : '' ?> data-closed-choice> Ganztägig geschlossen</label><label class="inline-choice"><input type="radio" name="closed" value="0"<?= !$exception['closed'] ? ' checked' : '' ?> data-closed-choice> Ersatzöffnungszeit</label><div class="form-grid" data-replacement-hours><label>Öffnet<input type="time" name="opens" value="<?= hof_h($exception['opens'] ?? '09:00') ?>"></label><label>Schließt<input type="time" name="closes" value="<?= hof_h($exception['closes'] ?? '18:00') ?>"></label></div></fieldset>
<label>Hinweis <span class="hint">optional, maximal 120 Zeichen</span><input name="note" maxlength="120" value="<?= hof_h($exception['note']) ?>"></label><button type="submit">Ausnahme speichern</button></form></section>

<section id="themen" class="panel"><p class="eyebrow">Saisonale Gestaltung</p><h2>Themen</h2><?php if ($document['themes']['mode'] !== 'automatic' && $document['themes']['mode'] !== 'off'): ?><div class="warning" role="status">Ein Thema ist derzeit manuell erzwungen.</div><?php endif; ?><form method="post" class="editor-form" data-dirty-form><input type="hidden" name="action" value="save_theme"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><label>Modus<select name="themeMode"><option value="automatic"<?= $document['themes']['mode'] === 'automatic' ? ' selected' : '' ?>>Automatisch</option><option value="off"<?= $document['themes']['mode'] === 'off' ? ' selected' : '' ?>>Aus</option><?php foreach (['spring' => 'Frühling', 'summer' => 'Sommer', 'autumn' => 'Herbst', 'christmas' => 'Weihnachten', 'winter' => 'Winter'] as $value => $label): ?><option value="<?= $value ?>"<?= $document['themes']['mode'] === $value ? ' selected' : '' ?>>Manuell: <?= $label ?></option><?php endforeach; ?></select></label><p class="hint">Weihnachten (1.12.–6.1.) und Winter (7.1.–Ende Februar) sind fest. Jedes bearbeitbare Fenster dauert 14 Tage.</p><div class="form-grid"><?php foreach (['spring' => 'Frühling ab', 'summer' => 'Sommer ab', 'autumn' => 'Herbst ab'] as $name => $label): ?><label><?= $label ?><input type="date" name="<?= $name ?>" value="<?= hof_h($document['themes']['windows'][$name] ?? '') ?>"></label><?php endforeach; ?></div><button type="submit">Themen speichern</button></form></section>

<section id="bilder" class="panel"><p class="eyebrow">Ein Beitragsbild pro Eintrag</p><h2>Bilder</h2><form method="post" enctype="multipart/form-data" class="editor-form"><input type="hidden" name="action" value="upload_asset"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$config['max_upload_bytes'] ?>"><label>JPEG, PNG oder WebP <span class="hint">maximal <?= hof_h(rtrim(rtrim(number_format((int)$config['max_upload_bytes'] / 1048576, 2, ',', ''), '0'), ',')) ?> MiB, <?= hof_h(number_format((int)$config['max_image_dimension'], 0, ',', '.')) ?> px je Seite und <?= hof_h(number_format((int)$config['max_image_pixels'] / 1000000, 1, ',', '.')) ?> Megapixel</span><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><button type="submit">Bild sicher verarbeiten</button></form>
<div class="asset-grid"><?php foreach ($visibleAssets as $asset): ?><article class="asset"><img src="img.php?id=<?= hof_h($asset['id']) ?>" alt="Privates Vorschaubild" loading="lazy" width="320" height="240"><p><strong>Bild <?= hof_h(substr($asset['id'], 0, 8)) ?></strong><br><?= (int)$asset['width'] ?>×<?= (int)$asset['height'] ?> · <?= hof_h($asset['status'] === 'active' ? 'Aktiv' : 'Papierkorb') ?></p><form method="post"><input type="hidden" name="action" value="asset_intent"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="id" value="<?= hof_h($asset['id']) ?>"><?php if ($asset['status'] === 'active'): ?><button name="status" value="trashed" class="danger" data-confirm="Unbenutztes Bild in den Papierkorb verschieben?">Papierkorb</button><?php else: ?><button name="status" value="active" class="secondary">Wiederherstellen</button><?php endif; ?></form></article><?php endforeach; ?></div><?php if ($assetPages > 1): ?><nav class="pagination" aria-label="Bilderseiten"><?php if ($assetPage > 1): ?><a class="button secondary" href="?asset_page=<?= $assetPage - 1 ?>#bilder">Zurück</a><?php endif; ?><span>Seite <?= $assetPage ?> von <?= $assetPages ?></span><?php if ($assetPage < $assetPages): ?><a class="button secondary" href="?asset_page=<?= $assetPage + 1 ?>#bilder">Weiter</a><?php endif; ?></nav><?php endif; ?></section>

<section id="system" class="panel"><p class="eyebrow">Nur für Angemeldete</p><h2>Server-Prüfung</h2><ul class="check-list"><?php foreach ($checks as $check): ?><li class="<?= $check['status'] === 'ok' ? 'check-ok' : 'check-error' ?>"><strong><?= $check['status'] === 'ok' ? 'OK' : 'Fehlt' ?>: <?= hof_h($check['label']) ?></strong><span><?= hof_h($check['message']) ?></span></li><?php endforeach; ?></ul></section>

<section id="wiederherstellung" class="panel"><p class="eyebrow">Gemeinsames Betreiberkonto, kein Personen-Audit</p><h2>Wiederherstellung</h2><p>Eine Sicherung ersetzt strukturierte Inhalte, niemals den unabhängigen Neuigkeiten-Revisionszähler. Vorher aktuelle Texte extern sichern.</p><?php if ($backups === []): ?><p>Noch keine Sicherungen.</p><?php else: ?><div class="item-list"><?php foreach (array_slice($backups, 0, 30) as $backup): ?><article class="item-row"><div><strong><?= hof_h($backup['modifiedAt']) ?></strong><span><?= (int)$backup['bytes'] ?> Byte</span></div><form method="post"><input type="hidden" name="action" value="restore_backup"><input type="hidden" name="csrf" value="<?= hof_h($csrf) ?>"><input type="hidden" name="expectedRevision" value="<?= $revision ?>"><input type="hidden" name="backup" value="<?= hof_h($backup['name']) ?>"><button class="danger" data-confirm="Diese Sicherung wirklich als neuen Stand wiederherstellen?">Wiederherstellen</button></form></article><?php endforeach; ?></div><?php endif; ?></section>
</main>
<footer><p>Zeiten werden in Europe/Berlin interpretiert. Abgeleitete Zustände werden nicht gespeichert.</p></footer>
</body></html>
