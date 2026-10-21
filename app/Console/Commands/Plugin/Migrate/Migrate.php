<?php

namespace App\Console\Commands\Plugin\Migrate;

use App\Services\PluginManager;

class Migrate extends MigrationBase {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:migrate  {--plugin= : The name of the target plugin} {--step= : How many steps should be rolled back.} {--all : Rollback all migrations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run plugin migrations.';

    /**
     * 
     */
    protected function exec($plugin, $steps): void{
        app(PluginManager::class)->migrationService->run($plugin, $steps);
    }
}