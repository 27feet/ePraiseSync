<?php

namespace App\Console\Commands;

use App\Services\EpraiseFileImporter;
use Illuminate\Console\Command;

class ImportEpraiseFileCommand extends Command
{
    protected $signature = 'epraise:import-file
        {type : points, demerits or interventions}
        {file : Path to a saved ePraise API JSON array}
        {--apply : Write changes; without this option only preview database changes}
        {--validate-only : Validate the file without connecting to the database}';

    protected $description = 'Import saved ePraise records directly, without API requests or queued import jobs';

    public function handle(EpraiseFileImporter $importer): int
    {
        if ($this->option('apply') && $this->option('validate-only')) {
            $this->error('Use --apply or --validate-only, not both.');

            return self::FAILURE;
        }
        set_time_limit(0);
        try {
            $type = $this->argument('type');
            $rows = $importer->read($type, $this->argument('file'));
            $this->info('Validated '.count($rows).' records.');
            if ($this->option('validate-only')) {
                return self::SUCCESS;
            }
            $apply = (bool) $this->option('apply');
            $this->info($apply ? 'Applying import in batches of 500.' : 'DRY RUN: database changes will not be saved.');
            $counts = $importer->import($type, $rows, $apply);
            $this->table(array_keys($counts), [array_values($counts)]);
            $this->line('No missing-record deletions or Arbor jobs were dispatched by this command.');
            if ($counts['queued_skipped'] > 0) {
                $this->warn('Some records already have queued jobs. Drain those jobs, then rerun this import.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->warn('If applying, earlier completed batches remain saved; rerunning the same file is supported.');

            return self::FAILURE;
        }
    }
}
