<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-share">
        <x-slot name="heading">Tree</x-slot>

        <x-slot name="description">
            Courses come from the faculty and appear here by themselves. The dean adds the teachers, and the teachers everyone below.
        </x-slot>

        @include('filament.pages.partials.tree-unit', ['unit' => $root, 'children' => $children, 'members' => $members])
    </x-filament::section>
</x-filament-panels::page>
