<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

/** W1: an account made from an emailed link has no password, and settings still work for it. */
class PasswordlessSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
        $this->user = User::factory()->withoutPassword()->create(['email' => 'pat@example.com']);
        $this->actingAs($this->user);
    }

    public function test_security_opens_without_a_password_prompt_and_offers_an_optional_password(): void
    {
        $this->get(route('security.edit'))->assertOk()
            ->assertSee('Set a password (optional)')
            ->assertDontSee('Current password')
            ->assertSee('Set a password to turn on two-factor authentication.')
            ->assertDontSee('Enable 2FA');
    }

    public function test_a_password_can_be_set_and_then_is_asked_for(): void
    {
        Livewire::test('pages::settings.security')
            ->set('password', 'a-long-new-password')->set('password_confirmation', 'a-long-new-password')
            ->call('updatePassword')
            ->assertHasNoErrors()->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('a-long-new-password', $this->user->fresh()->password));
        // From now on the page wants the password confirmed, as for any password account.
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    }

    public function test_two_factor_cannot_be_started_without_a_password(): void
    {
        Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->call('startTwoFactorSetup')->assertForbidden();
        $this->assertNull($this->user->fresh()->two_factor_secret);
    }

    public function test_the_account_is_deleted_by_typing_its_email(): void
    {
        $this->user->watchAreas()->create(['name' => 'Kal east', 'latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5, 'event_types' => ['death']]);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'someone@example.com')->call('deleteUser')
            ->assertHasErrors('password');
        $this->assertNotNull($this->user->fresh());

        Livewire::test('pages::settings.delete-user-modal')->set('password', ' Pat@Example.com ')->call('deleteUser')
            ->assertHasNoErrors()->assertRedirect('/');
        $this->assertNull($this->user->fresh());
        $this->assertDatabaseCount('watch_areas', 0);
        $this->assertGuest();
    }

    public function test_the_profile_page_opens(): void
    {
        $this->get(route('profile.edit'))->assertOk()->assertSee('Delete account');
    }
}
