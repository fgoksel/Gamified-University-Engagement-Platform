<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_tag_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('proposed_name');
            $table->enum('category', ['academic', 'scientific', 'sports', 'community']);
            $table->text('justification');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_topic_tag_id')->nullable()->constrained('topic_tags')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_tag_requests');
    }
};
