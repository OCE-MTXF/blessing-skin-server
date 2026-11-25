<?php

namespace Tests;

use App\Services\Option;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class OptionTest extends TestCase
{
    use DatabaseTransactions;

    public function testGet()
    {
        $options = resolve(Option::class);
        $options->set('k1', '(null)');
        $this->assertNull($options->get('k1'));
    }

    public function testSet()
    {
        $options = resolve(Option::class);
        $options->set([
            'k1' => 'v1',
            'k2' => 'v2',
        ]);
        $this->assertEquals('v1', $options->get('k1'));
        $this->assertEquals('v2', $options->get('k2'));
    }

    public function testMissingOptionReadNeverExecutesAFileOrWritesTheDefault(): void
    {
        file_put_contents(storage_path('options.php'), '<?php throw new \\RuntimeException("must not execute");');
        $options = resolve(Option::class);
        $this->assertSame('fallback', $options->get('testing-key', 'fallback'));
        $this->assertDatabaseMissing('options', ['option_name' => 'testing-key']);
    }
}
