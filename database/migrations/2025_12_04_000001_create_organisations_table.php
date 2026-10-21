<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-Registration — organisations, backfilled from the existing distinct
 * (case/whitespace-insensitive) users.organisation strings so the
 * organisation-centric admin views (Phase 9) don't start fragmented.
 * users.organisation stays as-is for display; users.organisation_id is
 * added in the next migration and populated by matching normalized_name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->timestamps();
        });

        $distinctNames = DB::table('users')
            ->whereNotNull('organisation')
            ->where('organisation', '!=', '')
            ->pluck('organisation')
            ->unique(fn ($name) => $this->normalize($name));

        foreach ($distinctNames as $name) {
            $normalized = $this->normalize($name);

            DB::table('organisations')->insertOrIgnore([
                'name' => trim($name),
                'normalized_name' => $normalized,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }

    private function normalize(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
};
