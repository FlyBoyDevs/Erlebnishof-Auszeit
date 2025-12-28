<?php
// --- BASIC CONFIG ---

// Login credentials
// Username is stored in plain text; the password must be stored as a secure hash (bcrypt/Argon2)
// To generate a hash, you can use the helper at /admin/hash.php (temporarily) or any PHP one-liner:
// php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
const ADMIN_USERNAME = 'admin';
// IMPORTANT: Replace the placeholder below with a real hash generated for your chosen password
// Example format (bcrypt starts with $2y$...):
// const ADMIN_PASSWORD_HASH = '$2y$10$exampleexampleexampleexampleexampleexampleexampleexampl';
const ADMIN_PASSWORD_HASH = '$2y$10$exampleexampleexampleexampleexampleexampleexampleexampl';

// Path to the news folder (where images + manifest.json live)
$NEWS_DIR = realpath(__DIR__ . '/../img/news');

// Manifest file inside news folder
$MANIFEST_FILE = $NEWS_DIR . DIRECTORY_SEPARATOR . 'manifest.json';

// Allowed image extensions
$ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

// --- SESSION ---
session_start();

// Simple login check
function require_login(): void {
    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: ?login');
        exit;
    }
}

// Load manifest as array of filenames (relative, e.g. "image.png")
function load_manifest(string $manifestFile): array {
    if (!file_exists($manifestFile)) {
        return [];
    }
    $json = file_get_contents($manifestFile);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

// Save manifest
function save_manifest(string $manifestFile, array $images): bool {
    return (bool)file_put_contents(
        $manifestFile,
        json_encode(
            array_values(array_unique($images)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )
    );
}