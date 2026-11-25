<?php

namespace App\Providers;

use App\Http\View\Composers;
use App\Services\SafeMarkdown;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer([
            'home',
            '*.base',
            '*.master',
            'shared.header',
        ], function ($view) {
            $lightColors = ['light', 'warning', 'white', 'orange'];
            $color = option('navbar_color');
            $view->with([
                'site_name' => option_localized('site_name'),
                'navbar_color' => $color,
                'color_mode' => in_array($color, $lightColors) ? 'light' : 'dark',
                'dark_mode' => (bool) optional(auth()->user())->is_dark_mode,
                'locale' => str_replace('_', '-', app()->getLocale()),
            ]);
        });

        View::composer('shared.head', Composers\HeadComposer::class);

        View::composer('shared.notifications', function ($view) {
            $notifications = auth()->user()->unreadNotifications()->limit(50)->get()->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->data['title'],
                ];
            });
            $view->with(['notifications' => $notifications]);
        });

        View::composer(
            ['shared.languages', 'errors.*'],
            Composers\LanguagesMenuComposer::class
        );

        View::composer('shared.user-menu', Composers\UserMenuComposer::class);

        View::composer('shared.sidebar', function ($view) {
            $isDarkMode = (bool) optional(auth()->user())->is_dark_mode;
            $color = option('sidebar_color');
            $color = $isDarkMode ? str_replace('light', 'dark', $color) : $color;

            $view->with('sidebar_color', $color);
        });

        View::composer('shared.side-menu', Composers\SideMenuComposer::class);

        View::composer('shared.user-panel', Composers\UserPanelComposer::class);

        View::composer('shared.copyright', function ($view) {
            $text = option_localized('copyright_text');
            $markdown = is_string($text) ? strtr($text, [
                '{year}' => date('Y'),
                '{site_name}' => (string) option_localized('site_name'),
                '{site_url}' => url('/'),
            ]) : '';
            $view->with([
                'copyright' => option_localized('copyright_prefer', 0),
                'custom_copyright_html' => app(SafeMarkdown::class)->render($markdown),
            ]);
        });

        View::composer('shared.foot', Composers\FootComposer::class);

        View::composer('shared.dark-mode', function ($view) {
            $view->with([
                'dark_mode' => (bool) optional(auth()->user())->is_dark_mode,
            ]);
        });

    }
}
