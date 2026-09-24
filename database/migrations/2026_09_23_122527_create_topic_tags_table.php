<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('category', ['academic', 'scientific', 'sports', 'community']);
            $table->text('description')->nullable();
            // Deactivated tags stay on historical events but can't be picked for new ones.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_tags');
    }
};
