<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live attendance check-in (Task #80, meeting of 26 Oct): the organiser shows a QR code that
     * changes every 10 seconds. A session is the time the code is being shown for an event.
     * The code itself is never stored: it is computed from the session's secret and the time,
     * so a session only needs to say whether check-in is open, paused or over.
     */
    public function up(): void
    {
        Schema::create('event_checkin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            // Who opened it. Points are credited in their name, and they can never scan their own code.
            $table->foreignId('issuer_id')->constrained('users')->restrictOnDelete();
            // Random and never shown: the codes are signed with it, so ending the session ends the codes.
            $table->string('secret', 64);
            $table->timestamp('opened_at')->useCurrent();
            // Paused: the code is hidden and nothing is accepted until it is resumed.
            $table->timestamp('paused_at')->nullable();
            // Over for good. Showing the code again starts a new session with a new secret.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_checkin_sessions');
    }
};
