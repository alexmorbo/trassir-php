<?php

namespace AlexMorbo\Trassir\Dto;

class Server
{
    public function __construct(
        private readonly string $host,
        private readonly int $httpPort,
        private readonly int $rtspPort,
        private readonly string $login,
        private readonly string $password
    ) {
    }

    public static function fromArray(array $server): self
    {
        return new self(
            $server['host'],
            $server['httpPort'],
            $server['rtspPort'],
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
}