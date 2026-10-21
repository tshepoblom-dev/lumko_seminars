<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->after('organisation')
                ->constrained('organisations')->nullOnDelete();
        });

        $organisations = DB::table('organisations')->get(['id', 'normalized_name']);
        $byNormalized = $organisations->keyBy('normalized_name');

        DB::table('users')->whereNotNull('organisation')->where('organisation', '!=', '')
            ->orderBy('id')->chunkById(200, function ($users) use ($byNormalized) {
                foreach ($users as $user) {
                    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $user->organisation)));
                    $match = $byNormalized->get($normalized);

                    if ($match) {
                        DB::table('users')->where('id', $user->id)->update(['organisation_id' => $match->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organisation_id');
        });
    }
};
