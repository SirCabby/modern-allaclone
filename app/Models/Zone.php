<?php

namespace App\Models;

use App\Support\ContentFilter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Zone extends Model
{
    use HasFactory;

    protected $connection = 'eqemu';
    protected $table = 'zone';

    public function forages(): HasMany
    {
        return $this->hasMany(Forage::class, 'zoneid', 'zoneidnumber');
    }

    public function fished(): HasMany
    {
        return $this->hasMany(Forage::class, 'zoneid', 'zoneidnumber');
    }

    public function spawns(): HasMany
    {
        return $this->hasMany(SpawnTwo::class, 'zone', 'short_name');
    }

    public function zonepoints(): HasMany
    {
        return $this->hasMany(ZonePoint::class, 'zone', 'short_name');
    }

    public function taskActivities(): HasMany
    {
        return $this->hasMany(TaskActivity::class, 'zones', 'zoneidnumber');
    }

    public static function getExpansionZones(): Collection
    {
        return self::liveQuery()
            ->orderBy('expansion', 'asc')
            ->orderBy('long_name', 'asc')
            ->get()
            ->groupBy('expansion');
    }

    /**
     * Zones that exist in the era currently being presented, as one flat
     * alphabetical list. The site shows what the server is actually running, so
     * there is nothing for a visitor to pick between.
     */
    public static function getLiveZones(): Collection
    {
        return self::liveQuery()
            ->orderBy('long_name', 'asc')
            ->get();
    }

    private static function liveQuery()
    {
        return self::where('min_status', 0)
            ->whereNotIn('short_name', config('everquest.ignore_zones', []))
            ->tap(fn ($q) => ContentFilter::applyZone($q))
            ->select('id', 'expansion', 'short_name', 'long_name', 'version', 'zone_exp_multiplier');
    }
}
