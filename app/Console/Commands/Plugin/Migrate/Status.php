<?php

namespace App\Console\Commands\Plugin\Migrate;

use Illuminate\Console\Command;

use App\Plugin;
use App\Services\PluginManager;

class Status extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:migration {--plugin= : The name of the target plugin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the migration status for a plugin.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(): int {
        $plugin = Plugin::where('name', $this->option('plugin'))->first();
        if($plugin === null) {
            $this->error('The specified plugin was not found.');

            return self::FAILURE;
        }
        
        $this->printMigrationTable($plugin);
        return self::SUCCESS;
    }

    public function printMigrationTable(Plugin $plugin) {
        $migrationList = app(PluginManager::class)->migrationService->getMigrationStatus($plugin);
        
        $rows = array_map(
            fn(array $migration): array => [
                $migration['name'],
                $migration['ran'] ? 'Ran' : 'Pending',
            ],
            $migrationList
        );

        $this->table(['Migration', 'Status'], $rows);
    }
}