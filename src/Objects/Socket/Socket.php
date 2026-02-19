<?php declare(strict_types=1);

namespace Tbessenreither\QuickFork\Objects\Socket;


class Socket
{
    private const string MESSAGE_DELIMITER = "\n";

    private $readBuffer = '';

    private $socket;
    private bool $isClosed = false;
    private array $messageBuffer = [];

    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    public function getSocket(): mixed
    {
        if ($this->isClosed) {
            return false;
        }

        return $this->socket;
    }

    public function close(bool $ignoreMessages = false): void
    {
        if (!$ignoreMessages) {
            $this->checkForMessages();
        }

        if (!$this->isClosed) {
            fclose($this->socket);
            $this->isClosed = true;
        }
    }

    public function send(Message $message): bool
    {
        if ($this->isClosed) {
            return false;
        }
        $writeResult = fwrite($this->getSocket(), $message->getEncoded() . self::MESSAGE_DELIMITER);
        fflush($this->getSocket());

        return $writeResult !== false && $writeResult > 0;
    }

    /**
     * @return Message[]
     */
    public function getMessages(bool $waitForMessages = false, ?string $topic = null): array
    {
        $this->checkForMessages($waitForMessages);

        if ($topic !== null) {
            $topicFilteredMessages = [];
            foreach ($this->messageBuffer as $index => $message) {
                if ($message->getTopic() === $topic) {
                    $topicFilteredMessages[] = $message;
                    unset($this->messageBuffer[$index]);
                }
            }
            return $topicFilteredMessages;
        }

        $messageBufferCopy = $this->messageBuffer;
        $this->messageBuffer = [];
        return $messageBufferCopy;
    }

    /**
     * @return Message[]|false
     */
    private function checkForMessages(bool $waitForMessages = true): void
    {
        if ($this->isClosed) {
            return;
        }

        stream_set_blocking($this->getSocket(), $waitForMessages);
        $streamContents = stream_get_contents($this->getSocket());
        stream_set_blocking($this->getSocket(), true);
        $this->readBuffer .= $streamContents;

        if (empty($this->readBuffer)) {
            return;
        }

        $parts = explode(self::MESSAGE_DELIMITER, $this->readBuffer);

        $this->readBuffer = array_pop($parts);

        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }
            $this->messageBuffer[] = Message::fromString($part);
        }
    }

}
