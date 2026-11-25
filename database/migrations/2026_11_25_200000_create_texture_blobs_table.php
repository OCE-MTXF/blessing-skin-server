<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('texture_blobs', function (Blueprint $table) {
            $table->string('hash', 64)->primary();
            $table->timestamp('unreferenced_at')->nullable()->index();
        });
        Schema::table('textures', function (Blueprint $table) {
            $table->index(['hash', 'public'], 'textures_hash_public_index');
        });
    }

    public function down(): void
    {
        Schema::table('textures', function (Blueprint $table) {
            $table->dropIndex('textures_hash_public_index');
        });
        Schema::dropIfExists('texture_blobs');
    }
};
