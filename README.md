# Quickfork

This is a lightweight wrapper for PCNTL that adds Inter Process Communication (IPC) via Unix Sockets and a simple API for running tasks in parallel.
It is designed to be easy to use and to integrate into existing projects.

## Features
- Run tasks in parallel using a fixed pool of worker processes.
- IPC via Unix Sockets for communication between the main process and worker processes.
- Simple API for defining tasks and handling results.
- Error handling and timeouts for worker processes.
- Tasks are queued and executed as soon as a worker process is available, allowing for efficient resource management.

## Installation

You can install Quickfork via Composer.

First you need to add the repository to your composer.json:

```json
{
	"repositories": [
		{
			"type": "vcs",
			"url": "https://github.com/tbessenreither/quickfork"
		}
	]
}
```

Then you can require the package:

```bash
# For the latest stable version
composer require tbessenreither/quickfork
```

```bash
# For the latest version but might be unstable
composer require tbessenreither/quickfork:dev-main
```

## Usage

The main use case of Quickfork is to run Tasks in parallel with a fixed pool of worker processes.

This example command will create 4 tasks that will be run on a maximum of 5 worker threads. Each task will execute the `workerThread` method, which simulates some work and returns a string. One of the tasks will throw an exception to demonstrate error handling.

If there are more threads than tasks only one thread per task will be created, so in this example only 4 threads will be created.
If you have more tasks than threads, the tasks will be queued and executed as soon as a thread is available.

```php

use Tbessenreither\Quickfork\Objects\Task;
use Tbessenreither\Quickfork\Quickfork;

class FastparallelCommand
{

    protected function execute(): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Running QuickFork Command');

        $quickfork = new Quickfork();

        try {
            $tasks = [];
            for ($i = 0; $i < 4; $i++) {
                $task = new Task(
                    callable: $this->workerThread(...),
                    arguments: [
                        'number' => $i + 1,
                        'index' => $i,
                    ],
                );
                $tasks[] = $task;
            }

            $results = $quickfork->runTasksInThreads($tasks, maxConcurrent: 5);

            foreach ($tasks as $task) {
                $taskId = $task->getId();
                $taskResult = $results[$taskId] ?? null;
                echo "Task ID: {$taskId}, Result:\n";
                if ($taskResult->hasError()) {
                    echo "Error: " . $taskResult->getError()->getMessage() . "\n";
                } else {
                    echo "Result: " . $taskResult->getResult() . "\n";
                }
                echo "-----------------------------\n";
            }

            $io->success('QuickFork Command executed successfully.');
        } catch (Throwable $e) {
            $io->error('An error occurred while executing QuickFork Command: ' . $e->getMessage());

            return Command::FAILURE;
        }


        return Command::SUCCESS;
    }

    private function workerThread(Task $task, ?int $index = null, ?int $number = null): string
    {
        //sleep(rand(1, 3));
        if ($number === 3) {
            throw new RuntimeException('Simulated critical error in worker thread.');
        }
        return "Worker thread is executing task {$number}.";
    }

}
```

This will print something like this:

![example output of a command](documentation/images/example_command_output.png)