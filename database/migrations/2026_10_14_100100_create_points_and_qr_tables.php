<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The immutable points ledger and the event QR codes.
     *
     * A student's points are always the sum of their ledger rows. The database
     * itself allows one base credit per student and event, whichever way the
     * credit arrives (manual list, passport scan or event QR code).
     */
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            // Who authorised the credit: the organiser, or the issuer of the QR code that was scanned.
            $table->foreignId('credited_by_id')->constrained('users')->restrictOnDelete();
            // Positive for a credit, negative for a reversal.
            $table->integer('points');
            $table->enum('type', ['credit', 'reversal'])->default('credit');
            // Which route wrote it: manual, passport, qr_poster, qr_slip, milestone.
            $table->string('source', 20);
            // The event's category at the moment of crediting; later tag edits never change it.
            $table->enum('category', ['academic', 'scientific', 'sports', 'community']);
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('point_transactions')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'semester_id']);
            $table->index(['event_id', 'type']);
            // Only credits get a value, so a reversal never collides with the base credit
            // while a second credit for the same student and event always does.
            $table->unsignedBigInteger('credit_event_id')->nullable()
                ->virtualAs("case when type = 'credit' then event_id end");
            $table->unsignedBigInteger('credit_student_id')->nullable()
                ->virtualAs("case when type = 'credit' then student_id end");
            $table->unique(['credit_event_id', 'credit_student_id'], 'point_transactions_base_credit_unique');
        });

        Schema::create('qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            // Who made the code. Never allowed to redeem it.
            $table->foreignId('issuer_id')->constrained('users')->restrictOnDelete();
            // Random; shown to students only inside a signed code.
            $table->string('token_code', 100)->unique();
            // Poster: shown to a class, one claim per student. Slip: one claim in total.
            $table->enum('type', ['poster', 'slip']);
            // For a task of several parts (HW 1, 2, 3): which part this code is, out of how many.
            $table->unsignedTinyInteger('step_number')->nullable();
            $table->unsignedTinyInteger('total_steps')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('event_id');
        });

        Schema::create('qr_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_token_id')->constrained('qr_tokens')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('step_number')->nullable();
            $table->timestamp('redeemed_at')->useCurrent();
            // Empty for a scan that only recorded progress towards the award.
            $table->foreignId('point_transaction_id')->nullable()->constrained('point_transactions')->restrictOnDelete();
            // Filled with the token id for a slip only: the unique index then allows one claim in total.
            $table->unsignedBigInteger('slip_claim_id')->nullable()->unique();
            $table->unique(['qr_token_id', 'student_id']);
            $table->index('student_id');
        });

        // Progress through a task of several parts. Points are credited once, when all parts are done.
        Schema::create('event_milestone_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('step_number');
            $table->foreignId('qr_token_id')->constrained('qr_tokens')->restrictOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->unique(['event_id', 'student_id', 'step_number'], 'event_milestone_progress_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_milestone_progress');
        Schema::dropIfExists('qr_redemptions');
        Schema::dropIfExists('qr_tokens');
        Schema::dropIfExists('point_transactions');
    }
};
