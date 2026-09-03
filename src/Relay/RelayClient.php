<?php

namespace Ernestdefoe\Courier\Relay;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Talks to the mail relay.
 *
 * 🚨 The prefix lives here, in one constant, not in each caller. When the two
 * halves of a hosted product disagree about a path every call 404s — and a 404
 * arrives looking exactly like a refusal or an outage, so both halves pass
 * testing individually while nothing works. That has already happened once.
 */
class RelayClient
{
    private const PREFIX = '/api/steward/v1/';

    private Client $http;

    /** Set when the service answered and refused us, as opposed to not answering. */
    public ?string $lastRefusal = null;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Config $config,
        private LoggerInterface $log,
        ?Client $http = null
    ) {
        $this->http = $http ?? new Client([
            'timeout'         => 20,
            'connect_timeout' => 6,
            'http_errors'     => true,
        ]);
    }

    private function s(string $key): string
    {
        return (string) ($this->settings->get('courier.' . $key) ?: '');
    }

    public function configured(): bool
    {
        return $this->s('relay_url') !== '' && $this->s('site_key') !== '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null null when the call failed
     */
    public function post(string $path, array $payload): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        $url = rtrim($this->s('relay_url'), '/') . self::PREFIX . ltrim($path, '/');

        try {
            $res = $this->http->post($url, [
                'headers' => array_filter([
                    'Authorization' => 'Bearer ' . $this->s('site_key'),
                    'Accept'        => 'application/json',
                    /*
                     * The key is bound to one origin and re-checked on every
                     * call, so a client that sends no Origin is refused every
                     * time — which is what a stolen key looks like. Taken from
                     * config, never from a request: an origin read from an
                     * incoming request is an origin the caller chooses.
                     */
                    'Origin' => $this->forumUrl(),
                ]),
                'json' => $payload,
            ]);

            $body = json_decode((string) $res->getBody(), true);

            return is_array($body) ? $body : [];
        } catch (ClientException $e) {
            /*
             * 🚨 A refusal is not an outage, and saying so matters. "Could not
             * reach the service" sends somebody looking at their network for a
             * problem that is actually a wrong key or an unbound origin — and
             * unlike an outage, retrying will never fix it.
             */
            $status = $e->getResponse()->getStatusCode();
            $body   = json_decode((string) $e->getResponse()->getBody(), true);

            $this->log->error('[courier] the service refused this forum', [
                'path'   => $path,
                'status' => $status,
                'reason' => $body['error'] ?? null,
                'origin' => $this->forumUrl(),
            ]);

            $this->lastRefusal = (string) ($body['error'] ?? ('http_' . $status));

            return null;
        } catch (GuzzleException $e) {
            $this->log->warning('[courier] could not reach the service', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 🚨 From config.php, not the settings table.
     *
     * `forum_url` is not a setting Flarum keeps — reading it there returns an
     * empty string, the Origin header is then dropped, and the relay refuses
     * every call because a key with no origin is what a stolen key looks like.
     * The canonical URL is the one in config, and it is also the identity the
     * key is bound to.
     */
    private function forumUrl(): string
    {
        return rtrim((string) $this->config->url(), '/');
    }
}
