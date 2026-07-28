<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other half of the script <-> peq bridge. Tasks live in peq but are driven
 * entirely from the quests/ tree -- assigntask(), taskselector(),
 * updatetaskactivity() -- so neither side can find the other without an index.
 * `php artisan quests:index` fills this in alongside quest_script_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_script_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_script_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('task_id')->index();
            $table->string('kind', 16);                 // offer | update | mentioned
            $table->unique(['quest_script_id', 'task_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_script_tasks');
    }
};
