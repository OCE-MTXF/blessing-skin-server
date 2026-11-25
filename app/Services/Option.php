<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Option
{
    public function get($key, $default = null, $raw = false)
    {
        // Configuration defaults do not persist, and writable storage is never executed as PHP.
        $value = DB::table('options')->useWritePdo()->where('option_name', $key)->value('option_value')
            ?? config('options.'.$key, $default);
        if ($raw || !is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value,
        };
    }

    public function set($key, $value = null): void
    {
        if (is_array($key)) {
            DB::transaction(function () use ($key) {
                foreach ($key as $k => $v) {
                    $this->set($k, $v);
                }
            });

            return;
        }

        $value = match ($value) {
            true => 'true',
            false => 'false',
            null => 'null',
            default => (string) $value,
        };
        DB::table('options')->updateOrInsert(['option_name' => $key], ['option_value' => $value]);
    }

    public function all(): array
    {
        return array_replace(config('options'), DB::table('options')->useWritePdo()->pluck('option_value', 'option_name')->all());
    }
}
