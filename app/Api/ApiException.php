<?php

declare(strict_types=1);

namespace App\Api;

use RuntimeException;

/**
 * An error that is safe to show to the user. `fields` maps input names to
 * validation messages so the UI can highlight them.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $fields = [],
        public readonly string $errorCode = 'error',
    ) {
        parent::__construct($message);
    }

    public static function validation(array $fields, string $message = 'Please fix the highlighted fields.'): self
    {
        return new self($message, 422, $fields, 'validation');
    }

    public static function notFound(string $what = 'Item'): self
    {
        return new self($what . ' not found.', 404, [], 'not_found');
    }
}
