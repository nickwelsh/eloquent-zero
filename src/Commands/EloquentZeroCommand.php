<?php

namespace NickWelsh\EloquentZero\Commands;

use Illuminate\Console\Command;
use NickWelsh\EloquentZero\Generators\ZeroSchemaGenerator;

class EloquentZeroCommand extends Command
{
    protected $signature = 'generate:zero-schema
        {--path= : Output path override}
        {--connection= : Force a single connection}
        {--model=* : Explicit model classes}
        {--format : Reserved for future formatting support}';

    protected $description = 'Generate a Zero schema from Eloquent models';

    public function __construct(
        private readonly ZeroSchemaGenerator $generator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->generator->generate(
            outputPath: $this->option('path') ?: config('eloquent-zero.output_path'),
            explicitModels: $this->option('model'),
            forcedConnection: $this->option('connection'),
            output: $this->output,
        );

        if ($result->wasWritten) {
            $this->info("Generated Zero schema at {$result->outputPath}");
        } else {
            $this->line("Zero schema unchanged at {$result->outputPath}");
        }

        return self::SUCCESS;
    }
}
