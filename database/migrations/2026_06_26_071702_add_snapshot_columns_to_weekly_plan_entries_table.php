<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('weekly_plan_entries', function (Blueprint $table) {
            $table->string('dish_snapshot_name')->nullable()->after('is_leftovers');
            $table->text('dish_snapshot_note')->nullable()->after('dish_snapshot_name');
            $table->json('dish_snapshot_ingredients')->nullable()->after('dish_snapshot_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_plan_entries', function (Blueprint $table) {
            $table->dropColumn([
                'dish_snapshot_name',
                'dish_snapshot_note',
                'dish_snapshot_ingredients',
            ]);
        });
    }
};
