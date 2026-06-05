<?php

namespace App\Console\Commands;

use App\Models\Jasa;
use Illuminate\Console\Command;

class GenerateJasaSlugs extends Command
{
    protected $signature = 'jasa:generate-slugs';
    protected $description = 'Generate slugs for all jasa records that dont have one';

    public function handle()
    {
        $jasas = Jasa::whereNull('slug')->get();
        
        if ($jasas->isEmpty()) {
            $this->info('All jasa records already have slugs.');
            return;
        }

        $count = 0;
        foreach ($jasas as $jasa) {
            $jasa->slug = Jasa::generateUniqueSlug($jasa->title);
            $jasa->save();
            $this->line("✓ {$jasa->title} -> {$jasa->slug}");
            $count++;
        }

        $this->info("Generated {$count} slugs successfully!");
    }
}
