<?php
require __DIR__ . '/config.php';

// Ensure config variables exist (map constants to variables if config defines constants)
if (!isset($NEWS_DIR) && defined('NEWS_DIR')) {
    $NEWS_DIR = NEWS_DIR;
}
if (!isset($ALLOWED_EXTENSIONS) && defined('ALLOWED_EXTENSIONS')) {
    $ALLOWED_EXTENSIONS = ALLOWED_EXTENSIONS;
}
$ALLOWED_EXTENSIONS = $ALLOWED_EXTENSIONS ?? ['jpg','jpeg','png','gif','webp'];

// Basic validation
$name = $_GET['file'] ?? '';
if ($name === '') {
    http_response_code(400);
    echo 'Ungültige Anfrage';
    exit;
}

// Disallow directory traversal
$baseName = basename($name);
if ($baseName !== $name) {
    http_response_code(400);
    echo 'Ungültiger Dateiname';
    exit;
}

// Resolve real path and ensure it's inside NEWS_DIR
$newsDirReal = realpath($NEWS_DIR);
if ($newsDirReal === false) {
    http_response_code(500);
    echo 'Serverkonfiguration fehlerhaft';
    exit;
}

$fullPath = realpath($newsDirReal . DIRECTORY_SEPARATOR . $baseName);
if ($fullPath === false || strpos($fullPath, $newsDirReal) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    echo 'Nicht gefunden';
    exit;
}

// Check allowed extension
$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
if (!in_array($ext, $ALLOWED_EXTENSIONS, true)) {
    http_response_code(403);
    echo 'Verboten';
    exit;
}

// Determine mime type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($fullPath) ?: 'application/octet-stream';

// Send headers and file
header_remove(); // remove any previously set headers
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');

// Stream the file
$fp = fopen($fullPath, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'Datei konnte nicht gelesen werden';
    exit;
}
while (!feof($fp)) {
    echo fread($fp, 8192);
    // flush to client
    @ob_flush();
    @flush();
}
fclose($fp);
exit;

