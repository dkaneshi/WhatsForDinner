<?php

use App\Actions\WeeklyPlans\ResolveWeeklyPlanWeek;
use App\Models\WeeklyPlan;
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
        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->boolean('includes_weekend')->default(true)->after('week_start_date');
        });

        $resolver = app(ResolveWeeklyPlanWeek::class);

        WeeklyPlan::query()
            ->with('family')
            ->chunkById(100, function ($plans) use ($resolver): void {
                foreach ($plans as $plan) {
                    $plan->forceFill([
                        'includes_weekend' => ! $resolver->isPastWeek($plan->family, $plan->week_start_date),
                    ])->saveQuietly();
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->dropColumn('includes_weekend');
        });
    }
};
