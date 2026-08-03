<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The era index already works out *where* an item first becomes obtainable --
 * it has to, since the zone is what carries the expansion -- and then throws
 * that zone away and keeps only the era. Keeping it costs one column and gives
 * the results table somewhere to show what drops or quests an item.
 *
 * Nullable because not every source is a place: a crafted item's era comes from
 * its components and the LDoN flag comes off the item row itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_expansions', function (Blueprint $table) {
            $table->string('zone', 32)->nullable()->after('source'); // peq.zone.short_name
        });
    }

    public function down(): void
    {
        Schema::table('item_expansions', function (Blueprint $table) {
            $table->dropColumn('zone');
        });
    }
};
