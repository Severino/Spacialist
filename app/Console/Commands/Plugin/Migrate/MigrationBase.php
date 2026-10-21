<?php

namespace App\Console\Commands\Plugin\Migrate;

use Illuminate\Console\Command;

use App\Plugin;


/**
 * Migrate and Rollback functions do work identical with identical parameters.
 * This base class keeps it consistent and only the exec function, description and 
 * signature have to be supplied by the child classes.
 */
abstract class MigrationBase extends Command {

    private function printFailure($msg, Plugin $plugin = null) {
        $this->warn($msg);
        if($plugin) {
            $this->call('plugin:migration', ['--plugin' => $plugin->name]);
        }
        return self::FAILURE;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        $pluginName = $this->option('plugin');
        $plugins = Plugin::where('name', $pluginName)->get();

        if($plugins->count() !== 1) {
            return $this->printFailure("Plugin could not be determined: $pluginName");
        }

        $plugin = $plugins->sole();
        $all = $this->option('all');
        $stepOption = intval($this->option('step'));

        if(!$all && !$stepOption) {
            return $this->printFailure('Run this command with either --all or --step=N.', $plugin);
        }

        if($all) {
            $steps = 0;
        } else {
            if($stepOption <= 0) {
                return $this->printFailure("Invalid step value: $stepOption", $plugin);
            }

            $steps = $stepOption;
        }

        $this->exec($plugin, $steps);
        $this->call('plugin:migration', ['--plugin' => $plugin->name]);
    }
    
    /**
     * Executes a specific command within the handle function of the MigrationBase context.
     * @param mixed $plugin
     * @param mixed $steps
     * @return void
     */
    abstract protected function exec($plugin, $steps): void;
}