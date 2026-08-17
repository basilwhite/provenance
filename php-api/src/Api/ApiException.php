<?php

declare(strict_types=1);

namespace Provenance\Api;

final class ApiException extends \Exception
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
