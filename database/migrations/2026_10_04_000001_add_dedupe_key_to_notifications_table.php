<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a stable business key to the notifications table.
     *
     * Roster notifications are written directly (inside the roster change
     * transaction) rather than through the normal `notify()` flow, so a client
     * retry or a replayed job could otherwise insert a second row for the same
     * business event. The `dedupe_key` is deterministic per
     * (event, recipient) — e.g. `roster:12:v4:emp:7` — and the unique index
     * makes a duplicate insert a no-op instead of a duplicate notification.
     *
     * The column is nullable so every other notification type (leave, billing,
     * invitations) keeps working unchanged.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('dedupe_key')->nullable()->after('type');
            $table->unique('dedupe_key');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
