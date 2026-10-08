<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving hides a topic, and everything below it, from normal browsing
     * without deleting anything. Only the archived topic itself is marked;
     * what lies below is hidden because an ancestor is archived.
     */
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
            $table->foreignId('archived_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by_id');
            $table->dropColumn('archived_at');
        });
    }
};
