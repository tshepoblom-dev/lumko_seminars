<?php

use App\Support\TicketCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a keyed-hash column used for ticket lookups so the raw code (now
     * stored encrypted) is never used in a WHERE clause. Backfills existing
     * rows so this ships safely on a database that already has tickets.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable()->after('code');
        });

        DB::table('tickets')->whereNull('code_hash')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('tickets')->where('id', $row->id)->update([
                    'code_hash' => TicketCode::hash($row->code),
                ]);
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['code_hash']);
            $table->dropColumn('code_hash');
        });
    }
};
