<?php

namespace ContaShare\Socialite;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * "Sign in with ContaShare" for Laravel Socialite.
 *
 * The user's `id` is the ID your company knows them by (GET /api/v1/me, free): it never changes, it is the same for every
 * OAuth client your team owns, and another company gets a different one for the same person. ContaShare doesn't put a name
 * or email on the user: call sharedData() with the token once, when you create the account.
 */
class ContaShareProvider extends AbstractProvider
{
    public const DEFAULT_BASE_URL = 'https://contashare.com';

    /**
     * ContaShare asks no scopes: the customer chooses each record to share on the consent screen.
     *
     * @var array<int, string>
     */
    protected $scopes = [];

    protected $scopeSeparator = ' ';

    protected string $baseUrl = self::DEFAULT_BASE_URL;

    /**
     * Use another ContaShare, such as a local copy while you develop.
     */
    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        return $this;
    }

    /**
     * Show the customer the consent screen even if they approved your app before, so they can share something new.
     */
    public function reconfirm(): static
    {
        return $this->with(['reconfirm' => 'true']);
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->baseUrl.'/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->baseUrl.'/oauth/token';
    }

    /**
     * @return array{id: string}
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->baseUrl.'/api/v1/me', [
            RequestOptions::HEADERS => $this->apiHeaders($token),
            // Never followed, so the customer's token can't be carried to another site.
            RequestOptions::ALLOW_REDIRECTS => false,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param  array{id: string}  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'name' => null,
            'email' => null,
        ]);
    }

    /**
     * Everything the customer shares with you, in one call (1 credit). It answers once per customer: after that it only
     * answers again when they have shared something new. Returns null when there is nothing new, so you look records up
     * with the list endpoints instead.
     *
     * @return array<string, mixed>|null
     */
    public function sharedData(string $token): ?array
    {
        $response = $this->getHttpClient()->get($this->baseUrl.'/api/v1/shared-data', [
            RequestOptions::HEADERS => $this->apiHeaders($token),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() === 200) {
            return $body;
        }

        if ($response->getStatusCode() === 403 && is_array($body) && isset($body['error'])) {
            return null;
        }

        throw new RuntimeException(sprintf(
            'ContaShare answered %d: %s',
            $response->getStatusCode(),
            is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '',
        ));
    }

    /**
     * Without Accept: application/json, ContaShare redirects an unauthorised call to its login page instead of a 401.
     *
     * @return array<string, string>
     */
    private function apiHeaders(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
