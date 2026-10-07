<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        System Admins can see and manage everything. The system always keeps at least one active System Admin:
        you cannot remove, replace or deactivate yourself, so with only one System Admin, appoint a second one first.
        A role called "Admin" inside a topic is only a topic role and gives none of this authority.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
