<?php

namespace AlexMorbo\Trassir;

use AlexMorbo\Trassir\Client\AsyncClient;
use AlexMorbo\Trassir\Client\ClientInterface;
use React\Promise\PromiseInterface;

class Trassir
{
    private ClientInterface $client;
    private static array $instances = [];
    private ?PromiseInterface $connection = null;

    /**
     * @throws TrassirException
     */
    public static function getInstance(ConnectionOptions $options, bool $async = true): self
    {
        if (!isset(self::$instances[$options->getHash()])) {
            self::$instances[$options->getHash()] = new self();
            if ($async) {
                self::$instances[$options->getHash()]->connectAsync($options);
            } else {
                throw new TrassirException('Sync client is not implemented yet');
            }
        }

        return self::$instances[$options->getHash()];
    }

    public function connectAsync(ConnectionOptions $options): PromiseInterface
    {
        $this->client = new AsyncClient($options);
        $this->connection = $this->client->auth();

        return $this->connection;
    }

    public function getConnection(): PromiseInterface
    {
        return $this->connection;
    }

    public function getState(): int
    {
        return $this->client->getState();
    }

    public function getSettings(): array
    {
        return $this->client->getSettings();
    }

    public function getChannels()
    {
        return $this->client->getChannels();
    }

    public function getScreenshot(string $serverId, string $channelId)
    {
        return $this->client->getScreenshot($serverId, $channelId);
    }

    public function getVideo(string $serverId, string $channelId, string $container)
    {
        return $this->client->getVideo($serverId, $channelId, $container);
    }
}