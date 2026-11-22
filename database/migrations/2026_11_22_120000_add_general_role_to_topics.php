<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General areas (Task #133, #128 Q3): an area with a general role gives that role to every
     * student whose account becomes active. Empty: an ordinary area, joined by import.
     */
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->foreignId('general_role_id')->nullable()->constrained('role_definitions')->nullOnDelete();
            $table->foreignId('general_set_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('general_set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('general_role_id');
            $table->dropConstrainedForeignId('general_set_by_id');
            $table->dropColumn('general_set_at');
        });
    }
};
