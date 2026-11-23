<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_asks_for_an_email_only(): void
    {
        $this->get(route('register'))->assertOk()
            ->assertSee('Create a free account')
            ->assertDontSee('name="password"', false);
    }

    public function test_new_users_register_by_emailed_link_without_a_password(): void
    {
        Mail::fake();

        $this->post(route('login.link.send'), ['email' => ' New@Example.com '])->assertSessionHas('link_sent', 'new@example.com');
        $this->assertDatabaseCount('users', 0);

        $url = null;
        Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $m) use (&$url) {
            $url = $m->url;

            return $m->hasTo('new@example.com');
        });
        $this->post($url)->assertRedirect(route('watch-areas.index'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('new', $user->name);
        $this->assertFalse($user->hasPassword());
        $this->assertTrue($user->hasVerifiedEmail());
    }
}
