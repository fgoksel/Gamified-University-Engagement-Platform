<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Faculties own the courses, and each faculty has at most one tree:
     *
     * - faculties.name is unique (the MySQL collation makes it case-insensitive)
     * - every course belongs to one faculty (courses.faculty_id)
     * - a tree's root points to its faculty, at most one root per faculty
     * - one active dean per tree, and a person is the active dean of one tree at most
     *
     * Existing trees are linked to a faculty with the tree's name, created when
     * missing, and their courses move to that faculty.
     */
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('faculty_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        Schema::table('tree_units', function (Blueprint $table) {
            $table->foreignId('faculty_id')->nullable()->unique()->after('parent_id')->constrained()->restrictOnDelete();
        });

        $this->linkExistingTrees();

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('faculty_id')->nullable(false)->change();
        });

        // Only active dean rows get a value here, so the unique indexes ignore
        // every other role and every ended dean.
        Schema::table('unit_memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('active_dean_user_id')->nullable()
                ->virtualAs("case when role = 'dean' and ended_at is null then user_id end");
            $table->unsignedBigInteger('active_dean_unit_id')->nullable()
                ->virtualAs("case when role = 'dean' and ended_at is null then unit_id end");

            $table->unique('active_dean_user_id');
            $table->unique('active_dean_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('unit_memberships', function (Blueprint $table) {
            $table->dropUnique(['active_dean_user_id']);
            $table->dropUnique(['active_dean_unit_id']);
            $table->dropColumn(['active_dean_user_id', 'active_dean_unit_id']);
        });

        Schema::table('tree_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('faculty_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('faculty_id');
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }

    private function linkExistingTrees(): void
    {
        foreach (DB::table('tree_units')->where('kind', 'root')->orderBy('id')->get() as $root) {
            $facultyId = DB::table('faculties')->whereRaw('lower(name) = ?', [Str::lower($root->title)])->value('id')
                ?? DB::table('faculties')->insertGetId([
                    'name' => $root->title,
                    'code' => $this->freeCode($root->title),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (DB::table('tree_units')->where('faculty_id', $facultyId)->exists()) {
                throw new RuntimeException("Two trees would belong to the faculty \"{$root->title}\". Remove the duplicate tree first.");
            }

            DB::table('tree_units')->where('id', $root->id)->update(['faculty_id' => $facultyId]);

            $courseIds = DB::table('tree_units')
                ->where('kind', 'course')
                ->where('path', 'like', $root->path.'%')
                ->pluck('course_id');

            DB::table('courses')->whereIn('id', $courseIds)->update(['faculty_id' => $facultyId]);
        }

        // Courses in no tree yet (made by an older enrolment import) need a faculty too.
        if (DB::table('courses')->whereNull('faculty_id')->exists()) {
            $facultyId = DB::table('faculties')->where('name', 'Unassigned courses')->value('id')
                ?? DB::table('faculties')->insertGetId([
                    'name' => 'Unassigned courses',
                    'code' => $this->freeCode('Unassigned courses'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('courses')->whereNull('faculty_id')->update(['faculty_id' => $facultyId]);
        }
    }

    /**
     * A short unused faculty code from the initials, e.g. "Faculty of Informatics" -> "FI".
     */
    private function freeCode(string $name): string
    {
        $words = array_filter(preg_split('/\s+/', $name), fn (string $word) => ! in_array(Str::lower($word), ['of', 'and', 'the'], true));
        $base = Str::upper(implode('', array_map(fn (string $word) => Str::substr($word, 0, 1), $words))) ?: 'FAC';
        $code = $base;

        for ($i = 2; DB::table('faculties')->where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }
};
