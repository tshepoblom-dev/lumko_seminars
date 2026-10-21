<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defence in depth for D5 (events with related data cannot be deleted)
     * and the parallel rule for users (B12): the application already
     * refuses these deletes (EventDeletionGuard, AccountAnonymiser), this
     * migration makes the database refuse them too, at the FK level.
     *
     * NOTE: SQLite does not support ALTER on an existing foreign key in
     * place; Laravel rebuilds the table under the hood for these
     * dropForeign()/foreign() pairs on both SQLite and MySQL, but per the
     * project's own rule ("never claim a migration passed unless you saw
     * it"), this must be run against both a fresh SQLite test DB and a
     * MySQL/MariaDB copy before Stage B is signed off — see the Stage B
     * report's manual verification steps.
     */
    private array $eventChildren = [
        'event_registrations' => 'event_id',
        'attendee_consents' => 'event_id',
        'announcements' => 'event_id',
        'resources' => 'event_id',
        'questions' => 'event_id',
        'polls' => 'event_id',
        'reactions' => 'event_id',
        'connections' => 'event_id',
        'feedback' => 'event_id',
        'programme_sessions' => 'event_id',
    ];

    public function up(): void
    {
        foreach ($this->eventChildren as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('events')->restrictOnDelete();
            });
        }

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('attendee_consents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['event_registration_id']);
            $table->foreign('event_registration_id')->references('id')->on('event_registrations')->restrictOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['event_registration_id']);
            $table->foreign('event_registration_id')->references('id')->on('event_registrations')->restrictOnDelete();
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->restrictOnDelete();

            // Removing a staff account must never delete check-in history (B5).
            $table->dropForeign(['checked_in_by']);
            $table->foreign('checked_in_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ($this->eventChildren as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('events')->cascadeOnDelete();
            });
        }

        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('attendee_consents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['event_registration_id']);
            $table->foreign('event_registration_id')->references('id')->on('event_registrations')->cascadeOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['event_registration_id']);
            $table->foreign('event_registration_id')->references('id')->on('event_registrations')->cascadeOnDelete();
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->dropForeign(['checked_in_by']);
            $table->foreign('checked_in_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
