<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-clock">
        <x-slot name="heading">
            {{ __('admin.coming_soon.heading') }}
        </x-slot>

        <x-slot name="description">
            {{ $description }}
        </x-slot>

        <p>{{ __('admin.coming_soon.this_page_will_let') }}</p>

        {{-- Inline styles: Filament's default theme only ships its own CSS classes --}}
        <ul style="list-style: disc; padding-inline-start: 1.25rem; margin-top: 0.5rem">
            @foreach ($plannedFeatures as $feature)
                <li>{{ $feature }}</li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>