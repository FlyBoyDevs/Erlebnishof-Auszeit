<?php
declare(strict_types=1);

/**
 * Copy this file OUTSIDE the public document root. Point HOFLADEN_CONFIG at
 * that copy or put its one-line absolute path in admin/.hofladen-config-path.
 * Never commit either environment-specific file or its values.
 */
return [
    'environment' => 'staging', // staging|production|test; written into storage markers
    'document_root' => '/absolute/path/to/public-document-root',

    'admin_username' => 'admin',
    // Generate outside the repository: php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => '$2y$12$replace.with.a.real.password.hash......................',
    // Generate at least 32 random bytes and encode as hex/base64.
    'throttle_secret' => 'replace-with-a-long-random-secret',

    // Every private path must be outside document_root (or protected by a verified server deny rule).
    'private_data_dir' => '/absolute/private/hofladen/data',
    'private_upload_dir' => '/absolute/private/hofladen/uploads',
    'ledger_dir' => '/absolute/private/hofladen/ledger',
    'backup_dir' => '/absolute/private/hofladen/backups',
    'trash_dir' => '/absolute/private/hofladen/trash',
    'throttle_dir' => '/absolute/private/hofladen/throttle',
    'cache_dir' => '/absolute/private/hofladen/cache',

    // Public editorial variants are content-hashed and may live below the document root.
    'public_media_dir' => '/absolute/path/to/public-document-root/content/media',
    'public_media_url' => '/content/media',

    'release_version' => 'redesign-v1',
    'available_effects' => ['winter'],
    'trusted_proxies' => [], // exact IP addresses only
    'require_https' => true,
    // Set true only after /content/current.json, headers, private-path denial,
    // upload limits and permissions have been verified on this environment.
    'public_route_verified' => false,

    'session_idle_seconds' => 1800,
    'session_absolute_seconds' => 28800,
    'max_upload_bytes' => 12 * 1024 * 1024,
    'max_image_dimension' => 8000,
    'max_image_pixels' => 40000000,
    'backup_limit' => 30,
    'trash_retention_days' => 30,
    // Pruning remains disabled until retention/off-host recovery is approved.
    'pruning_enabled' => false,
];
