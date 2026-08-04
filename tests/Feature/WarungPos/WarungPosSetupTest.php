<?php

namespace Tests\Feature\WarungPos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WarungPosSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_install_redirects_login_to_setup(): void
    {
        $this->get(route('login'))->assertRedirect(route('setup'));
    }

    public function test_setup_creates_first_owner_and_logs_in(): void
    {
        $this->post(route('setup.store'), [
            'store_name' => 'Warung Production',
            'store_phone' => '08123456789',
            'store_address' => 'Jakarta',
            'name' => 'Owner Production',
            'username' => 'ownerprod',
            'email' => 'owner@example.com',
            'password' => 'password-kuat',
            'password_confirmation' => 'password-kuat',
            'pin' => '123456',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('stores', ['name' => 'Warung Production']);
        $this->assertDatabaseHas('roles', ['slug' => 'owner']);
        $this->assertDatabaseHas('payment_methods', ['code' => 'cash']);

        $user = User::where('username', 'ownerprod')->firstOrFail();
        $this->assertTrue(Hash::check('password-kuat', $user->password));
        $this->assertSame('owner', DB::table('roles')->join('user_roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $user->id)->value('roles.slug'));
    }

    public function test_setup_is_closed_after_first_user_exists(): void
    {
        User::factory()->create();

        $this->get(route('setup'))->assertRedirect(route('login'));
    }
}
