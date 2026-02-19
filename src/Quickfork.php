<?php declare(strict_types=1);

namespace Tbessenreither\Quickfork;

use Tbessenreither\Quickfork\Objects\Socket\Message;
use Tbessenreither\Quickfork\Objects\Socket\Socket;
use Tbessenreither\Quickfork\Objects\Fork;
use Tbessenreither\Quickfork\Objects\Task;
use Tbessenreither\Quickfork\Objects\TaskResult;
use Tbessenreither\Quickfork\Objects\ThreadTaskBuffer;
use Tbessenreither\Quickfork\Exceptions\ParallelRunException;
use InvalidArgumentException;
use Throwable;


class Quickfork
{
    private const int DEFAULT_IDLE_PAUSE_MICROSECONDS = 100 * 1000;

    private int $socketDomain;

    private ThreadTaskBuffer $threadTaskBuffer;

    public function __construct(
    ) {
        $this->socketDomain = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? STREAM_PF_INET : STREAM_PF_UNIX);
        $this->threadTaskBuffer = new ThreadTaskBuffer();
        $this->threadTaskBuffer->reset();
    }

    public function runTask(Fork $fork): Fork
    {
        if ($fork->isStarted()) {
            throw new ParallelRunException('Fork has already been started.');
        }
        $fork->markAsStarted();

        $socketsPair = stream_socket_pair($this->socketDomain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!$socketsPair) {
            throw new ParallelRunException('Failed to create socket pair: ' . error_get_last()['message']);
        }

        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new ParallelRunException('Failed to fork process: ' . error_get_last()['message']);
        } elseif ($pid) {
            // Parent
            fclose($socketsPair[0]); // Close child's socket
            $fork->setSocket(new Socket($socketsPair[1]));
            $fork->setParent(true);
            $fork->setChildPid($pid);
        } else {
            // Child
            $this->threadTaskBuffer->lock(); // This needs to be locked so that child processes don't accidentally modify or clear the task buffer.
            $this->fixPcntlIssues();

            fclose($socketsPair[1]); // Close parent's socket
            $fork->setSocket(new Socket($socketsPair[0]));
            $fork->setParent(false);

            $fork->execute();

            $fork->getSocket()->close(ignoreMessages: true);
            exit(0);
        }

        return $fork;
    }

    /**
     * @param Task[] $tasks
     * @return array<string, TaskResult>
     */
    public function runTasksInThreads(array $tasks, int $maxConcurrent = 4): array
    {
        try {
            foreach ($tasks as $task) {
                if (!$task instanceof Task) {
                    throw new InvalidArgumentException('All items in tasks array must be instances of Task.');
                }
            }

            $maxConcurrent = max(1, min($maxConcurrent, count($tasks)));

            $this->threadTaskBuffer->addTasks($tasks);

            $workerThreads = [];
            for ($i = 0; $i < $maxConcurrent; $i++) {
                $workerThread = new Fork(
                    callable: $this->runThreadsWorker(...),
                    arguments: [],
                );
                $workerThreads[$workerThread->getId()] = $workerThread;
                $this->runTask($workerThread);
            }

            $threadOutputs = [];

            // Wait for remaining forks to finish
            while (!empty($tasks)) {
                usleep(self::DEFAULT_IDLE_PAUSE_MICROSECONDS / 2);
                foreach ($workerThreads as $workerThread) {
                    $messages = $workerThread->getSocket()->getMessages(topic: 'ready_for_task');
                    foreach ($messages as $message) {
                        if (empty($tasks)) {
                            break 2;
                        }
                        $nextTask = array_shift($tasks);
                        $nextTask->markAsStarted();

                        $workerThread->getSocket()->send(new Message(
                            topic: 'new_task',
                            content: [
                                'taskId' => $nextTask->getId(),
                            ],
                        ));
                    }
                    $messages = $workerThread->getSocket()->getMessages(topic: 'fork_error');
                    foreach ($messages as $message) {
                        $error = $message->getContent();
                        $forkId = $message->getForkId();
                        unset($workerThreads[$forkId]);

                        if (empty($workerThreads)) {
                            throw new ParallelRunException("All worker threads have failed. {$error->getMessage()}");
                        }
                    }
                }
                usleep(self::DEFAULT_IDLE_PAUSE_MICROSECONDS / 2);
            }

            foreach ($workerThreads as $workerThread) {
                $workerThread->getSocket()->send(new Message(
                    topic: 'shutdown',
                ));
            }

            foreach ($workerThreads as $workerThread) {
                $workerThread->waitToComplete(60);
            }

            foreach ($workerThreads as $workerThread) {
                $results = $workerThread->getSocket()->getMessages(waitForMessages: true, topic: 'thread_result');
                foreach ($results as $result) {
                    /**
                     * @var TaskResult
                     */
                    $threadResponse = $result->getContent();
                    $threadResponse->throwIfCritical();
                    $threadOutputs[$result->getReplyTo()] = $threadResponse;
                }
            }
            return $threadOutputs;
        } catch (Throwable $e) {
            throw new ParallelRunException('Error during parallel execution: ' . $e->getMessage(), previous: $e);
        } finally {
            $this->threadTaskBuffer->reset();
        }
    }

    private function runThreadsWorker(Fork $fork): void
    {
        $fork->getSocket()->send(new Message(
            topic: 'ready_for_task',
        ));
        $active = true;
        while ($active) {
            $messages = $fork->getSocket()->getMessages();
            foreach ($messages as $message) {
                if ($message->getTopic() === 'new_task') {
                    $taskId = $message->getContent()['taskId'];

                    $threadTask = $this->threadTaskBuffer->getTask($taskId);
                    $callable = $threadTask->getCallable();
                    $arguments = $threadTask->getArguments();

                    $error = null;
                    $output = null;
                    $result = null;
                    try {
                        ob_start();
                        $result = $callable(...array_merge([$threadTask], $arguments));
                    } catch (Throwable $e) {
                        $error = $e;
                    }
                    $output = ob_get_clean();

                    $response = new TaskResult(
                        result: $result,
                        output: $output,
                        error: $error,
                        criticalError: $threadTask->isCritical(),
                    );

                    $fork->getSocket()->send(new Message(
                        topic: 'thread_result',
                        content: $response,
                        replyTo: $taskId,
                    ));
                    $fork->getSocket()->send(new Message(
                        topic: 'ready_for_task'
                    ));
                } elseif ($message->getTopic() === 'shutdown') {
                    $active = false;
                    return;
                }
            }
            usleep(self::DEFAULT_IDLE_PAUSE_MICROSECONDS);
        }
    }

    private function fixPcntlIssues(): void
    {
        $this->fixPcntlRandIssues();
    }

    private function fixPcntlRandIssues(): void
    {
        usleep(random_int(0, 500));
        mt_srand();
    }

}
