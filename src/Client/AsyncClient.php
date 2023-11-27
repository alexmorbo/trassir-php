<?php

namespace AlexMorbo\Trassir\Client;

use AlexMorbo\Trassir\ConnectionOptions;
use AlexMorbo\Trassir\Enum\ConnectionState;
use AlexMorbo\Trassir\Enum\VideoContainer;
use AlexMorbo\Trassir\TrassirException;
use Carbon\Carbon;
use Clue\React\HttpProxy\ProxyConnector;
use Exception;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Stream\ReadableStreamInterface;
use RingCentral\Psr7\Response;

use function React\Promise\reject;
use function React\Promise\resolve;
use function React\Promise\Stream\all;

class AsyncClient implements ClientInterface
{
    private Browser $client;
    private int $state;
    private string $sid;
    private string $host;
    private ?array $settings = [];
    private ?array $channels = [];
    private ?TimerInterface $healthTimer = null;
    private ?TimerInterface $settingsTimer = null;
    private ?TimerInterface $channelsTimer = null;
    private LoggerInterface $logger;

    public function __construct(private ConnectionOptions $options)
    {
        $this->logger = $options->getLogger();
        $this->state = ConnectionState::INIT->value;
        if ($proxyUrl = $this->options->getProxy()) {
            $this->logger->debug('Using proxy: ' . $proxyUrl);
            $proxy = new ProxyConnector($proxyUrl);
        } else {
            $proxy = null;
        }
        $connector = new Connector([
            'tcp' => $proxy,
            'tls' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        $this->host = $this->options->getHost();
        $this->client = (new Browser($connector))
            ->withBase(
                sprintf('https://%s:%d', $options->getHost(), $options->getHttpPort())
            )
            ->withTimeout(30.0);

        $this->logger->debug(
            'Client created, base url: ' . sprintf('https://%s:%d', $options->getHost(), $options->getHttpPort())
        );
    }

    public function auth($attempt = 0): PromiseInterface
    {
        $this->logger->debug('Start auth for ' . $this->host . ', attempt: ' . $attempt);
        return $this->client
            ->post(
                '/login',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                http_build_query(
                    [
                        'username' => $this->options->getLogin(),
                        'password' => $this->options->getPassword(),
                    ]
                )
            )
            ->then(
                function (Response $response) use ($attempt) {
                    $data = json_decode($response->getBody()->getContents(), true);

                    if ($data['success'] !== 1) {
                        $this->state = ConnectionState::AUTH_ERROR->value;
                        $this->logger->error('Auth error: ' . $data['error_code'] . ' - ' . $data['help']);

                        return reject(new TrassirException('Login failed', 400));
                    }

                    $this->sid = $data['sid'];
                    $this->state = ConnectionState::HAVE_SID->value;
                    $this->logger->debug('Auth success for ' . $this->host . ', sid: ' . $this->sid);

                    if (!$this->healthTimer) {
                        $this->healthTimer = Loop::addPeriodicTimer(20, function () use ($attempt) {
                            return $this->health()
                                ->then(
                                    function ($data) {
                                        if (isset($data['error_code']) && $data['error_code'] === 'no session') {
                                            return $this->auth(0);
                                        }

                                        return $data;
                                    },
                                    function () use ($attempt) {
                                        $this->logger->error('Health check failed', [func_get_args()]);

                                        return $this->auth($attempt + 1);
                                    }
                                );
                        });
                    }

                    return resolve($data['sid']);
                },
                function (Exception $e) {
                    return reject(new TrassirException('Login failed: ' . $e->getMessage(), $e->getCode()));
                }
            )
            ->then(
                fn() => $this->fetchSettings(),
            )
            ->then(
                fn() => $this->fetchChannels()
            )
            ->then(
                function () {
                    if (!$this->settingsTimer) {
                        $this->settingsTimer = Loop::addPeriodicTimer(300, function () {
                            return $this->fetchSettings();
                        });
                    }
                    if (!$this->channelsTimer) {
                        $this->channelsTimer = Loop::addPeriodicTimer(300, function () {
                            return $this->fetchChannels();
                        });
                    }
                }
            )
            ->catch(
                function (Exception $e) use ($attempt) {
                    Loop::addTimer(10, fn() => $this->auth($attempt + 1));
                    $this->logger->error('Auth failed: ' . $e->getMessage(), [func_get_args()]);
                }
            );
    }

    private function health(): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        return $this->client->post(
            '/health',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query(
                [
                    'sid' => $this->sid,
                ]
            )
        )->then(function (Response $response) {
            $data = json_decode($response->getBody()->getContents(), true);
            return resolve($data);
        });
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getState(): int
    {
        return $this->state;
    }

    private function fetchSettings(): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        return $this->client->post(
            '/settings/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query(
                [
                    'sid' => $this->sid,
                ]
            )
        )->then(function (Response $response) {
            $this->settings = json_decode($response->getBody()->getContents(), true);

            return $this->settings;
        });
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function fetchChannels(): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        return $this->client->post(
            '/channels/',
            [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            http_build_query(
                [
                    'sid' => $this->sid,
                ]
            )
        )->then(function (Response $response) {
            $this->channels = json_decode($response->getBody()->getContents(), true);

            return $this->channels;
        });
    }

    public function getScreenshot(string $serverId, string $channelId): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        $url = sprintf(
            '/screenshot?%s',
            http_build_query(
                [
                    'sid' => $this->sid,
                    'server' => $serverId,
                    'channel' => $channelId,
                ]
            )
        );

        return $this->client->get($url)
            ->then(
                function (Response $response) use ($serverId, $channelId) {
                    if ($response->getHeaders()['Content-Type'][0] === 'image/jpeg') {
                        return $response->getBody()->getContents();
                    } else {
                        return $this->getThumbnail($serverId, $channelId);
                    }
                },
            );
    }

