<?php
declare(strict_types=1);

namespace Hofladen\Editorial;

use RuntimeException;

final class ConfigurationException extends RuntimeException {}
final class ValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", $errors));
    }
}
final class ConflictException extends RuntimeException {}
final class StorageException extends RuntimeException {}
final class PublicationException extends RuntimeException {}
final class AuthenticationException extends RuntimeException {}
