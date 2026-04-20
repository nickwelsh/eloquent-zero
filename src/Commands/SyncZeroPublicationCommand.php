<?php

namespace NickWelsh\EloquentZero\Commands;

use Illuminate\Console\Command;
use NickWelsh\EloquentZero\Publications\ZeroPublicationSynchronizer;

class SyncZeroPublicationCommand extends Command
{
    protected $signature = 'zero:sync-publication
        {--name= : Publication name override}
        {--connection= : Force single connection}
        {--model=* : Explicit model classes}
        {--dry-run : Validate and print SQL without applying}';

    protected $description = 'Sync Postgres publication for Zero models';

    public function __construct(
        private readonly ZeroPublicationSynchronizer $synchronizer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sql = $this->synchronizer->plan(
            explicitModels: $this->option('model'),
            forcedConnection: $this->option('connection'),
            publicationName: $this->option('name'),
            output: $this->output,
        );

        if ($this->option('dry-run')) {
            $this->line(implode(PHP_EOL, $sql));

            return self::SUCCESS;
        }

        $this->synchronizer->apply(
            sql: $sql,
            forcedConnection: $this->option('connection'),
            output: $this->output,
        );

        $this->info('Zero publication synced.');

        return self::SUCCESS;
    }
}
