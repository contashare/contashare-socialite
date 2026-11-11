# Sign in with ContaShare for Laravel

A [Laravel Socialite](https://laravel.com/docs/socialite) driver for [ContaShare](https://contashare.com), and a checker for
ContaShare webhook signatures.

Your customers keep their contact details in ContaShare and choose which ones your app can read. When they change one, you
get a webhook and fetch the new version.

## Install

```bash
composer require contashare/socialite
```

Create an OAuth client under **API Clients** in your ContaShare company account, then add it to `config/services.php`:

```php
'contashare' => [
    'client_id' => env('CONTASHARE_CLIENT_ID'),
    'client_secret' => env('CONTASHARE_CLIENT_SECRET'),
    'redirect' => env('CONTASHARE_REDIRECT_URI'),
    // 'base_url' => 'http://localhost:8000', // only to point at another ContaShare
],
```

## Sign up and sign in

```php
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

Route::get('/auth/contashare', fn () => Socialite::driver('contashare')->redirect());

Route::get('/auth/contashare/callback', function () {
    $contashare = Socialite::driver('contashare');
    $customer = $contashare->user(); // $customer->getId() is how your company knows this person

    $user = User::firstWhere('contashare_id', $customer->getId());

    if ($user === null) {
        // New customer: one call (1 credit) returns everything they shared.
        $shared = $contashare->sharedData($customer->token) ?? [];
        $email = collect($shared['emails'] ?? [])->sortByDesc('email_detail.is_verified')->first();
        $name = $shared['names'][0]['name_detail'] ?? null;

        abort_if($email === null, 422, 'Share an email address to sign up.');

        // Never sign someone in to, or link, an account that already has this email address.
        abort_if(User::where('email', $email['email_detail']['email_address'])->exists(), 409, 'Log in with your password instead.');

        $user = User::create([
            'contashare_id' => $customer->getId(),
            'name' => trim(($name['first_name'] ?? '').' '.($name['last_name'] ?? '')) ?: Str::before($email['email_detail']['email_address'], '@'),
            'email' => $email['email_detail']['email_address'],
            'password' => Str::random(40),
        ]);
    }

    // Keep both tokens: you need them to fetch updates when a webhook arrives.
    $user->forceFill([
        'contashare_token' => $customer->token,
        'contashare_refresh_token' => $customer->refreshToken,
    ])->save();

    Auth::login($user);
    request()->session()->regenerate();

    return redirect('/dashboard');
});
```

- `getId()` comes from `GET /api/v1/me`, which is free. It never changes, it is the same for every OAuth client your team owns,
  and another company gets a different one for the same person.
- `sharedData()` returns `null` when there is nothing new since you last called it. Use the list endpoints, such as
  `GET /api/v1/email`, after that.
- `Socialite::driver('contashare')->reconfirm()->redirect()` shows the consent screen again, so the customer can share
  something new.
- Tokens last a year. Refresh them well before then with `Socialite::driver('contashare')->refreshToken($refreshToken)`, and
  store the new pair straight away.

## Webhooks

ContaShare signs every webhook with a `Signature` header. Check it against the **raw** request body:

```php
use ContaShare\Socialite\WebhookSignature;

Route::post('/webhooks/contashare', function (Illuminate\Http\Request $request) {
    abort_unless(WebhookSignature::isValid($request->getContent(), $request->header('Signature'), config('services.contashare.webhook_secret')), 401);

    // Queue a job that fetches $request->input('resource_url') with that customer's token.

    return response()->noContent();
})->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
```

The [OAuth guide](https://contashare.com/help/oauth) and [webhooks guide](https://contashare.com/help/webhooks) cover the rest,
and every endpoint is in the [API reference](https://contashare.com/docs/api).

## Testing

```bash
composer test
```

## Licence

MIT. See [LICENSE](LICENSE).
