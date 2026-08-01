<?php
declare(strict_types=1);

use Hofladen\Editorial\Config;
use Hofladen\Editorial\Repository;
require_once __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $config = Config::load(__DIR__);
    Config::prepareStorage($config);
    $repository = new Repository($config);
    $command = $argv[1] ?? 'status';
    if ($command === 'status') {
        $document = $repository->readDocument();
        $snapshot = $repository->currentSnapshot();
        fwrite(STDOUT, sprintf(
            "Schema: %d\nBearbeitungsstand: %d\nSnapshot: %s\nAktuelles-Sequenz: %d\n",
            $document['schemaVersion'],
            $document['writeRevision'],
            $snapshot['snapshotRevision'],
            $snapshot['newsVersion']['sequence']
        ));
        exit(0);
    }
    if ($command === 'rotate-ledger' && ($argv[2] ?? '') === 'I_UNDERSTAND_NEW_GENERATION') {
        $repository->rotateLedgerGeneration();
        fwrite(STDOUT, "Neue Aktuelles-Generation wurde angelegt. Aktuelle Einträge gelten auf allen Geräten als ungelesen.\n");
        exit(0);
    }
    fwrite(STDERR, "Verwendung: php maintenance.php status\n");
    fwrite(STDERR, "Disaster-only: php maintenance.php rotate-ledger I_UNDERSTAND_NEW_GENERATION\n");
    exit(2);
} catch (Throwable) {
    fwrite(STDERR, "Wartung fehlgeschlagen. Keine Pfade oder Geheimnisse wurden ausgegeben.\n");
    exit(1);
}
