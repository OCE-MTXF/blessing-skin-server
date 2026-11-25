<?php

namespace Tests;

use App\Events\PluginBootFailed;
use App\Events\RenderingFooter;
use App\Models\User;
use App\Services\Plugin;

class NotifyFailedPluginTest extends TestCase
{
    public function testPluginErrorUsesEscapedAdminOnlyNotice(): void
    {
        $plugin = new Plugin('', ['title' => 'Example <b>&</b>']);
        event(new PluginBootFailed($plugin));

        $content = [];
        event(new RenderingFooter($content));
        $this->assertSame([], $content);

        $this->actingAs(User::factory()->make());
        event(new RenderingFooter($content));
        $this->assertSame([], $content);

        $this->actingAs(User::factory()->admin()->make());
        event(new RenderingFooter($content));
        $this->assertCount(1, $content);
        $this->assertStringContainsString('role="alert"', $content[0]);
        $this->assertStringContainsString('Example &lt;b&gt;&amp;&lt;/b&gt;', $content[0]);
        $this->assertStringNotContainsString('<script', $content[0]);
    }
}
