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
    protected ?string $heading = 'System overview';

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
            $this->accountsStat('Organizers', UserRole::Teacher, Heroicon::OutlinedUserGroup),
            $this->accountsStat('Students', UserRole::Student, Heroicon::OutlinedAcademicCap),
            $this->semesterStat(),
            // No events table yet: shown so the layout matches UC-3.0.
            Stat::make('Active events', '–')
                ->description('Available once events are added')
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
            ->description(sprintf('%d active · %d invited', $counts['active'] ?? 0, $counts['invited'] ?? 0))
            ->icon($icon);
    }

    /**
     * Days left until the active semester ends.
     */
    private function semesterStat(): Stat
    {
        $semester = Semester::query()->where('status', 'active')->latest('starts_at')->first();

        if (! $semester) {
            return Stat::make('Current semester', 'None')
                ->description('No active semester')
                ->color('warning')
                ->icon(Heroicon::OutlinedCalendarDays);
        }

        $daysLeft = max(0, (int) today()->diffInDays($semester->ends_at, false));

        return Stat::make($semester->name, $daysLeft.' '.str('day')->plural($daysLeft).' left')
            ->description('Ends '.$semester->ends_at->format('Y. m. d.'))
            ->icon(Heroicon::OutlinedCalendarDays);
    }
}
