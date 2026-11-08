<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Widgets\SystemOverview;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use App\Support\PseudoLocale;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Task #95: "switching to a test locale changes every screen". The test locale is the made-up
 * language qps: every text that goes through Laravel's translator (ours, Laravel's, the admin panel's)
 * comes out accented and in ⟦ ⟧, so a text that is still typed into the code stands out. Start the app
 * with APP_LOCALE=qps to look at the screens; here the server side of the switch is tried: messages,
 * emails, the page the screens start from, and the admin panel.
 */
class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_laravel_sends_the_texts_of_the_chosen_language(): void
    {
        $this->assertSame('Save', __('common.save'));

        app()->setLocale('qps');

        $this->assertSame(PseudoLocale::convert('Save'), __('common.save'));
        $this->assertSame(PseudoLocale::convert('Page 2 of 5'), __('notifications.page_of', ['page' => 2, 'total' => 5]));
        $this->assertSame(PseudoLocale::convert('You received 3 points'), trans_choice('notifications.feed.points.title', 3));
        $this->assertSame(PseudoLocale::convert('You received 1 point'), trans_choice('notifications.feed.points.title', 1));
    }

    public function test_a_message_of_the_server_follows_the_language(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['email' => 'Incorrect username or password.']);

        app()->setLocale('qps');

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['email' => PseudoLocale::convert('Incorrect username or password.')]);
    }

    public function test_an_email_follows_the_language(): void
    {
        app()->setLocale('qps');

        $mail = (new PasswordResetNotification('token'))
            ->toMail((object) ['name' => 'Anna']);

        $this->assertSame(PseudoLocale::convert('Set a new password for Campus Engage'), $mail->subject);
        $this->assertSame(str_replace(':name', 'Anna', PseudoLocale::convert('Hello :name!')), $mail->greeting);
    }

    public function test_the_layout_of_an_email_follows_the_language(): void
    {
        $html = (string) (new PasswordResetNotification('token'))->toMail((object) ['name' => 'Anna'])->render();

        $this->assertStringContainsString('Regards,', $html);
        $this->assertStringContainsString('All rights reserved.', $html);

        app()->setLocale('qps');

        $html = (string) (new PasswordResetNotification('token'))->toMail((object) ['name' => 'Anna'])->render();

        // The salutation, the footer and the help under the button are texts of Laravel's own layout.
        $this->assertStringContainsString(PseudoLocale::convert('Regards,'), $html);
        $this->assertStringContainsString(PseudoLocale::convert('All rights reserved.'), $html);
        $this->assertStringContainsString(mb_substr(PseudoLocale::convert('copy and paste the URL below'), 1, -1), $html);
    }

    public function test_a_page_for_an_error_follows_the_language(): void
    {
        $this->get('/there-is-no-such-page')->assertNotFound()->assertSee('Not Found');

        app()->setLocale('qps');

        $this->get('/there-is-no-such-page')->assertNotFound()->assertSee(PseudoLocale::convert('Not Found'), false);
    }

    public function test_the_page_tells_the_screens_which_language_to_use(): void
    {
        $this->get('/login')->assertOk()->assertSee('<html lang="en"', false);

        app()->setLocale('qps');

        $this->get('/login')->assertOk()->assertSee('<html lang="qps"', false);
    }

    public function test_the_admin_panel_follows_the_language(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create()->assignRole(UserRole::Admin);

        app()->setLocale('qps');

        // The sign-in page: the panel's own texts and the name of the app.
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(PseudoLocale::convert('Sign in'), false)
            ->assertSee(PseudoLocale::convert('Campus Engage'), false);

        // A signed-in page: the menu and the dashboard widget are texts of the dictionary.
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee(PseudoLocale::convert('Faculties'), false)
            ->assertSee(PseudoLocale::convert('Area roles'), false);

        Livewire::test(SystemOverview::class)
            ->assertSee(PseudoLocale::convert('System overview'), false)
            ->assertSee(PseudoLocale::convert('Organizers'), false)
            ->assertSee(PseudoLocale::convert('No active semester'), false);
    }
}
