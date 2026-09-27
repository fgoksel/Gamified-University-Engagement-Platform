<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-clock">
        <x-slot name="heading">
            Coming soon
        </x-slot>

        <x-slot name="description">
            {{ $description }}
        </x-slot>

        <p>This page will let administrators:</p>

        {{-- Inline styles: Filament's default theme only ships its own CSS classes --}}
        <ul style="list-style: disc; padding-inline-start: 1.25rem; margin-top: 0.5rem">
            @foreach ($plannedFeatures as $feature)
                <li>{{ $feature }}</li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>