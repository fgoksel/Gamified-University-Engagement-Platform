<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the roles and permissions (Technical Specification 6.4 and 8.2).
 *
 * Must run before every other seeder. Safe to run again: existing roles and
 * permissions are reused, and each role's permissions are reset to this list.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Every permission in the system, with the use case it comes from.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'leaderboard.view' => 'UC-1.2, UC-2.2, UC-4.1',
        'events.view' => 'UC-1.5, UC-2.3, UC-3.5',
        'events.manage_own' => 'UC-1.3',
        'applications.manage' => 'UC-1.6',
        'points.credit' => 'UC-1.7',
        'qr_codes.manage' => 'UC-1.8',
        'topic_tags.request' => 'UC-1.9',
        'events.apply' => 'UC-2.3',
        'qr_codes.redeem' => 'UC-2.3',
        'organizers.manage' => 'UC-3.1',
        'students.manage' => 'UC-3.2',
        'semesters.manage' => 'UC-3.3',
        'subject_areas.manage' => 'UC-3.4',
        'topic_tags.manage' => 'UC-3.4',
        'events.moderate' => 'UC-3.5',
        'points.reverse' => 'UC-3.5',
    ];

    /**
     * Which permissions each role gets.
     *
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            UserRole::Admin->value => [
                'leaderboard.view',
                'events.view',
                'organizers.manage',
                'students.manage',
                'semesters.manage',
                'subject_areas.manage',
                'topic_tags.manage',
                'events.moderate',
                'points.reverse',
            ],
            UserRole::Teacher->value => [
                'leaderboard.view',
                'events.view',
                'events.manage_own',
                'applications.manage',
                'points.credit',
                'qr_codes.manage',
                'topic_tags.request',
            ],
            UserRole::Student->value => [
                'leaderboard.view',
                'events.view',
                'events.apply',
                'qr_codes.redeem',
            ],
            UserRole::Observer->value => [
                'leaderboard.view',
            ],
        ];
    }

    public function run(): void
    {
        // Roles and permissions are cached; clear it so the new ones are seen.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Clear again by hand: DatabaseSeeder turns off model events, and the
        // package relies on those events to refresh its cache after creating.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::rolePermissions() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }
    }
}
