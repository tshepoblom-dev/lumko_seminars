<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('checked_in_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
