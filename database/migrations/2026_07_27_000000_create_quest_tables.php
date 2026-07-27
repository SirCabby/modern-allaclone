<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quest scripts live on disk as Perl/Lua, not in the game database, so there is
 * nothing in peq to join against. `php artisan quests:index` walks the server's
 * quests/ tree and materialises the cross-references here, in the app's own
 * sqlite database. Nothing in peq is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('zone', 64)->index();        // quests/<zone>/ ; 'global' for the shared tree
            $table->string('file_name', 191);
            $table->string('relative_path', 255)->unique();
            $table->string('language', 8);              // lua | pl
            $table->string('npc_name', 191)->nullable()->index();
            $table->unsignedInteger('npc_id')->nullable()->index();
            $table->boolean('npc_ambiguous')->default(false); // name matched >1 npc_types row
            $table->unsignedInteger('bytes');
            $table->string('sha1', 40);
            $table->timestamps();
        });

        // Items a script hands in, summons, or rewards.
        Schema::create('quest_script_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_script_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('item_id')->index();
            $table->string('kind', 16);                 // handin | reward | mentioned
            $table->unique(['quest_script_id', 'item_id', 'kind']);
        });

        // Other NPCs a script spawns or references.
        Schema::create('quest_script_npcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_script_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('npc_id')->index();
            $table->unique(['quest_script_id', 'npc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_script_npcs');
        Schema::dropIfExists('quest_script_items');
        Schema::dropIfExists('quest_scripts');
    }
};
