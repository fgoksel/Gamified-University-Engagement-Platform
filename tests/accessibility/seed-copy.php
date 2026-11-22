<?php

// Task #131: the smallest extra demo data for the accessibility check, for the throwaway copy
// only (serve-copy.sh pipes this file into it after the Sprint 2 demo seed). Never run it on a
// real database.
//
// 1. Ivan (Event organiser) cannot import or invite people, so his Access tab has no "Invite a
//    person" button. This gives him one more role on Faculty of Informatics that can, so the
//    dialog can be measured.
// 2. Anna sees only the leaderboards of her own area (Task #119), and the demo boards are all
//    above it, so her Leaderboards page would be empty. This adds one board on Databases.
// Nothing else changes.

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\AreaLeaderboards;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$admin = User::role('admin')->orderBy('id')->firstOrFail();
$ivan = User::where('email', 'sprint2.ivan@example.test')->firstOrFail();
$informatics = Topic::where('title', 'Faculty of Informatics')->firstOrFail();

$powers = ['topic.view', 'access.view', 'access.assign', 'access.import', 'events.view', 'events.apply'];
$role = RoleDefinition::create([
    'name' => 'People admin (accessibility check)',
    'description' => 'Only in the throwaway copy of the accessibility check (Task #131).',
    'capabilities' => $powers,
    'delegable' => $powers,
    'created_by_id' => $admin->id,
]);

RoleAssignment::create([
    'topic_id' => $informatics->id,
    'user_id' => $ivan->id,
    'role_definition_id' => $role->id,
    'granted_by_id' => $admin->id,
    'started_at' => now(),
]);

echo "Ivan can now invite people on Faculty of Informatics (copy only).\n";

$databases = Topic::where('title', 'Databases')->firstOrFail();
$boards = app(AreaLeaderboards::class);
$board = $boards->create($admin, $databases, ['name' => 'Databases total', 'show_neptun_code' => true, 'show_first_name' => true, 'show_last_name' => false]);
$boards->setPublished($admin, $board, true);

echo "Anna can now see the leaderboard \"Databases total\" (copy only).\n";
