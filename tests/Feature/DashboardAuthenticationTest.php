<?php

namespace Tests\Feature;

use App\Http\Middleware\DashboardAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mock.dashboard_auth', [
            'enabled' => true,
            'username' => 'operator',
            'password' => 'correct-horse-battery-staple',
        ]);
    }

    public function test_guest_is_redirected_to_login_and_intended_url_is_preserved(): void
    {
        $this->get('/dashboard/endpoints/create')
            ->assertRedirect('/dashboard/login');

        $this->post('/dashboard/login', [
            'username' => 'operator',
            'password' => 'correct-horse-battery-staple',
        ])->assertRedirect('/dashboard/endpoints/create');
    }

    public function test_guest_cannot_access_config_transfer_routes(): void
    {
        $this->get('/dashboard/config')->assertRedirect('/dashboard/login');
        $this->post('/dashboard/config/exports')->assertRedirect('/dashboard/login');
        $this->post('/dashboard/config/imports/preview')->assertRedirect('/dashboard/login');
        $this->post('/dashboard/config/imports/apply')->assertRedirect('/dashboard/login');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->from('/dashboard/login')->post('/dashboard/login', [
            'username' => 'operator',
            'password' => 'wrong-password',
        ])->assertRedirect('/dashboard/login')->assertSessionHasErrors('username');

        self::assertFalse((bool) session(DashboardAccess::SESSION_KEY, false));
    }

    public function test_authenticated_operator_can_sign_out(): void
    {
        $this->withSession([DashboardAccess::SESSION_KEY => true])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Sign out');

        $this->post('/dashboard/logout')->assertRedirect('/dashboard/login');
        self::assertFalse((bool) session(DashboardAccess::SESSION_KEY, false));
    }
}
