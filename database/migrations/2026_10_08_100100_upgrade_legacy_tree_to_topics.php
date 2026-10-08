<?php

use App\Support\LegacyTreeUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Copy the previous faculty tree into topics and role assignments.
     * A fresh installation has no legacy rows, so nothing is created and no
     * organisational role exists afterwards.
     */
    public function up(): void
    {
        (new LegacyTreeUpgrade)->run();
    }

    /**
     * The legacy tables were never touched, so there is nothing to restore.
     * The previous migration's down() drops the new tables; this one has no
     * extra work. Anything created in topics after the upgrade is not kept.
     */
    public function down(): void
    {
        //
    }
};
