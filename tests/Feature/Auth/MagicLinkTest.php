<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private function linkFor(string $email): string
    {
        Mail::fake();
        $this->post(route('login.link.send'), ['email' => $email])->assertSessionHasNoErrors();
        $url = null;
        Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $m) use (&$url) {
            $url = $m->url;

            return true;
        });

        return $url;
    }

    public function test_the_login_page_leads_with_the_emailed_link(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('Email me a login link')
            ->assertSee('Use your password instead');
    }

    public function test_opening_the_link_shows_a_button_and_only_pressing_it_signs_in(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com']);
        $url = $this->linkFor('pat@example.com');

        // A mail scanner opens the link: nothing happens.
        $this->get($url)->assertOk()->assertSee('You are logging in as pat@example.com.')->assertSee('Log in');
        $this->assertGuest();

        $this->post($url)->assertRedirect(route('watch-areas.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_link_works_once(): void
    {
        User::factory()->create(['email' => 'pat@example.com']);
        $url = $this->linkFor('pat@example.com');
        $this->post($url);
        $this->post(route('logout'));

        $this->get($url)->assertOk()->assertSee('This login link has expired');
        $this->post($url)->assertOk()->assertSee('This login link has expired');
        $this->assertGuest();
    }

    public function test_a_tampered_or_expired_link_signs_no_one_in(): void
    {
        User::factory()->create(['email' => 'pat@example.com']);
        $url = $this->linkFor('pat@example.com');

        $this->post(str_replace('signature=', 'signature=0', $url))->assertSee('This login link has expired');
        $this->travel(31)->minutes();
        $this->post($url)->assertSee('This login link has expired');
        $this->assertGuest();
    }

    public function test_the_reply_is_the_same_for_unknown_addresses_and_bots_get_nothing(): void
    {
        Mail::fake();
        $this->post(route('login.link.send'), ['email' => 'nobody@example.com'])->assertSessionHas('link_sent', 'nobody@example.com');
        $this->post(route('login.link.send'), ['email' => 'bot@example.com', 'website' => 'x'])->assertSessionHas('link_sent', 'bot@example.com');
        Mail::assertSent(LoginLinkMail::class, 1);
        $this->post(route('login.link.send'), ['email' => 'not an email'])->assertSessionHasErrors('email', null, 'link');
    }

    public function test_links_are_rate_limited_per_address(): void
    {
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.link.send'), ['email' => 'pat@example.com'])->assertSessionHasNoErrors();
        }
        $this->post(route('login.link.send'), ['email' => 'pat@example.com'])->assertSessionHasErrors('email', null, 'link');
        Mail::assertSent(LoginLinkMail::class, 5);
    }

    public function test_two_factor_still_applies_to_link_sign_in(): void
    {
        User::factory()->withTwoFactor()->create(['email' => 'pat@example.com']);
        $url = $this->linkFor('pat@example.com');

        $this->post($url)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_existing_password_logins_keep_working(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_login_fails_for_an_account_without_a_password(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'anything'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guests_sent_to_log_in_see_why(): void
    {
        $this->get('/watch-areas')->assertRedirect(route('login'));
        $this->get(route('login'))->assertSee('Log in to see your watch area.');
    }

    public function test_an_unsigned_link_url_is_rejected(): void
    {
        $this->get(URL::route('login.link', ['token' => 'x']))->assertSee('This login link has expired');
    }
}
