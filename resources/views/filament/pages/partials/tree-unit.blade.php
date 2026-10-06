{{-- One unit of the tree with its active people, then its child units. Inline styles: Filament's default theme only ships its own CSS classes --}}
@php
    $people = ($members[$unit->id] ?? collect())->groupBy(fn ($membership) => $membership->role->value);
    $students = $people->get('student', collect());
    $kindLabel = match ($unit->kind) {
        \App\Enums\TreeUnitKind::Root => 'Dean',
        \App\Enums\TreeUnitKind::Subject => 'Subject',
        \App\Enums\TreeUnitKind::Subtopic => 'Subtopic',
    };
@endphp

<div style="margin-top: 0.75rem">
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem">
        <x-filament::badge :color="$unit->kind === \App\Enums\TreeUnitKind::Subject ? 'primary' : 'gray'">
            {{ $kindLabel }}
        </x-filament::badge>

        <strong>{{ $unit->title }}</strong>

        @if ($unit->course)
            <span style="opacity: 0.7">{{ $unit->course->code }}</span>
        @endif
    </div>

    <ul style="list-style: none; padding-inline-start: 0.25rem; margin-top: 0.25rem; font-size: 0.875rem">
        @foreach ([\App\Enums\TreeRole::Dean, \App\Enums\TreeRole::Teacher, \App\Enums\TreeRole::CoTeacher, \App\Enums\TreeRole::Tutor] as $role)
            @foreach ($people->get($role->value, collect()) as $membership)
                <li>
                    {{ $role->label() }}: {{ $membership->user->name }}
                    @if ($role === \App\Enums\TreeRole::Tutor && filled($membership->permissions))
                        <span style="opacity: 0.7">({{ str_replace('_', ' ', implode(', ', $membership->permissions)) }})</span>
                    @endif
                </li>
            @endforeach
        @endforeach

        @if ($students->isNotEmpty())
            <li>
                Students: {{ $students->count() }}
                @if ($manual = $students->where('manual', true)->count())
                    <span style="opacity: 0.7">({{ $manual }} added by hand)</span>
                @endif
            </li>
        @endif

        @if ($unit->kind === \App\Enums\TreeUnitKind::Subject && $people->isEmpty())
            <li style="opacity: 0.7">No teacher yet</li>
        @endif
    </ul>

    @if ($children->has($unit->id))
        <div style="margin-inline-start: 1.5rem; padding-inline-start: 0.75rem; border-inline-start: 1px solid rgba(127, 127, 127, 0.3)">
            @foreach ($children[$unit->id] as $child)
                @include('filament.pages.partials.tree-unit', ['unit' => $child, 'children' => $children, 'members' => $members])
            @endforeach
        </div>
    @endif
</div>
