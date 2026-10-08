<?php

namespace Ernestdefoe\Courier\Tests\integration;

use Ernestdefoe\Courier\Relay\RelayClient;
use Flarum\Extend\ExtenderInterface;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Extension\Extension;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * The reply service, as the tests see it: canned responses in order, and
 * every request kept. No test reaches the network.
 */
trait FakeRelay
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    protected array $relayRequests = [];

    protected function connect(): void
    {
        $this->setting('courier.relay_url', 'https://relay.example');
        $this->setting('courier.site_key', 'test-site-key');
    }

    /**
     * The relay answers $responses in turn. Call before the app boots: the
     * notification drivers, and the relay client inside Courier's, are built
     * during boot.
     */
    protected function relay(Response ...$responses): void
    {
        $this->relayRequests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->relayRequests));
        $client = new Client(['handler' => $stack, 'http_errors' => true]);

        $this->extend(new class($client) implements ExtenderInterface {
            public function __construct(private Client $client)
            {
            }

            public function extend(Container $container, ?Extension $extension = null): void
            {
                // The relay client itself, not just Guzzle: RelayClient's
                // client parameter is optional, and an optional dependency
                // the container has not bound is left to its default.
                $container->bind(RelayClient::class, fn (Container $c) => new RelayClient(
                    $c->make(SettingsRepositoryInterface::class),
                    $c->make(Config::class),
                    $c->make(LoggerInterface::class),
                    $this->client
                ));
            }
        });
    }

    protected function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @return list<array{path: string, body: array<string, mixed>, auth: string}> */
    protected function relayCalls(): array
    {
        return array_map(fn ($entry) => [
            'path' => $entry['request']->getUri()->getPath(),
            'body' => json_decode((string) $entry['request']->getBody(), true),
            'auth' => $entry['request']->getHeaderLine('Authorization'),
        ], $this->relayRequests);
    }
}
