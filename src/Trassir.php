<?php

namespace AlexMorbo\Trassir;

use AlexMorbo\Trassir\Client\AsyncClient;
use AlexMorbo\Trassir\Client\ClientInterface;
use Carbon\Carbon;
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

    public function getChannels(): array
    {
        return $this->client->getChannels();
    }

    public function getScreenshot(string $serverId, string $channelId): PromiseInterface
    {
        return $this->client->getScreenshot($serverId, $channelId);
    }

    public function getVideo(string $serverId, string $channelId, string $container, string $stream): PromiseInterface
    {
        return $this->client->getVideo($serverId, $channelId, $container, $stream);
    }

    public function downloadArchiveVideo(string $channelId, Carbon $start, Carbon $end): PromiseInterface
    {
        return $this->client->downloadArchiveVideo($channelId, $start, $end);
    }
}