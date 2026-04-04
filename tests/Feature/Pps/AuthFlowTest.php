<?php

namespace Tests\Feature\Pps;

use App\Models\User;
use App\Support\PpsPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_fetch_profile_and_log_out_with_sanctum(): void
    {
        $user = User::query()->create([
            'name' => 'Principal User',
            'email' => 'principal@example.test',
            'role' => 'principal',
            'password' => Hash::make('secret-password'),
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'principal@example.test',
            'password' => 'secret-password',
        ]);

        $loginResponse
            ->assertCreated()
            ->assertJsonPath('user.role', 'principal')
            ->assertJsonPath('user.home_path', '/pps');

        $token = $loginResponse->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'principal@example.test')
            ->assertJsonPath('user.role_label', 'Principal');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertSame($user->permissions(), $loginResponse->json('user.permissions'));
    }

    public function test_teacher_and_counselor_receive_accessible_home_paths(): void
    {
        $teacher = User::query()->create([
            'name' => 'Teacher User',
            'email' => 'teacher@example.test',
            'role' => 'teacher',
            'password' => Hash::make('secret-password'),
        ]);

        $counselor = User::query()->create([
            'name' => 'Counselor User',
            'email' => 'counselor@example.test',
            'role' => 'counselor',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'teacher@example.test',
            'password' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonPath('user.home_path', '/pps/teacher')
            ->assertJsonPath('user.permissions', $teacher->permissions());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'counselor@example.test',
            'password' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonPath('user.home_path', '/pps/students')
            ->assertJsonPath('user.permissions', PpsPermissions::forRole('counselor'));
    }
}
