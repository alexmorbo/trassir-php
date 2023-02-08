<?php

namespace AlexMorbo\Trassir\Client;

use AlexMorbo\Trassir\ConnectionOptions;
use AlexMorbo\Trassir\Enum\ConnectionState;
use AlexMorbo\Trassir\Enum\VideoContainer;
use AlexMorbo\Trassir\TrassirException;
use Clue\React\HttpProxy\ProxyConnector;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use RingCentral\Psr7\Response;

use function React\Promise\reject;
use function React\Promise\resolve;

class AsyncClient implements ClientInterface
{
    private Browser $client;
    private int $state;
    private string $sid;
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

        $this->client = (new Browser($connector))
            ->withBase(
                sprintf('https://%s:%d', $options->getHost(), $options->getHttpPort())
            )
            ->withTimeout(10.0);

        $this->logger->debug(
            'Client created, base url: ' . sprintf('https://%s:%d', $options->getHost(), $options->getHttpPort())
        );
    }

    public function auth(): PromiseInterface
    {
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
            ->then(function (Response $response) {
                $data = json_decode($response->getBody()->getContents(), true);

                if ($data['success'] !== 1) {
                    $this->state = ConnectionState::AUTH_ERROR->value;
                    $this->logger->error('Auth error: ' . $data['error']);

                    return reject(new TrassirException('Login failed', 400));
                }

                $this->sid = $data['sid'];
                $this->state = ConnectionState::HAVE_SID->value;
                $this->logger->debug('Auth success, sid: ' . $this->sid);

                if (!$this->healthTimer) {
                    $this->healthTimer = Loop::addPeriodicTimer(120, function () {
                        return $this->health()
                            ->then(
                                function ($data) {
                                    if (isset($data['error_code']) && $data['error_code'] === 'no session') {
                                        return $this->auth();
                                    }

                                    return $data;
                                },
                                fn() => var_dump('fail', func_get_args())
                            );
                    });
                }

                return resolve($data['sid']);
            })
            ->then(fn() => $this->fetchSettings())
            ->then(fn() => $this->fetchChannels())
            ->then(function () {
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
            });
    }

    private function health()
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

    public function getVideo(string $serverId, string $channelId, string $container): PromiseInterface
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
                    'stream' => 'main',
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
}