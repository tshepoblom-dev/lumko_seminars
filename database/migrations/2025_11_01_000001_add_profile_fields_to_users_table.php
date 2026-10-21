<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('attendee')->after('password');
            $table->string('first_name')->nullable()->after('role');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('mobile')->nullable()->after('last_name');
            $table->string('organisation')->nullable()->after('mobile');
            $table->string('job_title')->nullable()->after('organisation');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'first_name', 'last_name', 'mobile', 'organisation', 'job_title']);
        });
    }
};
