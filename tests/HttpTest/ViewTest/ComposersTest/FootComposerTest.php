<?php

namespace Tests;

use App\Events\RenderingFooter;
use App\Models\User;
use App\Services\Translations\JavaScript;
use Illuminate\Support\Facades\Event;
use Symfony\Component\DomCrawler\Crawler;

class FootComposerTest extends TestCase
{
    public function testSerializesTranslationsAsInertDataAndIgnoresLegacyScript(): void
    {
        $messages = ['label' => 'Use <b>plain text</b> & "quotes"'];
        $this->mock(JavaScript::class, function ($mock) use ($messages) {
            $mock->shouldReceive('generate')->with('en')->andReturn($messages);
        });
        option(['custom_js' => 'unused legacy footer marker']);
        $crawler = new Crawler($this->get('/')->assertOk()->assertDontSee('unused legacy footer marker')->getContent());
        $data = $crawler->filter('script#blessing-i18n[type="application/json"]');
        $this->assertCount(1, $data);
        $this->assertStringNotContainsString('<b>', $data->text());
        $this->assertSame($messages, json_decode($data->text(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRetainsTrustedExtensionHook(): void
    {
        Event::listen(RenderingFooter::class, function (RenderingFooter $event) {
            $event->addContent('<div id="appended"></div>');
        });

        $this->actingAs(User::factory()->create()->refresh())
            ->get('/user')->assertOk()->assertSee('<div id="appended"></div>', false);
    }
}
