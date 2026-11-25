<?php

namespace App\Services\Translations;

use App\Services\PluginManager;

class JavaScript
{
    public function __construct(private PluginManager $plugins)
    {
    }

    /** Return inert translations for the current page; never write a public script. */
    public function generate(string $locale): array
    {
        $core = trans('front-end', [], $locale);
        $data = is_array($core) ? $core : [];
        foreach ($this->plugins->getEnabledPlugins() as $plugin) {
            $messages = trans($plugin->namespace.'::front-end', [], $locale);
            if (is_array($messages) && !array_key_exists($plugin->name, $data)) {
                $data[$plugin->name] = $messages;
            }
        }

        return $data;
    }
}
