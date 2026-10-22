<?php

namespace App\Console\Commands;

use App\Services\DoseMaintenanceService;
use Illuminate\Console\Command;

class MaintainDoses extends Command
{
    protected $signature = 'doses:maintain {--dry-run : Report planned creations and missed transitions without changing records}';

    protected $description = 'Create pending doses for the current Asia/Kolkata date and mark open doses missed after their grace period. A manual run writes to the database. --dry-run writes nothing. This command is not scheduled.';

    public function handle(DoseMaintenanceService $maintenance): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = $maintenance->maintain($dryRun);

        $this->line($dryRun
            ? 'Dry run: no records were changed.'
            : 'Dose maintenance wrote to the database.');
        $this->line('Eligible schedules examined: '.$counts['eligible']);
        $this->line('New dose events created: '.$counts['created']);
        $this->line('Invalid schedules skipped: '.$counts['invalid']);
        $this->line('Events marked missed: '.$counts['missed']);
        $this->line('Demo missed events: '.($counts['demo_missed'] ?? 0));

        return self::SUCCESS;
    }
}
