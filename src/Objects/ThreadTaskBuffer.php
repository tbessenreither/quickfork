<?php declare(strict_types=1);

namespace Tbessenreither\QuickFork\Objects;

use Tbessenreither\QuickFork\Objects\Task;
use RuntimeException;


class ThreadTaskBuffer
{
    private bool $isLocked = false;
    /**
     * @var array<string, Task>
     */
    private array $tasks = [];

    public function addTask(Task $task): void
    {
        if ($this->isLocked) {
            throw new RuntimeException('Cannot add task to locked buffer.');
        }
        $this->tasks[$task->getId()] = $task;
    }

    /**
     * @param Task[] $tasks
     */
    public function addTasks(array $tasks): void
    {
        if ($this->isLocked) {
            throw new RuntimeException('Cannot add tasks to locked buffer.');
        }

        foreach ($tasks as $task) {
            $this->addTask($task);
        }
    }

    public function getTask(string $id): ?Task
    {
        return $this->tasks[$id] ?? null;
    }

    public function reset(): void
    {
        if ($this->isLocked) {
            throw new RuntimeException('Cannot reset a locked buffer.');
        }
        $this->tasks = [];
    }

    public function lock(): void
    {
        $this->isLocked = true;
    }

}
