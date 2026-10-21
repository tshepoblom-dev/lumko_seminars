<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resources uploaded from the speaker portal (speaker_id) or a partner's
 * portal (partner_id). Staff-created resources have neither. Owner uploads
 * are published straight away (owner decision) but don't trigger the
 * "new resource available" email to every attendee (resources:release
 * skips them); staff can announce them if they want to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->foreignId('speaker_id')->nullable()->after('created_by')->constrained('speakers')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->after('speaker_id')->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropConstrainedForeignId('speaker_id');
        });
    }
};
