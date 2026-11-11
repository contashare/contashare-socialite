<?php

namespace ContaShare\Socialite\Tests;

use ContaShare\Socialite\ContaShareProvider;
use ContaShare\Socialite\ContaShareServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class ContaShareProviderTest extends TestCase
{
    /**
     * @var list<array{request: \Psr\Http\Message\RequestInterface}>
     */
    private array $requests = [];

    protected function getPackageProviders($app): array
    {
        return [SocialiteServiceProvider::class, ContaShareServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('services.contashare', [
            'client_id' => 'client-123',
            'client_secret' => 'secret-456',
            'redirect' => 'https://acme.example/auth/contashare/callback',
        ]);
    }

    private function provider(Request $request, Response ...$answers): ContaShareProvider
    {
        $handler = HandlerStack::create(new MockHandler($answers));
        $handler->push(Middleware::history($this->requests));

        $provider = new ContaShareProvider($request, 'client-123', 'secret-456', 'https://acme.example/auth/contashare/callback');
        $provider->setHttpClient(new Client(['handler' => $handler]));

        return $provider;
    }

    public function test_it_sends_people_to_the_consent_screen_with_the_apps_details(): void
    {
        $url = $this->provider(Request::create('/login'))->stateless()->redirect()->getTargetUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://contashare.com/oauth/authorize?', $url);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame('https://acme.example/auth/contashare/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertArrayNotHasKey('reconfirm', $query);
    }

    public function test_reconfirm_shows_the_consent_screen_again(): void
    {
        $url = $this->provider(Request::create('/login'))->stateless()->reconfirm()->redirect()->getTargetUrl();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('true', $query['reconfirm']);
    }

    public function test_the_signed_in_user_is_the_id_from_me_with_the_token_pair(): void
    {
        $provider = $this->provider(
            Request::create('/callback', 'GET', ['code' => 'the-code']),
            new Response(200, [], json_encode(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => 'access-1', 'refresh_token' => 'refresh-1'])),
            new Response(200, [], json_encode(['id' => '9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f'])),
        );

        $user = $provider->stateless()->user();

        $this->assertSame('9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f', $user->getId());
        $this->assertSame('access-1', $user->token);
        $this->assertSame('refresh-1', $user->refreshToken);
        $this->assertSame(31536000, $user->expiresIn);
        $this->assertNull($user->getEmail());

        [$tokenCall, $meCall] = array_column($this->requests, 'request');
        parse_str((string) $tokenCall->getBody(), $tokenFields);
        $this->assertSame('https://contashare.com/oauth/token', (string) $tokenCall->getUri());
        $this->assertSame(['grant_type' => 'authorization_code', 'client_id' => 'client-123', 'client_secret' => 'secret-456', 'code' => 'the-code', 'redirect_uri' => 'https://acme.example/auth/contashare/callback'], $tokenFields);
        $this->assertSame('https://contashare.com/api/v1/me', (string) $meCall->getUri());
        $this->assertSame('Bearer access-1', $meCall->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $meCall->getHeaderLine('Accept'));
    }

    public function test_refreshing_swaps_the_pair(): void
    {
        $provider = $this->provider(
            Request::create('/'),
            new Response(200, [], json_encode(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => 'access-2', 'refresh_token' => 'refresh-2'])),
        );

        $token = $provider->refreshToken('refresh-1');

        parse_str((string) $this->requests[0]['request']->getBody(), $fields);
        $this->assertSame('access-2', $token->token);
        $this->assertSame('refresh-2', $token->refreshToken);
        $this->assertSame('refresh_token', $fields['grant_type']);
        $this->assertSame('refresh-1', $fields['refresh_token']);
    }

    public function test_shared_data_returns_the_records_once_and_null_when_there_is_nothing_new(): void
    {
        $provider = $this->provider(
            Request::create('/'),
            new Response(200, [], json_encode(['emails' => [['id' => 'email-1']], 'names' => []])),
            new Response(403, [], json_encode(['error' => 'You have already fetched bulk shared data for this customer.'])),
        );

        $this->assertSame([['id' => 'email-1']], $provider->sharedData('access-1')['emails']);
        $this->assertNull($provider->sharedData('access-1'));
        $this->assertSame('https://contashare.com/api/v1/shared-data', (string) $this->requests[0]['request']->getUri());
    }

    public function test_shared_data_says_why_when_contashare_refuses(): void
    {
        $provider = $this->provider(Request::create('/'), new Response(403, [], json_encode(['message' => 'Your credits have run out.'])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ContaShare answered 403: Your credits have run out.');

        $provider->sharedData('access-1');
    }

    public function test_the_contashare_driver_is_set_up_from_the_services_config(): void
    {
        config(['services.contashare.base_url' => 'http://localhost:8000/']);

        $url = Socialite::driver('contashare')->stateless()->redirect()->getTargetUrl();

        $this->assertStringStartsWith('http://localhost:8000/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=client-123', $url);
    }
}
