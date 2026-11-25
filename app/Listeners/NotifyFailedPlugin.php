<?php

namespace App\Listeners;

use App\Events\RenderingFooter;
use Illuminate\Support\Facades\Event;

class NotifyFailedPlugin
{
    public function handle($event): void
    {
        $plugin = $event->plugin;
        Event::listen(RenderingFooter::class, function (RenderingFooter $event) use ($plugin) {
            if (auth()->user()?->isAdmin()) {
                $message = trans('errors.plugins.boot', ['plugin' => $plugin->displayTitle()]);
                $event->addContent('<div class="alert alert-danger" role="alert">'.e($message).'</div>');
            }
        });
    }
}
