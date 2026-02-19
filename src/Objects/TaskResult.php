<?php declare(strict_types=1);

namespace Tbessenreither\QuickFork\Objects;

use Throwable;


class TaskResult
{

    public function __construct(
        private mixed $result = null,
        private string $output = '',
        private ?Throwable $error = null,
        private bool $criticalError = false,
    ) {
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getError(): ?Throwable
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function isCriticalError(): bool
    {
        return $this->criticalError;
    }

    public function throwIfCritical(): void
    {
        if ($this->hasError() && $this->isCriticalError()) {
            throw $this->error;
        }
    }

}
