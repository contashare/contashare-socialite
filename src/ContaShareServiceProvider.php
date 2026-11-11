<?php

namespace ContaShare\Socialite;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\SocialiteManager;

/**
 * Adds the `contashare` Socialite driver, set up from `services.contashare`.
 */
class ContaShareServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Factory::class, function (SocialiteManager $socialite): void {
            $socialite->extend('contashare', function ($app) use ($socialite): ContaShareProvider {
                $config = $app['config']['services.contashare'] ?? [];

                /** @var ContaShareProvider $provider */
                $provider = $socialite->buildProvider(ContaShareProvider::class, $config);

                return $provider->setBaseUrl($config['base_url'] ?? ContaShareProvider::DEFAULT_BASE_URL);
            });
        });
    }
}
