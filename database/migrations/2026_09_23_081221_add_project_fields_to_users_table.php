<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('faculty_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['invited', 'active', 'banned'])->default('invited');
            $table->boolean('must_change_password')->default(true);
            $table->string('neptun_code', 10)->nullable()->unique();
            $table->string('activation_token')->nullable();
            $table->timestamp('activation_token_expires_at')->nullable();
            $table->enum('appearance', ['light', 'dark'])->default('light');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['faculty_id']);
            $table->dropColumn([
                'faculty_id',
                'status',
                'must_change_password',
                'neptun_code',
                'activation_token',
                'activation_token_expires_at',
                'appearance',
            ]);
        });
    }
};