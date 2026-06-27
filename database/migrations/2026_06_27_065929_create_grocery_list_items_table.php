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
        Schema::create('grocery_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grocery_list_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_checked')->default(false);
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_suppressed')->default(false);
            $table->json('source_entry_ids')->nullable();
            $table->json('source_labels')->nullable();
            $table->timestamps();

            $table->unique(['grocery_list_id', 'normalized_name']);
            $table->index(['grocery_list_id', 'is_manual', 'is_suppressed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grocery_list_items');
    }
};
