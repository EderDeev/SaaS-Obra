<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'Senha1!',
                'password_confirmation' => 'Senha1!',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('Senha1!', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'Senha1!',
                'password_confirmation' => 'Senha1!',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/profile');
    }

    public function test_user_with_temporary_password_must_change_it_before_dashboard(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'temporary_password_created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('password.force.edit'));
    }

    public function test_user_can_replace_temporary_password(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'temporary_password_created_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->put(route('password.force.update'), [
                'password' => 'Senha1!',
                'password_confirmation' => 'Senha1!',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard');

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_created_at);
        $this->assertTrue(Hash::check('Senha1!', $user->password));
    }
}
