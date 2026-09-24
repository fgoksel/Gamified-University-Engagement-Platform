<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('password');
            $table->enum('status', ['active', 'invited', 'inactive'])->default('invited')->index()->after('avatar');
            $table->boolean('must_change_password')->default(true)->after('status');
            $table->string('neptun_code', 20)->nullable()->unique()->after('must_change_password');
            $table->string('major')->nullable()->after('neptun_code');
            $table->unsignedTinyInteger('year_of_study')->nullable()->after('major');
            $table->enum('appearance', ['light', 'dark'])->default('light')->after('year_of_study');
            $table->foreignId('faculty_id')->nullable()->after('appearance')->constrained()->nullOnDelete();
            $table->string('activation_token', 100)->nullable()->index()->after('faculty_id');
            $table->timestamp('activation_token_expires_at')->nullable()->after('activation_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['faculty_id']);
            $table->dropUnique(['neptun_code']);
            $table->dropIndex(['status']);
            $table->dropIndex(['activation_token']);
            $table->dropColumn([
                'avatar',
                'status',
                'must_change_password',
                'neptun_code',
                'major',
                'year_of_study',
                'appearance',
                'faculty_id',
                'activation_token',
                'activation_token_expires_at',
            ]);
        });
    }
};
