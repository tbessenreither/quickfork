<?php declare(strict_types=1);

namespace Tbessenreither\Quickfork\Objects\Socket;

use InvalidArgumentException;
use RuntimeException;


class Message
{
    private string $id;
    private mixed $replyHandler = null;

    public function __construct(
        private string $topic,
        private mixed $content = null,
        private ?string $forkId = null,
        private ?string $replyTo = null,
    ) {
        $this->id = uniqid('msg_', true);
    }

    public static function fromString(string $encodedData): self
    {
        $decoded = base64_decode($encodedData);
        $uncompressed = gzuncompress($decoded);
        $deserialized = unserialize($uncompressed);

        if (!$deserialized instanceof self) {
            throw new InvalidArgumentException('Decoded data is not a valid Message object.');
        }

        return $deserialized;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getContent(): mixed
    {
        return $this->content;
    }

    public function getForkId(): ?string
    {
        return $this->forkId;
    }

    public function getEncoded(): string
    {
        $serialized = serialize($this);
        $compressed = gzcompress($serialized);
        $encoded = base64_encode($compressed);

        return $encoded;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setReplyTo(string $replyTo): void
    {
        $this->replyTo = $replyTo;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function setupReplyHandler(callable $handler): void
    {
        if ($this->replyHandler !== null) {
            throw new RuntimeException('Reply handler can only be set up once per message.');
        }

        $this->replyHandler = $handler;
    }

    public function reply(Message $message): void
    {
        if ($this->replyHandler === null) {
            throw new RuntimeException('No reply handler set up for this message.');
        }

        $message->setReplyTo($this->getId());

        $handler = $this->replyHandler;
        $handler($message);
    }

}
