<?php
declare(strict_types=1);

if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
}

use Hofladen\Editorial\Config;
use Hofladen\Editorial\Preflight;
use Hofladen\Editorial\Repository;
use Hofladen\Editorial\Support;
require_once dirname(__DIR__) . '/admin/lib/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, noarchive', true);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    header('Cache-Control: no-store');
    http_response_code(405);
    if ($method !== 'HEAD') {
        echo "{\"error\":\"method_not_allowed\"}\n";
    }
    exit;
}

try {
    $config = Config::load(dirname(__DIR__) . '/admin');
    Config::prepareStorage($config);
    Preflight::assertPublicRuntime($config);
    $repository = new Repository($config);
    $now = Support::now();
    $snapshot = $repository->currentSnapshot($now);
    $metadata = Repository::responseMetadata($snapshot, $now);
    header('ETag: ' . $metadata['etag']);
    header('Cache-Control: public, max-age=' . $metadata['maxAge'] . ', must-revalidate');
    header('Vary: Accept-Encoding');

    $validators = array_map('trim', explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
    if (in_array($metadata['etag'], $validators, true)) {
        http_response_code(304);
        exit;
    }
    if ($method !== 'HEAD') {
        echo Support::encodeJson($snapshot);
    }
} catch (Throwable $error) {
    if (function_exists('error_log')) {
        @error_log('Hofladen current-content resolver failed (' . get_class($error) . ').');
    }
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    http_response_code(503);
    if ($method !== 'HEAD') {
        echo "{\"error\":\"current_content_unavailable\"}\n";
    }
}
