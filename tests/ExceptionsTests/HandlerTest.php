<?php

namespace Tests;

use Illuminate\Support\Facades\Route;

class HandlerTest extends TestCase
{
    public function testJsonErrorOmitsExceptionDetailAndIncludesSecurityHeaders(): void
    {
        $this->getJson('/unknown-page')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found'])
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function testInternalErrorDoesNotDependOnRenderingTheSite(): void
    {
        Route::get('/fixture-error', static fn () => throw new \RuntimeException('Internal diagnostic marker'));

        $this->get('/fixture-error')->assertStatus(500)->assertContent('Internal Server Error');
        $this->getJson('/fixture-error')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    }
}
