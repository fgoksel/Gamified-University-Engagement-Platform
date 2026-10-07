<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tree unit for a Neptun course is now called a "course", not a "subject".
     */
    public function up(): void
    {
        $this->renameKind('subject', 'course', ['root', 'course', 'subtopic']);
    }

    public function down(): void
    {
        $this->renameKind('course', 'subject', ['root', 'subject', 'subtopic']);
    }

    /**
     * @param  list<string>  $kinds
     */
    private function renameKind(string $from, string $to, array $kinds): void
    {
        // Widen to a plain string first, so both values are allowed while the rows change.
        Schema::table('tree_units', function (Blueprint $table) {
            $table->string('kind', 20)->change();
        });

        DB::table('tree_units')->where('kind', $from)->update(['kind' => $to]);

        Schema::table('tree_units', function (Blueprint $table) use ($kinds) {
            $table->enum('kind', $kinds)->change();
        });
    }
};
