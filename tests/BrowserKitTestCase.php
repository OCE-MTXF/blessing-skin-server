<?php

namespace Tests;

require_once __DIR__.'/CreatesIsolatedApplication.php';

class BrowserKitTestCase extends \Laravel\BrowserKitTesting\TestCase
{
    use CreatesIsolatedApplication;

    protected $baseUrl = 'http://localhost';
}
