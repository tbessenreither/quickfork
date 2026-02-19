<?php declare(strict_types=1);

namespace Tbessenreither\QuickFork\Objects\Socket;

use InvalidArgumentException;


class Message
{
    private string $id;

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

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

}
