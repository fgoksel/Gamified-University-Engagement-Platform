<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->enum('category', ['academic', 'scientific', 'sports', 'community'])->default('academic');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_areas');
    }
};
