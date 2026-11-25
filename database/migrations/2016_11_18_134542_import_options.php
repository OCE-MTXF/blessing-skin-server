<?php

use App\Services\Facades\Option;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ImportOptions extends Migration
{
    public function up()
    {
        // import options
        $options = config('options');

        // Lifecycle records the installed version only after migration and schema checks.
        unset($options['version']);

        $options['announcement'] = str_replace(
            '{version}',
            config('app.version'),
            $options['announcement']
        );

        $options['copyright_text'] = str_replace(
            '{year}',
            Carbon\Carbon::now()->year,
            $options['copyright_text']
        );

        foreach ($options as $key => $value) {
            Option::set($key, $value);
        }
    }

    public function down()
    {
        DB::table('options')->delete();
    }
}
