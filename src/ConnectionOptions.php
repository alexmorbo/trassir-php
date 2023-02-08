<?php

declare(strict_types=1);

namespace AlexMorbo\Trassir;

use AlexMorbo\Trassir\Dto\Server;

class ConnectionOptions
{
    public function __construct(
        private readonly string $host,
        private readonly int $httpPort,
        private readonly int $rtspPort,
        private readonly string $login,
        private readonly string $password,
        private readonly ?string $proxy = null,
    ) {
    }

    public static function fromServer(Server $server): self
    {
        return new self(
            $server->getHost(),
            $server->getHttpPort(),
            $server->getRtspPort(),
            $server->getLogin(),
            $server->getPassword()
        );
    }

    public static function fromServerArray(array $server): self
    {
        return new self(
            $server['host'],
            $server['http_port'],
            $server['rtsp_port'],
            $server['login'],
            $server['password']
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

    public function getProxy(): ?string
    {
        return $this->proxy;
    }
}