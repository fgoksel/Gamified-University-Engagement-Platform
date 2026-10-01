<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Checks password hashing, password rules and brute-force protection
 * (Task #16, Technical Specification 8.2).
 */
class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_passwords_are_hashed_with_bcrypt(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->assertSame('bcrypt', Hash::info($user->password)['algoName']);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertNotSame('secret-password', $user->getRawOriginal('password'));
    }

    public function test_bcrypt_cost_is_12_outside_of_tests(): void
    {
        // Tests use a lower cost for speed (phpunit.xml); real environments use .env.
        $this->assertMatchesRegularExpression('/^BCRYPT_ROUNDS=12$/m', file_get_contents(base_path('.env.example')));
        $this->assertSame('bcrypt', config('hashing.driver'));
    }

    public function test_new_passwords_must_have_at_least_8_characters(): void
    {
        $rules = ['password' => ['required', Password::defaults()]];

        $this->assertTrue(Validator::make(['password' => 'short12'], $rules)->fails());
        $this->assertFalse(Validator::make(['password' => 'long1234'], $rules)->fails());
    }

    public function test_login_allows_5_attempts_per_minute_per_ip(): void
    {
        Route::post('/_test/login', fn () => 'ok')->middleware('throttle:login');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/_test/login')->assertOk();
        }

        $this->post('/_test/login')->assertTooManyRequests();

        // Another account on the same IP (shared campus Wi-Fi) is not blocked.
        $this->post('/_test/login', ['email' => 'other@pte.hu'])->assertOk();

        // Another IP address is not blocked.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->post('/_test/login')->assertOk();
    }

    public function test_qr_redeem_allows_60_requests_per_minute_per_ip(): void
    {
        Route::post('/_test/qr-redeem', fn () => 'ok')->middleware('throttle:qr-redeem');

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->post('/_test/qr-redeem')->assertOk();
        }

        $this->post('/_test/qr-redeem')->assertTooManyRequests();
    }

    public function test_admin_panel_login_is_limited_to_5_attempts(): void
    {
        RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|127.0.0.1'));

        User::factory()->create(['email' => 'admin@pte.hu', 'password' => 'correct-password']);

        $login = Livewire::test(Login::class);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $login->fillForm(['email' => 'admin@pte.hu', 'password' => 'wrong-password'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        // The 6th attempt is refused before the password is even checked.
        $login->fillForm(['email' => 'admin@pte.hu', 'password' => 'correct-password'])
            ->call('authenticate')
            ->assertNotified();

        $this->assertGuest();
    }
}
