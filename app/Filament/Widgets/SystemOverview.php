<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Models\Semester;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Read-only statistics on the admin Dashboard (Task #17, UC-3.0):
 * organizers, students and the days left in the current semester.
 */
class SystemOverview extends StatsOverviewWidget
{
    protected function getHeading(): ?string
    {
        return __('admin.overview.heading');
    }

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [
            $this->accountsStat(__('admin.overview.organizers'), UserRole::Teacher, Heroicon::OutlinedUserGroup),
            $this->accountsStat(__('admin.overview.students'), UserRole::Student, Heroicon::OutlinedAcademicCap),
            $this->semesterStat(),
            // No events table yet: shown so the layout matches UC-3.0.
            Stat::make(__('admin.overview.active_events'), '–')
                ->description(__('admin.overview.available_once_events_are_added'))
                ->icon(Heroicon::OutlinedCalendar),
        ];
    }

    /**
     * Total accounts with this role, with the active / invited split.
     */
    private function accountsStat(string $label, UserRole $role, Heroicon $icon): Stat
    {
        $counts = User::role($role)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Stat::make($label, (string) $counts->sum())
            ->description(__('admin.overview.active_and_invited', ['active' => $counts['active'] ?? 0, 'invited' => $counts['invited'] ?? 0]))
            ->icon($icon);
    }

    /**
     * Days left until the active semester ends.
     */
    private function semesterStat(): Stat
    {
        $semester = Semester::query()->where('status', 'active')->latest('starts_at')->first();

        if (! $semester) {
            return Stat::make(__('admin.overview.current_semester'), __('admin.overview.none'))
                ->description(__('admin.overview.no_active_semester'))
                ->color('warning')
                ->icon(Heroicon::OutlinedCalendarDays);
        }

        $daysLeft = max(0, (int) today()->diffInDays($semester->ends_at, false));

        return Stat::make($semester->name, trans_choice('admin.overview.days_left', $daysLeft))
            ->description(__('admin.overview.ends_on', ['date' => $semester->ends_at->format('Y. m. d.')]))
            ->icon(Heroicon::OutlinedCalendarDays);
    }
}
