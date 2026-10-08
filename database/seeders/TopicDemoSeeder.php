<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\RoleService;
use App\Services\TopicService;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY. Not part of DatabaseSeeder: production and fresh installs
 * stay blank. Run it by hand to try the Topics explorer:
 *
 *     php artisan db:seed --class=TopicDemoSeeder
 *
 * It creates two unrelated top-level topics with a few nested levels, a few
 * example roles, and five example accounts (password: "password") to compare
 * what each kind of person sees. The role and topic names are examples; none of
 * them is special. Running it again changes nothing. It refuses to run in
 * production.
 */
class TopicDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The topic demo data is never seeded in production.');

            return;
        }

        $this->call(RolePermissionSeeder::class);

        $admin = User::role(UserRole::Admin)->where('status', 'active')->orderBy('id')->first();

        if ($admin === null) {
            $this->call(AdminUserSeeder::class);
            $admin = User::role(UserRole::Admin)->orderBy('id')->firstOrFail();
        }

        if (Topic::where('title', 'Hungary')->whereNull('parent_id')->exists()) {
            $this->command?->info('The demo topics already exist. Nothing changed.');

            return;
        }

        $topics = app(TopicService::class);
        $roles = app(RoleService::class);
        $assignments = app(AssignmentService::class);

        // Structure: names are only examples. Nothing here needs a "faculty" or a "course".
        $hungary = $topics->create($admin, null, ['title' => 'Hungary', 'description' => 'A top-level topic. Roots have no parent and no required type.']);
        $pecs = $topics->create($admin, $hungary, ['title' => 'Pécs']);
        $university = $topics->create($admin, $pecs, ['title' => 'University of Pécs', 'code' => 'UP']);
        $engineering = $topics->create($admin, $university, ['title' => 'Engineering']);
        $database = $topics->create($admin, $engineering, ['title' => 'Database', 'code' => 'BMEINFO102', 'description' => 'Relational databases and SQL.']);
        $sql = $topics->create($admin, $database, ['title' => 'SQL']);
        $topics->create($admin, $sql, ['title' => 'Joins']);
        $topics->create($admin, $sql, ['title' => 'Indexes']);
        $topics->create($admin, $engineering, ['title' => 'Networks', 'code' => 'BMEINFO201']);
        $budapest = $topics->create($admin, $hungary, ['title' => 'Budapest']);
        $topics->create($admin, $budapest, ['title' => 'Community sports club']);

        $programming = $topics->create($admin, null, ['title' => 'Programming', 'description' => 'A second, unrelated top-level topic.']);
        $topics->create($admin, $programming, ['title' => 'Web development']);
        $topics->create($admin, $programming, ['title' => 'Algorithms']);

        // Example roles. "Admin" is deliberately view-only: a title gives no power.
        $lead = $roles->create($admin, null, [
            'name' => 'Branch lead',
            'description' => 'Runs a branch: organises topics and gives roles inside it.',
            'capabilities' => ['topic.view', 'topic.create', 'topic.edit', 'topic.organise', 'topic.archive', 'access.view', 'access.assign', 'access.end', 'role.define'],
            'delegable' => ['topic.view', 'topic.create', 'topic.edit', 'topic.organise', 'topic.archive', 'access.view', 'access.assign', 'access.end'],
        ]);
        $manager = $roles->create($admin, null, [
            'name' => 'Branch manager',
            'description' => 'Like a branch lead, but cannot define roles. A lead can hand this one to a delegate.',
            'capabilities' => ['topic.view', 'topic.create', 'topic.edit', 'topic.organise', 'access.view', 'access.assign', 'access.end'],
            'delegable' => ['topic.view', 'topic.create', 'access.view'],
        ]);
        $contributor = $roles->create($admin, null, [
            'name' => 'Contributor',
            'description' => 'Adds topics and edits their own.',
            'capabilities' => ['topic.view', 'topic.create'],
        ]);
        $reader = $roles->create($admin, null, [
            'name' => 'Reader',
            'description' => 'Can look, nothing else.',
            'capabilities' => ['topic.view'],
        ]);
        $roles->create($admin, null, [
            'name' => 'Admin',
            'description' => 'Only a label. This role can look and nothing else, to show that titles give no authority.',
            'capabilities' => ['topic.view'],
        ]);

        $people = [
            'lead' => $this->person('Dora Lead', 'demo.lead@example.test', UserRole::Teacher),
            'delegate' => $this->person('Daniel Delegate', 'demo.delegate@example.test', UserRole::Teacher),
            'contributor' => $this->person('Cora Contributor', 'demo.contributor@example.test', UserRole::Teacher),
            'reader' => $this->person('Rita Reader', 'demo.reader@example.test', UserRole::Student),
            'programmer' => $this->person('Pat Programmer', 'demo.programmer@example.test', UserRole::Teacher),
        ];

        // The admin starts the Pécs branch with a lead; the lead then delegates inside it.
        $assignments->grant($admin, $pecs, $lead, $people['lead']);
        $assignments->grant($people['lead'], $database, $manager, $people['delegate']);
        $assignments->grant($people['lead'], $database, $contributor, $people['contributor']);
        $assignments->grant($people['lead'], $sql, $reader, $people['reader']);
        // One person with different responsibilities in different branches.
        $assignments->grant($admin, $programming, $contributor, $people['programmer']);
        $assignments->grant($admin, $budapest, $reader, $people['programmer']);

        $this->command?->info('Demo topics created. Demo accounts (password "'.self::PASSWORD.'"):');
        foreach ($people as $person) {
            $this->command?->line("  {$person->email}  {$person->name}");
        }
    }

    private function person(string $name, string $email, UserRole $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'status' => 'active',
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );
        $user->assignRole($role);

        return $user;
    }
}
