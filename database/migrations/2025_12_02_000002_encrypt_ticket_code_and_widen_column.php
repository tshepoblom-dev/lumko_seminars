<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B5 — `code` moves from a plain `varchar(64)` to an encrypted-at-rest
 * `text` column. All lookups already go through `code_hash` (added in an
 * earlier migration and backfilled); this migration only has to widen the
 * column and re-encrypt whatever plaintext codes already exist so nothing
 * is lost. Ciphertext (`Crypt::encryptString()`) is long and variable
 * length, hence `text` rather than a fixed varchar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('tickets')->select('id', 'code')->get();

        Schema::table('tickets', function (Blueprint $table) {
            $table->text('code_encrypted')->nullable()->after('code');
        });

        foreach ($existing as $row) {
            DB::table('tickets')->where('id', $row->id)->update([
                'code_encrypted' => Crypt::encryptString($row->code),
            ]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->renameColumn('code_encrypted', 'code');
        });
    }

    public function down(): void
    {
        $existing = DB::table('tickets')->select('id', 'code')->get();

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('code_plain', 64)->nullable()->after('code');
        });

        foreach ($existing as $row) {
            DB::table('tickets')->where('id', $row->id)->update([
                'code_plain' => $row->code ? Crypt::decryptString($row->code) : null,
            ]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->renameColumn('code_plain', 'code');
        });

        // Restore the unique constraint up() dropped, so down() is a true
        // inverse — this only runs on a rollback, not in production.
        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('code');
        });
    }
};
