<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4 — PaymentService needs `manual_override` (an admin approving without
 * a proof on file, e.g. a confirmed cash/EFT payment) and `cancelled_at`
 * recorded distinctly from `reviewed_at` (approve/reject).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('manual_override')->default(false)->after('admin_note');
            $table->timestamp('cancelled_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['manual_override', 'cancelled_at']);
        });
    }
};
