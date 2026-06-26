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
            $table->foreignId('dish_id')->nullable()->change();
            $table->string('special_entry', 30)->nullable()->after('dish_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_plan_entries', function (Blueprint $table) {
            $table->dropColumn('special_entry');
            $table->foreignId('dish_id')->nullable(false)->change();
        });
    }
};
