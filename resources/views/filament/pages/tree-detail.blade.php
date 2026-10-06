<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-share">
        <x-slot name="heading">Tree</x-slot>

        <x-slot name="description">
            Teachers are added to the courses by the dean, and everyone below by the teachers.
        </x-slot>

        @include('filament.pages.partials.tree-unit', ['unit' => $root, 'children' => $children, 'members' => $members])
    </x-filament::section>
</x-filament-panels::page>
