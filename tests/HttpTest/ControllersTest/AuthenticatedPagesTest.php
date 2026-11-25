<?php

namespace Tests;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthenticatedPagesTest extends TestCase
{
    #[DataProvider('pages')]
    public function testRendersInstalledSite(string $path, bool $superAdmin): void
    {
        $account = User::factory();
        $this->actingAs($superAdmin ? $account->superAdmin()->create() : $account->create());
        $this->withoutExceptionHandling();

        $this->get($path)->assertOk();
    }

    public static function pages(): array
    {
        return [
            'user dashboard' => ['/user', false],
            'profile' => ['/user/profile', false],
            'players' => ['/user/player', false],
            'closet' => ['/user/closet', false],
            'upload' => ['/skinlib/upload', false],
            'admin dashboard' => ['/admin', true],
            'admin options' => ['/admin/options', true],
        ];
    }
}
