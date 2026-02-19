<?php declare(strict_types=1);

namespace Tbessenreither\Quickfork\Objects;

use Tbessenreither\Quickfork\Objects\Socket\Message;
use Tbessenreither\Quickfork\Objects\Socket\Socket;
use Tbessenreither\Quickfork\Exceptions\TimeoutException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;


class Fork
{
    private string $id;
    private mixed $socket;
    private bool $isStarted = false;
    private ?bool $isParent = null;
    private ?int $childPid = null;

    public function __construct(
        private readonly mixed $callable,
        private readonly array $arguments,
        private bool $isCritical = false,

    ) {
        $this->id = uniqid('fork_', true);

        if (!is_callable($callable)) {
            throw new InvalidArgumentException('The provided callable is not valid.');
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function execute(): mixed
    {
        $this->getSocket()->send(new Message(topic: 'fork_start', forkId: $this->getId()));
        ob_start();

        try {
            $result = call_user_func_array($this->callable, array_merge([$this], $this->arguments));
        } catch (Throwable $e) {
            $result = null;
            $this->getSocket()->send(new Message(topic: 'fork_error', content: $e, forkId: $this->getId()));
        }
        $output = ob_get_clean();
        $this->getSocket()->send(new Message(topic: 'fork_output', content: $output, forkId: $this->getId()));
        $this->getSocket()->send(new Message(topic: 'fork_result', content: $result, forkId: $this->getId()));
        $this->getSocket()->send(new Message(topic: 'fork_complete', forkId: $this->getId()));

        return $result;
    }

    public function getCallable(): mixed
    {
        return $this->callable;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    public function markAsStarted(): void
    {
        if ($this->isStarted) {
            throw new RuntimeException('Task has already been marked as started.');
        }
        $this->isStarted = true;
    }

    public function isStarted(): bool
    {
        return $this->isStarted;
    }

    public function setSocket(Socket $socket): void
    {
        $this->socket = $socket;
    }

    public function getSocket(): Socket
    {
        return $this->socket;
    }

    public function setParent(bool $isParent): void
    {
        if ($this->isParent !== null) {
            throw new RuntimeException('Parent status has already been set for this fork.');
        }

        $this->isParent = $isParent;
    }

    public function isParent(): bool
    {
        if ($this->isParent === null) {
            throw new RuntimeException('Parent status has not been set for this fork.');
        }

        return $this->isParent;
    }

    public function setChildPid(int $pid): void
    {
        if ($this->childPid !== null) {
            throw new RuntimeException('PID has already been set for this fork.');
        }

        $this->childPid = $pid;
    }

    public function getChildPid(): int
    {
        if ($this->childPid === null) {
            throw new RuntimeException('PID has not been set for this fork.');
        }

        return $this->childPid;
    }

    public function waitToComplete(?int $timeout = 60): void
    {
        if (!$this->isParent()) {
            throw new RuntimeException('Cannot wait for a parent fork to complete.');
        }
        if ($this->childPid === null) {
            throw new RuntimeException('Child PID is not set for this fork.');
        }

        if ($timeout === null) {
            pcntl_waitpid($this->getChildPid(), $status);
            $this->getSocket()->close();
            return;
        }

        $startingTime = time();
        while (true) {
            $res = pcntl_waitpid($this->getChildPid(), $status, WNOHANG, $resourceUsage);
            if ($res == -1 || $res > 0) {
                break;
            }

            if ((time() - $startingTime) > $timeout) {
                $this->kill();
                throw new TimeoutException("Task {$this->getId()} timed out and terminated after {$timeout} seconds.");
            }
            usleep(100000); // 100ms poll interval
        }

        $this->getSocket()->close();
    }

    public function kill(int $graceInMs = 200): void
    {
        if ($graceInMs < 200) {
            $graceInMs = 200;
        }
        @posix_kill($this->getChildPid(), SIGTERM);
        usleep($graceInMs * 1000); // 200ms grace
        if (pcntl_waitpid($this->getChildPid(), $status, WNOHANG, $resourceUsage) == 0) {
            @posix_kill($this->getChildPid(), SIGKILL);
            pcntl_waitpid($this->getChildPid(), $status, 0, $resourceUsage);
        }

        $this->getSocket()->close();
    }

    public function isRunning(): bool
    {
        if (!$this->isParent()) {
            throw new RuntimeException('Cannot check running status of a parent fork.');
        }
        if ($this->childPid === null) {
            throw new RuntimeException('Child PID is not set for this fork.');
        }

        $res = pcntl_waitpid($this->getChildPid(), $status, WNOHANG, $resourceUsage);
        return $res == 0;
    }

}
