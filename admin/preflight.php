<?php
declare(strict_types=1);

use Hofladen\Editorial\Config;
use Hofladen\Editorial\Preflight;
require_once __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $config = Config::load(__DIR__);
    Config::prepareStorage($config);
    $checks = Preflight::checks($config);
    foreach ($checks as $check) {
        fwrite(STDOUT, sprintf("[%s] %s — %s\n", $check['status'] === 'ok' ? 'OK' : 'FEHLER', $check['label'], $check['message']));
    }
    exit(Preflight::allPassed($config) ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "[FEHLER] Konfiguration oder Speichergrenze ist ungültig. Keine Pfade oder Geheimnisse wurden ausgegeben.\n");
    exit(1);
}
