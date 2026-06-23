<?php

namespace Database\Seeders;

use App\Models\FamilyInvitation;
use Illuminate\Database\Seeder;

class FamilyInvitationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        FamilyInvitation::factory()->create();
    }
}
