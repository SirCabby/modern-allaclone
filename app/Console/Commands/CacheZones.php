<?php

namespace App\Console\Commands;

use App\Models\Zone;
use App\ViewModels\ZoneViewModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Support\ContentFilter;

class CacheZones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cache-zones';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache all the zones @show logic';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $currentExpansion = ContentFilter::currentExpansion();
        $zones = Zone::getExpansionZones()->flatten(1);

        $this->info("Starting zone cache warming for {$zones->count()} zones...");

        foreach ($zones as $zone) {
            $version = (int) $zone->version;
            // Key must match ZoneController::show(), era suffix included.
            $cacheKey = "zones.show.{$zone->id}_v{$version}_e{$currentExpansion}";

            // forget any previous cache we may have
            Cache::forget($cacheKey);

            // cache forever since this data rarely changes. Built through the
            // same payload the controller uses, so warming cannot write a
            // different page than the request would have.
            Cache::rememberForever($cacheKey, fn () => ZoneViewModel::payload($zone->id));

            $this->line("Cached: {$cacheKey} -- {$zone->short_name} / {$zone->id}-{$zone->version}");
        }

        $this->info('All zones cached successfully.');
        return Command::SUCCESS;
    }
}