    public function getThumbnail(string $serverId, string $channelId): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        $url = sprintf(
            '/thumbnail?%s',
            http_build_query(
                [
                    'sid' => $this->sid,
                    'server' => $serverId,
                    'channel' => $channelId,
                ]
            )
        );

        return $this->client->get($url)
            ->then(
                function (Response $response) {
                    return $response->getBody()->getContents();
                },
            );
    }

    public function getVideo(string $serverId, string $channelId, string $container, string $stream): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        $url = sprintf(
            '/get_video?%s',
            http_build_query(
                [
                    'sid' => $this->sid,
                    'server' => $serverId,
                    'channel' => $channelId,
                    'stream' => $stream,
                    'container' => $container,
                    'audio' => 'pcmu',
                ]
            )
        );

        return $this->client->get($url)
            ->then(
                function (Response $response) use ($container) {
                    $data = json_decode($response->getBody()->getContents(), true);

                    return match (true) {
                        $container == VideoContainer::HLS->value => sprintf(
                            'https://%s:%d/hls/%s/master.m3u8',
                            $this->options->getHost(),
                            $this->options->getHttpPort(),
                            $data['token']
                        ),
                        $container == VideoContainer::RTSP->value => sprintf(
                            'rtsp://%s:%d/%s',
                            $this->options->getHost(),
                            $this->options->getRtspPort(),
                            $data['token']
                        ),
                        default => throw new TrassirException('Unknown video container', 400),
                    };
                },
            );
    }

    public function downloadArchiveVideo(string $channelId, Carbon $start, Carbon $end): PromiseInterface
    {
        return $this
            ->requestArchiveTask($channelId, $start, $end)
            ->then(fn(string $taskId) => $this->exportArchiveTask($taskId))
            ->then(function(ReadableStreamInterface $stream) {
                return all($stream)->then(function (array $chunks) {
                    return implode('', $chunks);
                });
            });
    }

    private function requestArchiveTask(string $channelId, Carbon $start, Carbon $end): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(new TrassirException('Not authorized', 401));
        }

        $body = [
            'resource_guid' => $channelId,
            'start_ts' => $start->getTimestamp() * 1000000,
            'end_ts' => $end->getTimestamp() * 1000000,
            'is_hardware' => 0,
            'prefer_substream' => 0,
        ];

        return $this->client->post(
            sprintf("/jit-export-create-task?sid=%s", $this->sid),
            [
                'Content-Type' => 'application/json'
            ],
            json_encode($body)
        )
            ->then(
                function (Response $response) {
                    $data = json_decode($response->getBody()->getContents(), true);
                    if (! $data['success']) {
                        throw new TrassirException($data['error_code']);
                    }

                    return $data['task_id'];
                },
            );
    }

    private function exportArchiveTask(string $taskId): PromiseInterface
    {
        if ($this->state < ConnectionState::HAVE_SID->value) {
            return reject(throw new TrassirException('Not authorized', 401));
        }

        return $this->client
            ->requestStreaming('GET', sprintf("/jit-export-download?sid=%s&task_id=%s", $this->sid, $taskId))
            ->then(fn(Response $response) => $response->getBody());
    }
}