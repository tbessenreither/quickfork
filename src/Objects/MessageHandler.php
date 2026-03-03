<?php declare(strict_types=1);

namespace Tbessenreither\Quickfork\Objects;

use BadFunctionCallException;
use ReflectionMethod;
use Tbessenreither\Quickfork\Objects\Socket\Message;
use Tbessenreither\Quickfork\Objects\Socket\Socket;
use Tbessenreither\Quickfork\Service\ExponentialBackoff;
use Throwable;


abstract class MessageHandler
{
	private array $validatedHandlerMethods = [];

	private bool $listenActive = false;

	public function __construct(
		private Fork $fork,
	) {
	}

	protected function stopListening(): void
	{
		$this->listenActive = false;
	}

	public function listen(?int $maxRuntimeSeconds = 60, ?ExponentialBackoff $backoff): void
	{
		if ($backoff === null) {
			$backoff = new ExponentialBackoff();
		}

		$this->listenActive = true;
		$backoff->reset();
		$startTime = time();

		while ($this->listenActive) {
			$messages = $this->fork->getSocket()->getMessages();

			$this->processMessages($messages);

			if ($maxRuntimeSeconds !== null && time() - $startTime >= $maxRuntimeSeconds) {
				$this->listenActive = false;
			} elseif (!empty($messages)) {
				$backoff->reset();
			} else {
				$backoff->sleep();
			}
		}
	}

	/**
	 * @param Message[] $messages
	 */
	private function processMessages(array $messages): void
	{
		foreach ($messages as $message) {
			try {
				$topic = $message->getTopic();
				$handlerMethod = $this->validateHandlerMethod($topic);
				$handlerMethod($topic, $message, $this->fork->getSocket());
			} catch (Throwable $e) {
				error_log("Error processing message with topic '{$message->getTopic()}': " . $e->getMessage());
			}
		}
	}

	private function validateHandlerMethod(string $topic): callable
	{
		if (isset($this->validatedHandlerMethods[$topic])) {
			return $this->validatedHandlerMethods[$topic];
		}

		$methodName = 'handle' . ucfirst($topic);

		if (!method_exists($this, $methodName)) {
			throw new BadFunctionCallException("No handler method found for topic '$topic'. Expected method name: $methodName");
		}
		$reflectionMethod = new ReflectionMethod($this, $methodName);
		// ensure the method arguments are: string $topic, Message $message, Socket $socket
		if ($reflectionMethod->getNumberOfParameters() !== 3) {
			throw new BadFunctionCallException("Handler method $methodName must have exactly 3 parameters: string \$topic, mixed \$message, Socket \$socket");
		}
		if ($reflectionMethod->getParameters()[0]->getName() !== 'topic' || $reflectionMethod->getParameters()[0]->getType()->getName() !== 'string') {
			throw new BadFunctionCallException("First parameter of handler method $methodName must be: string \$topic");
		}
		if ($reflectionMethod->getParameters()[1]->getName() !== 'message' || $reflectionMethod->getParameters()[1]->getType()->getName() !== Message::class) {
			throw new BadFunctionCallException("Second parameter of handler method $methodName must be: Message \$message");
		}
		if ($reflectionMethod->getParameters()[2]->getName() !== 'socket' || $reflectionMethod->getParameters()[2]->getType()->getName() !== Socket::class) {
			throw new BadFunctionCallException("Third parameter of handler method $methodName must be: Socket \$socket");
		}

		$this->validatedHandlerMethods[$topic] = [$this, $methodName];

		return $this->validatedHandlerMethods[$topic];
	}

}