<?php

namespace AlexMorbo\Trassir\Dto;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

readonly class Server
{
    public function __construct(
        private mixed $id,
        private string $host,
        private int $httpPort,
        private int $rtspPort,
        private string $login,
        private string $password,
        private LoggerInterface $logger,
        private ?string $proxy = null,
    ) {
    }

    public static function fromArray(array $server): self
    {
        return new self(
            $server['id'] ?? uniqid('', true),
            $server['host'],
            $server['httpPort'],
            $server['rtspPort'],
            $server['login'],
            $server['password'],
            $server['logger'] ?? new NullLogger(),
            $server['proxy'] ?? null,
        );
    }

    public function getId(): mixed
    {
        return $this->id;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getHttpPort(): int
    {
        return $this->httpPort;
    }

    public function getRtspPort(): int
    {
        return $this->rtspPort;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getProxy(): ?string
    {
        return $this->proxy;
    }
}