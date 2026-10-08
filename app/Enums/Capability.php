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

    case DefineRoles = 'role.define';

    public function label(): string
    {
        return match ($this) {
            self::View => 'View topics',
            self::Create => 'Add topics',
            self::Edit => 'Edit any topic',
            self::Organise => 'Move topics',
            self::Archive => 'Archive topics',
            self::AccessView => 'See who has access',
            self::AccessAssign => 'Give people roles',
            self::AccessEnd => 'End and replace roles',
            self::DefineRoles => 'Define roles',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => 'Open the topic and everything below it, and see its description.',
            self::Create => 'Add child topics, and edit the topics the person added themselves.',
            self::Edit => 'Change the title, code and description of any topic in the branch, including other people\'s.',
            self::Organise => 'Move a topic, with everything below it, to another place. Needs this role at both the old and the new place.',
            self::Archive => 'Hide a topic and everything below it from normal browsing, and bring it back later. Nothing is deleted; roles and history are kept.',
            self::AccessView => 'See the people and roles assigned in the branch. Does not allow changing them.',
            self::AccessAssign => 'Give an existing role to a person in the branch, within the powers the giver may pass on.',
            self::AccessEnd => 'End or hand over a role in the branch. Never one\'s own role.',
            self::DefineRoles => 'Create and edit reusable roles for this branch, within the powers the creator may pass on.',
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
            self::AccessAssign, self::AccessEnd => [self::View, self::AccessView],
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
