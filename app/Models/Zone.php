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

    /**
     * Look zones up by short_name, grouped by short_name and earliest version
     * first, for captioning things that only stored the name -- the item era
     * index, for one.
     *
     * Not era-gated, unlike liveQuery(): the callers describe where an item
     * comes from, which does not change with the era the site is presenting.
     * A short_name repeats once per version of the zone and each version is its
     * own page, so this cannot hand back a single row; forEra() picks.
     */
    public static function byShortNames(array $shortNames): Collection
    {
        if (!$shortNames) {
            return collect();
        }

        return self::whereIn('short_name', $shortNames)
            ->orderBy('version')
            ->get(['id', 'short_name', 'long_name', 'version', 'expansion'])
            ->groupBy('short_name');
    }

    /**
     * Which version of a zone an item's era means: the one whose own expansion
     * matches it, and otherwise the earliest.
     *
     * `paw` is the Classic Lair of the Splitpaw at version 0 and the Dragons of
     * Norrath rework at version 1, and they are separate pages with separate
     * NPCs -- so sending a DoN item to the Classic page is simply the wrong
     * zone. Only a handful of zones have more than one version, and an era is
     * usually raised by something other than the zone it names, so the fallback
     * is the ordinary path and the match is the exception.
     *
     * @param Collection $zones as returned by byShortNames()
     */
    public static function forEra(Collection $zones, ?string $shortName, ?int $era): ?self
    {
        $versions = $shortName !== null ? ($zones[$shortName] ?? null) : null;

        if (!$versions || $versions->isEmpty()) {
            return null;
        }

        return $versions->firstWhere('expansion', $era) ?? $versions->first();
    }

    private static function liveQuery()
    {
        return self::where('min_status', 0)
            ->whereNotIn('short_name', config('everquest.ignore_zones', []))
            ->tap(fn ($q) => ContentFilter::applyZone($q))
            ->select('id', 'expansion', 'short_name', 'long_name', 'version', 'zone_exp_multiplier');
    }
}
