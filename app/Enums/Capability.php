<?php

namespace App\Enums;

/**
 * The controlled registry of actions a topic role can contain.
 *
 * A role definition is only ever a list of these values. A role's name never
 * confers anything, and nothing outside this list can be put in a role.
 * Creating topics, organising them, administering access and defining roles
 * are separate capabilities on purpose.
 */
enum Capability: string
{
    case View = 'topic.view';

    case Create = 'topic.create';

    case Edit = 'topic.edit';

    case Organise = 'topic.organise';

    case Archive = 'topic.archive';

    case AccessView = 'access.view';

    case AccessAssign = 'access.assign';

    case AccessEnd = 'access.end';

    // Task #72: give a role to a whole list of people at once (S3: passed on like the others).
    case AccessImport = 'access.import';

    case DefineRoles = 'role.define';

    case EventsView = 'events.view';

    case EventsApply = 'events.apply';

    case EventsCreate = 'events.create';

    case EventsManage = 'events.manage';

    case ApplicationsManage = 'applications.manage';

    case PointsCredit = 'points.credit';

    case QrCodesManage = 'qr_codes.manage';

    // Task #89: make the area leaderboards of this topic and the topics below it.
    case LeaderboardsManage = 'leaderboards.manage';

    public function label(): string
    {
        return match ($this) {
            self::View => __('capabilities.view_areas'),
            self::Create => __('capabilities.add_areas'),
            self::Edit => __('capabilities.edit_any_area'),
            self::Organise => __('capabilities.move_areas'),
            self::Archive => __('capabilities.archive_areas'),
            self::AccessView => __('capabilities.see_who_has_access'),
            self::AccessAssign => __('capabilities.give_people_roles'),
            self::AccessEnd => __('capabilities.end_and_replace_roles'),
            self::AccessImport => __('capabilities.import_students'),
            self::DefineRoles => __('capabilities.define_roles'),
            self::EventsView => __('capabilities.view_events'),
            self::EventsApply => __('capabilities.apply_to_events'),
            self::EventsCreate => __('capabilities.create_draft_events'),
            self::EventsManage => __('capabilities.manage_events'),
            self::ApplicationsManage => __('capabilities.manage_applicants'),
            self::PointsCredit => __('capabilities.credit_points'),
            self::QrCodesManage => __('capabilities.manage_event_qr_codes'),
            self::LeaderboardsManage => __('capabilities.manage_leaderboards'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => __('capabilities.open_the_area_and_everything'),
            self::Create => __('capabilities.add_child_areas_and_edit'),
            self::Edit => __('capabilities.change_the_title_code'),
            self::Organise => __('capabilities.move_an_area_with_everything'),
            self::Archive => __('capabilities.hide_an_area_and_everything'),
            self::AccessView => __('capabilities.see_the_people_and_roles'),
            self::AccessAssign => __('capabilities.give_an_existing_role'),
            self::AccessEnd => __('capabilities.end_or_hand_over'),
            self::AccessImport => __('capabilities.give_a_role'),
            self::DefineRoles => __('capabilities.create_and_edit_reusable_roles'),
            self::EventsView => __('capabilities.browse_the_published_visible_events'),
            self::EventsApply => __('capabilities.apply_for_the_published_events'),
            self::EventsCreate => __('capabilities.create_events_and_edit_their'),
            self::EventsManage => __('capabilities.edit_publish_put_on_hold'),
            self::ApplicationsManage => __('capabilities.review_accept_waitlist_or_reject'),
            self::PointsCredit => __('capabilities.confirm_attendance_and_credit'),
            self::QrCodesManage => __('capabilities.create_configure_and_print'),
            self::LeaderboardsManage => __('capabilities.create_edit_and_delete_leaderboards'),
        };
    }

    /**
     * Capabilities that only make sense together with others.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::View => [],
            self::AccessView => [self::View],
            self::AccessAssign, self::AccessEnd, self::AccessImport => [self::View, self::AccessView],
            self::EventsApply, self::EventsCreate => [self::View, self::EventsView],
            self::EventsManage => [self::View, self::EventsView, self::EventsCreate],
            self::ApplicationsManage, self::PointsCredit => [self::View, self::EventsView],
            self::QrCodesManage => [self::View, self::EventsView, self::PointsCredit],
            default => [self::View],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Registry shown by the role builder.
     *
     * @return list<array{value: string, label: string, description: string, requires: list<string>}>
     */
    public static function registry(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'requires' => array_map(fn (self $required) => $required->value, $case->requires()),
        ], self::cases());
    }

    /**
     * Keep only registry values, without duplicates, in registry order.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    public static function normalise(iterable $values): array
    {
        $given = collect($values)->map(fn ($value) => (string) $value)->all();

        return array_values(array_filter(self::values(), fn (string $value) => in_array($value, $given, true)));
    }
}
