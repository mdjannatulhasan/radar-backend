<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function signInPps(User $user): static
    {
        Sanctum::actingAs($user, $user->permissions());

        return $this;
    }
}
