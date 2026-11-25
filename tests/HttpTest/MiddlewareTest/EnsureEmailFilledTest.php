<?php

namespace Tests;

use App\Models\User;

class EnsureEmailFilledTest extends TestCase
{
    public function testHandle()
    {
        $noEmailUser = User::factory()->create(['email' => '']);
        $this->actingAs($noEmailUser)->get('/user')->assertRedirect('/auth/bind');

        $normalUser = User::factory()->create();
        $this->actingAs($normalUser)->get('/auth/bind')->assertRedirect('/user');
    }
}
