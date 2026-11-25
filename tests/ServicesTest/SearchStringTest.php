<?php

namespace Tests;

use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SearchStringTest extends TestCase
{
    public function testBoundedSearchPreservesFieldSortAndLiteralText(): void
    {
        $older = User::factory()->create(['nickname' => 'Lab 20% Alice', 'score' => 10]);
        $newer = User::factory()->create(['nickname' => 'Lab 20% Bob', 'score' => 20]);
        User::factory()->create(['nickname' => 'Lab 2000 Charlie', 'score' => 30]);

        self::assertSame([$newer->uid, $older->uid], User::usingSearchString('"Lab 20%" verified:true sort:-score')->pluck('uid')->all());
    }

    public function testUnknownFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        User::usingSearchString('unsupported:normal');
    }
}
