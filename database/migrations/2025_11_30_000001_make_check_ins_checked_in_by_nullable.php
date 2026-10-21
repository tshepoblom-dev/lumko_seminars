<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5 — must run *before* 2025_12_01_000006 (which adds
 * checked_in_by->nullOnDelete()). MySQL validates at ALTER TABLE time that
 * a column a SET NULL foreign key targets is actually nullable, and fails
 * outright if it isn't ("Column 'checked_in_by' cannot be NOT NULL: needed
 * in a foreign key constraint ... SET NULL") — SQLite is lenient here and
 * lets the same migration order "work" in tests, which is exactly why this
 * needs its own real-MySQL verification, not just the SQLite test suite.
 * Named to sort before the 2025_12_01_* batch rather than editing any of
 * those existing migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->unsignedBigInteger('checked_in_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->unsignedBigInteger('checked_in_by')->nullable(false)->change();
        });
    }
};
