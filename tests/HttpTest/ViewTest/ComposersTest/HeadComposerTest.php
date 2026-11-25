<?php

namespace Tests;

use App\Events\RenderingHeader;
use Illuminate\Support\Facades\Event;
use Symfony\Component\DomCrawler\Crawler;

class HeadComposerTest extends TestCase
{
    public function testFaviconAndThemeColor(): void
    {
        $this->get('/')->assertOk()->assertSee(config('options.favicon_url'));
        option(['favicon_url' => '/local-icon.png']);
        $this->get('/')->assertOk()->assertSee(url('/local-icon.png'));
        $crawler = new Crawler($this->get('/')->assertOk()->getContent());
        $this->assertCount(1, $crawler->filter('meta[name="theme-color"]'));

        $httpsIcon = 'https://assets.example.invalid/site-icon.png';
        option(['favicon_url' => $httpsIcon]);
        $crawler = new Crawler($this->get('/')->assertOk()->getContent());
        $this->assertSame($httpsIcon, $crawler->filter('link[rel="shortcut icon"]')->attr('href'));

        option(['favicon_url' => '//assets.example.invalid/site-icon.png']);
        $crawler = new Crawler($this->get('/')->assertOk()->getContent());
        $this->assertSame(url(config('options.favicon_url')), $crawler->filter('link[rel="shortcut icon"]')->attr('href'));
    }

    public function testRetainsSeoTextAndIgnoresLegacyCodeAndCdnOptions(): void
    {
        $siteName = 'Local <b>Name</b> & "friends"';
        option([
            'site_name' => $siteName,
            'meta_keywords' => 'topic & second',
            'meta_description' => 'A <b>local description</b>',
            'meta_extras' => '<meta name="legacy-meta">',
            'custom_css' => 'unused legacy CSS marker',
            'custom_js' => 'unused legacy JS marker',
            'cdn_address' => 'https://assets.example.invalid',
        ]);
        $response = $this->get('/')->assertOk()
            ->assertDontSee('legacy-meta')->assertDontSee('unused legacy CSS marker')
            ->assertDontSee('unused legacy JS marker')->assertDontSee('assets.example.invalid');
        $crawler = new Crawler($response->getContent());
        $this->assertSame('topic & second', $crawler->filter('meta[name=keywords]')->attr('content'));
        $this->assertSame('A <b>local description</b>', $crawler->filter('meta[name=description]')->attr('content'));
        $config = $crawler->filter('script#blessing-config[type="application/json"]');
        $this->assertCount(1, $config);
        $this->assertStringNotContainsString('<b>', $config->text());
        $this->assertSame($siteName, json_decode($config->text(), true, flags: JSON_THROW_ON_ERROR)['site_name']);
    }

    public function testRetainsTrustedHeaderHook(): void
    {
        Event::listen(RenderingHeader::class, function (RenderingHeader $event) {
            $event->contents[] = '<meta name=appended>';
        });

        $this->get('/')->assertOk()->assertSee('<meta name=appended>', false);
    }
}
