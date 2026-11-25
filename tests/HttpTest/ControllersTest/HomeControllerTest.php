<?php

namespace Tests;

use App\Events\RenderingFooter;
use App\Events\RenderingHeader;
use Illuminate\Support\Facades\Event;
use Symfony\Component\DomCrawler\Crawler;

class HomeControllerTest extends TestCase
{
    public function testIndex()
    {
        $this->get('/')
            ->assertSee(option_localized('site_name'))
            ->assertSee(option_localized('site_description'))
            ->assertViewHas('home_pic_url', option('home_pic_url'));
    }

    public function testBackgroundKeepsAValidImageUrlInBothCssContexts(): void
    {
        $cases = [
            ['images/o\'brien.webp?size=2&crop=1', false],
            ['https://assets.example.invalid/background.webp?size=2&crop=1', true],
        ];
        foreach ($cases as [$url, $fixed]) {
            option(['home_pic_url' => $url, 'fixed_bg' => $fixed]);
            $response = $this->get('/')->assertOk()->assertViewHas('home_pic_url', $url);
            $crawler = new Crawler($response->getContent());
            $this->assertSame($url, $crawler->filter('link[as="image"]')->attr('href'));

            $css = $fixed ? $crawler->filter('#fixed-bg')->attr('style') : $crawler->filter('head style')->text(null, false);
            $this->assertSame(1, preg_match("~background-image: url\\('([^']*)'\\)~", $css, $matches));
            // CSS consumes a hexadecimal escape and its separating whitespace as a code point.
            $this->assertSame($url, preg_replace_callback('/\\\\([0-9A-F]{1,6}) /i', fn ($match) => mb_chr(hexdec($match[1])), $matches[1]));
            $this->assertStringNotContainsString('&amp;', $css);
        }
    }

    public function testNonHttpsExternalBackgroundFallsBackToDefault(): void
    {
        option(['home_pic_url' => 'http://assets.example.invalid/background.webp']);
        $this->get('/')->assertOk()->assertViewHas('home_pic_url', config('options.home_pic_url'))
            ->assertDontSee('assets.example.invalid');
    }

    public function testRenderingHeaderEvent()
    {
        Event::listen(RenderingHeader::class, function (RenderingHeader $event) {
            $event->addContent('testing custom header');
        });
        $this->get('/')->assertSee('testing custom header');
    }

    public function testRenderingFooterEvent()
    {
        Event::listen(RenderingFooter::class, function (RenderingFooter $event) {
            $event->addContent('testing custom footer');
        });
        $this->get('/')->assertSee('testing custom footer');
    }

    public function testApiRoot()
    {
        $this->get('/api')->assertJson([
            'blessing_skin' => config('app.version'),
            'spec' => 0,
            'copyright' => 'Powered with ❤ by Blessing Skin Server.',
            'site_name' => option('site_name'),
        ]);
    }
}
