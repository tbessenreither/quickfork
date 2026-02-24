<?php declare(strict_types=1);

namespace Tbessenreither\Quickfork\Service;

use RuntimeException;
use SebastianBergmann\Environment\Runtime;


class ExponentialBackoff
{
    private ?int $lastAttemptTimeMs = null;
    private int $attempts = 0;

    public function __construct(
        private float $factor = 1.05,
        private int $minSleepTimeMs = 10,
        private int $maxSleepTimeMs = 5000,
        private ?int $maxAttempts = null,
    ) {
    }

    public function reset(): void
    {
        $this->attempts = 0;
        $this->lastAttemptTimeMs = $this->getTimeMs();
    }

    public function sleep(): void
    {
        $this->attempts++;
        if (
            $this->maxAttempts !== null
            && $this->attempts > $this->maxAttempts
        ) {
            throw new RuntimeException('Maximum number of attempts reached');
        }

        $sleepTimeClampedMs = $this->getSleepTimeMs();
        usleep((int) $sleepTimeClampedMs * 1000);

        $this->lastAttemptTimeMs = $this->getTimeMs();
    }

    public function getAttempt(): int
    {
        return $this->attempts;
    }

    public function getSleepTimeMs(): int
    {
        $sleepTimeMs = $this->minSleepTimeMs + pow($this->factor, $this->attempts);

        $sleepTimeDeltaMs = floor($sleepTimeMs - $this->getMsSinceLastAttemp());

        return (int) max($this->minSleepTimeMs, min($sleepTimeDeltaMs, $this->maxSleepTimeMs));
    }

    private function getTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function getLastAtemptTimeMs(): int
    {
        if ($this->lastAttemptTimeMs === null) {
            $this->lastAttemptTimeMs = $this->getTimeMs();
        }

        return $this->lastAttemptTimeMs;
    }

    private function getMsSinceLastAttemp(): int
    {
        return $this->getTimeMs() - $this->getLastAtemptTimeMs();
    }

}
