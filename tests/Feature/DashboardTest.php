<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/watch-areas')->assertRedirect('/login');
    }

    public function test_dashboard_sends_signed_in_users_to_their_watch_area(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/watch-areas');
        $this->get('/watch-areas')->assertOk();
    }
}
