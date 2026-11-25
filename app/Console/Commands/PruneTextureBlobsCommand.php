<?php

namespace App\Console\Commands;

use App\Services\Textures\TextureBlobs;
use App\Services\Textures\TexturePreflight;
use Illuminate\Console\Command;

final class PruneTextureBlobsCommand extends Command
{
    protected $signature = 'textures:prune
        {--preflight : Count legacy compatibility and reference issues without changing data}
        {--commit : Delete registered blobs unreferenced for at least seven days}';
    protected $description = 'Check legacy textures or review and reclaim unreferenced blobs';

    public function handle(TextureBlobs $blobs, TexturePreflight $preflight): int
    {
        if ($this->option('preflight')) {
            if ($this->option('commit')) {
                $this->error('The read-only --preflight cannot be combined with --commit.');

                return self::INVALID;
            }
            foreach ($preflight->counts() as $category => $count) {
                $this->line("$category: $count");
            }

            return self::SUCCESS;
        }
        $count = $blobs->prune(500, (bool) $this->option('commit'));
        $this->info($this->option('commit') ? "Reclaimed $count registered blobs." : "$count registered blobs eligible for reclamation (dry run).");

        return self::SUCCESS;
    }
}
