<?php
declare(strict_types=1);

namespace amblydia\databaseapi\exception;

use Exception;
use PDOException;

class DatabaseException extends Exception {
    private array $errorInfo;
    private ?string $query;

    public function __construct(
        string $message,
               $code,
               $errorInfo = null,
               $query = null,
               $previous = null
    ) {
        parent::__construct($message, (int) $code, $previous);
        $this->errorInfo = $errorInfo ?? [];
        $this->query = $query;
    }

    public function getErrorInfo(): array {
        return $this->errorInfo;
    }

    public function getQuery(): ?string {
        return $this->query;
    }

    public static function create(PDOException $exception, ?string $query = null): self {
        return new self(
            $exception->getMessage(),
            $exception->getCode(),
            $exception->errorInfo ?? null,
            $query,
            $exception
        );
    }
}
