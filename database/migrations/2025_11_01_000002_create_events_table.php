<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('venue_name')->nullable();
            $table->text('venue_address')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('online_price', 8, 2)->default(0);
            $table->decimal('in_person_price', 8, 2)->default(0);
            $table->string('status')->default('draft'); // draft | published | completed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
