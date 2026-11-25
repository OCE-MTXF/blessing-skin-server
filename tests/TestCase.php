<?php

namespace Tests;

require_once __DIR__.'/CreatesIsolatedApplication.php';

class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use CreatesIsolatedApplication;

    protected $baseUrl = 'http://localhost';

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy(\App\Services\Translations\JavaScript::class);
    }
}
