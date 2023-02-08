<?php

declare(strict_types=1);

namespace AlexMorbo\Trassir;

use AlexMorbo\Trassir\Dto\Server;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

readonly class ConnectionOptions
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

    public static function fromServer(Server $server): self
    {
        return new self(
            $server->getId(),
            $server->getHost(),
            $server->getHttpPort(),
            $server->getRtspPort(),
            $server->getLogin(),
            $server->getPassword(),
            $server->getLogger(),
            $server->getProxy(),
        );
    }

    public static function fromServerArray(array $server): self
    {
        return new self(
            $server['id'] ?? uniqid('', true),
            $server['host'],
            $server['http_port'],
            $server['rtsp_port'],
            $server['login'],
            $server['password'],
            $server['logger'] ?? new NullLogger(),
            $server['proxy'] ?? null,
        );
    }

    public function getHash(): string
    {
        return substr(
            md5($this->id . $this->host . $this->httpPort . $this->rtspPort . $this->login . $this->password),
            0,
            8
        );
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