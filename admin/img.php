<?php
declare(strict_types=1);

if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
}

use Hofladen\Editorial\Config;
use Hofladen\Editorial\Domain;
use Hofladen\Editorial\Images;
use Hofladen\Editorial\Repository;
use Hofladen\Editorial\Security;
require_once __DIR__ . '/lib/bootstrap.php';

try {
    $config = Config::load(__DIR__);
    Config::prepareStorage($config);
    $security = new Security($config);
    $security->sendAdminHeaders('application/octet-stream');
    $security->startSession();
    $security->requireAuthentication();

    $id = $_GET['id'] ?? null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) {
        throw new RuntimeException('not found');
    }
    $repository = new Repository($config);
    $document = $repository->readDocument();
    Domain::validateDocument($document);
    $asset = null;
    foreach ($document['assets'] as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? null) === $id) {
            $asset = $candidate;
            break;
        }
    }
    if (!is_array($asset)) {
        throw new RuntimeException('not found');
    }
    $images = new Images($config);
    if (($_GET['source'] ?? null) === '1') {
        $path = $images->sourcePath($asset);
        $mime = (string)$asset['sourceMime'];
    } else {
        $preview = $images->thumbnailPath($asset);
        $path = $preview['path'];
        $mime = $preview['mime'];
    }
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('not found');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: inline; filename="preview"');
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('not found');
    }
    fpassthru($handle);
    fclose($handle);
} catch (Throwable) {
    header('Cache-Control: no-store');
    http_response_code(404);
}
