<?php

namespace App\Services\Plugin;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use App\Models\Plugin\Migration as PluginMigration;
use App\Plugin;
use App\Plugin\Directory as PluginDirectory;
use App\Plugin\Manifest as PluginManifest;

/**
 * Plugins can define Migrations by declaring their directory inside their manifest file.
 * All migrations from all files are loaded and executed in the order of their creation as defined
 * by their file name, which must follow the Laravel migration file naming convention (e.g. "2024_01_01_000000_create_users_table.php").
 *
 * LEGACY: The 'MyPlugin/Migration' directory is by default evaluated for migration files. This will be removed in a future version.
 *
 * ```xml
 *     <migrations src="Migration" />
 * ```
 */
class MigrationService extends PluginService {
    const LEGACY_MIGRATION_DIRECTORY = 'Migration';

    public function install(Plugin $plugin, PluginManifest $manifest): void {
        $this->run($plugin);
    }

    public function update(Plugin $plugin, PluginManifest $manifest): void {
        $this->run($plugin);
    }

    public function remove(Plugin $plugin, PluginManifest $manifest): void {
        $this->rollback($plugin);
    }

    public function run(Plugin $plugin, int $steps = 0): void {
        $this->exec($plugin, false, $steps);
    }

    public function rollback(Plugin $plugin, int $steps = 0): void {
        $this->exec($plugin, true, $steps);
    }

    protected function exec(Plugin $plugin, bool $rollback = false, int $steps = 0) {
        //Determine the next batch number
        $nextBatch = PluginMigration::getNextBatchNumber();
        $directory = $this->getMigrationDirectory($plugin);
        $pluginDir = PluginDirectory::fromPlugin($plugin);
        $absoluteDirectory = $pluginDir->getAbsolutePluginPath($directory);
        if(!file_exists($absoluteDirectory) || !is_dir($absoluteDirectory)) {

            //Legacy support: If the default migration directory does not exist, we assume there are no migrations to run and return early.
            if($directory === self::LEGACY_MIGRATION_DIRECTORY) {
                return;
            } else {
                throw new Exception("Migration directory not found: '$absoluteDirectory'");
            }
        }

        $migrations = [];
        if($rollback) {
            $migrations = $this->getRollbackMigrations($plugin);
        } else {
            $migrations = $this->getPendingMigrations($plugin);
        }

        // When no steps are supplied take all migrations and if steps are supplied make sure its atmost the migrations amount.
        $actualSteps = ($steps === 0) ? count($migrations) : min($steps, count($migrations));
        for($step = 0; $step < $actualSteps; $step++) {
            
            $migration = $migrations[$step];
            $migrationInstance = $this->getMigrationClassName($plugin, $absoluteDirectory, $migration);
            try {
                call_user_func([$migrationInstance, $rollback ? 'rollback' : 'migrate']);
                if($rollback) {
                    //Remove the record of the migration
                    PluginMigration::where('plugin_id', $plugin->id)
                        ->where('migration', $migration)
                        ->delete();
                } else {
                    //Record the migration as run
                    PluginMigration::create([
                        'plugin_id' => $plugin->id,
                        'migration' => $migration,
                        'batch' => $nextBatch
                    ]);
                }
            } catch(\Exception $e) {
                throw $e;
            }
        }
    }

    /**
     *
     *
     * @param Plugin $plugin
     * @throws \Exception
     * @return string|null
     */
    public function getManifestMigration(Plugin $plugin): string|null {
        $manifest = PluginManifest::fromPlugin($plugin);
        $xmlNodes = $manifest->getTagNodes('migrations');

        if(!$xmlNodes || count($xmlNodes) === 0) {
            return null;
        }

        if(count($xmlNodes) > 1) {
            throw new \Exception("Plugin {$plugin->name} has multiple <migrations> tags in its manifest, but only one is allowed.");
        }

        $attributes = $xmlNodes[0]['attributes'] ?? [];
        $src = $attributes['src'] ?? trim((string) ($xmlNodes[0]['text'] ?? ''));

        return $src !== '' ? $src : null;
    }

    /**
     * Adds a migration entry to the database without running it.
     * This is required for legacy plugins that were not yet using the
     * plugin migration system and ran their migrations without supervision.
     *
     * @param mixed $migrationName
     * @param Plugin $plugin
     * @return void
     */
    public function set($migrationName, Plugin $plugin): void {
        //Determine the next batch number
        $nextBatch = PluginMigration::getNextBatchNumber();
        //Record the migration as run
        PluginMigration::create([
            'plugin_id' => $plugin->id,
            'migration' => $migrationName,
            'batch' => $nextBatch
        ]);
    }

    /**
     * Gets the migration directory for the plugin. If the manifest defines a migration directory,
     * it will be returned, otherwise the legacy migration directory will be used.
     *
     * @param Plugin $plugin
     * @return string - The migration directory path defined in the manifest or the legacy migration directory if not defined in the manifest.
     */
    public function getMigrationDirectory(Plugin $plugin): string {
        $migrationDirectory = $this->getManifestMigration($plugin);

        if(!$migrationDirectory) {
            $migrationDirectory = self::LEGACY_MIGRATION_DIRECTORY;
        }
        return $migrationDirectory;
    }

    /**
     * Get's the absolute path to the migration directory of the plugin.
     *
     *
     * @param Plugin $plugin
     * @return string
     */
    public function getAbsoluteMigrationDirectory(Plugin $plugin): string {
        $migrationDirectory = $this->getMigrationDirectory($plugin);
        return PluginDirectory::fromPlugin($plugin)->getAbsolutePluginPath($migrationDirectory);
    }

    function getMigrationList(string $migrationDirectory): array {
        if(file_exists($migrationDirectory) && is_dir($migrationDirectory)) {
            $migrations = collect(File::files($migrationDirectory))->map(function ($f) {
                return $f->getFilename();
            });

            $migrations = $migrations->filter(function ($f) {
                preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)\.php$/', $f, $matches);
                return count($matches) == 3;
            });

            $migrations = $migrations->sort();
            return $migrations->values()->toArray();
        } else {
            return [];
        }
    }

    function resolveDirectoryNamspace(Plugin $plugin, string $migrationDirectory): string {
        $normalizedPath = str_replace('/', '\\', Str::after($migrationDirectory, PluginDirectory::getPathByName($plugin->name)));
        // Transform the path into a namespace e.g. app/plugins/my-plugin to App\Plugins\MyPlugin
        $namespace = collect(explode('\\', $normalizedPath))
            ->filter()
            ->map(fn($part) => Str::studly(str_replace('-', '_', $part)))
            ->implode('\\');

        return $namespace;
    }

    /**
     * Get path to the plugin's migration directory (./Migration)
     *
     * @param string $pluginName - The plugin to get the migration path for
     * @param mixed $migrationFile - optional name of specific migration file, e.g. "2024_01_01_000000_create_users_table.php"
     * @return string - The path to the plugin's migration directory or to a specific migration file if $migrationFile is provided
     */
    public static function getLegacyMigrationPath(string $pluginName, ?string $migrationFile = null): string {
        $pluginDir = new PluginDirectory($pluginName);
        $path = $pluginDir->getPluginPath(self::LEGACY_MIGRATION_DIRECTORY);
        if($migrationFile) {
            $path .= '/' . $migrationFile;
        }
        return $path;
    }

    /**
     * Validates if the migration file does match the Laravel migration file naming convention.
     * If it's a valid migration, the migration class will be returned, otherwise an exception will be thrown.
     *
     * @param Plugin $plugin - The plugin the migration belongs to
     * @param string $directory - Absolute path to the migration directory.
     * @param string $migrationFile - The migration file name, e.g. "2024_01_01_000000_create_users_table.php"
     * @throws \Exception throws an exeption if the migration has in incompatible name
     * @return mixed - LEGACY - This should return an instance of "Illuminate\Database\Migrations\Migration" currently plugins don't comply with that,
     *                 this will be changed in a future version.
     */
    function getMigrationClassName(Plugin $plugin, string $directory, string $migrationFile) {
        preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)\.php$/', $migrationFile, $matches);
        if(count($matches) != 3) {
            throw new \Exception("Invalid migration file name: $migrationFile");
        }
        $migrationPath = Str::finish($directory, '/') . $migrationFile;
        // require_once: migration files may be called multiple times within the same process,
        // which would otherwise fatal with "Cannot redeclare class" (e.g. in tests).
        $instantiatedMigration = require_once($migrationPath);

        if(is_object($instantiatedMigration)) {
            return $instantiatedMigration;
        } else {
            $className = Str::studly($matches[2]);
            $pluginNamespacePath = $this->resolveDirectoryNamspace($plugin, $directory);
            $prefixedClassName = "App\\Plugins\\$plugin->name\\$pluginNamespacePath\\$className";
            return new $prefixedClassName();
        }
    }

    /**
     * Get's all plugin migrations in the database and compares them with all file
     * migrations and retuns all migrations that are not yet in the database.
     *
     * @param Plugin $plugin - The plugin to check for missing migrations
     * @return array<string, array['name' => string, 'ran' => bool]> - A list of migration file names that are missing in the database (or that can be rolled back if $rollback is true)
     */
    function getMigrationStatus(Plugin $plugin): array {
        $ranMigrations = PluginMigration::where('plugin_id', $plugin->id)
            ->pluck('migration')
            ->toArray();

        $absoluteDirectory = $this->getAbsoluteMigrationDirectory($plugin);
        $allMigrations = $this->getMigrationList($absoluteDirectory);

        return array_map(function ($migration) use ($ranMigrations) {
            $ran = in_array($migration, $ranMigrations);
            return [
                'name' => $migration,
                'ran' => $ran
            ];
        }, $allMigrations);
    }

    /**
     * Get's all plugin migrations in the database and compares them with all file
     * migrations and retuns all migrations that are not yet in the database.
     *
     * @param Plugin $plugin - The plugin to check for missing migrations
     * @return array - A list of migration file names that are missing in the database (or that can be rolled back if $rollback is true)
     */
    function getPendingMigrations(Plugin $plugin): array {
        // array_filter will the values with their keys and leaves it in a 'broken' state, this is fixed by array_values.
        $pendingMigrations = array_values(array_filter($this->getMigrationStatus($plugin), fn($item) => $item['ran'] === false));
        return array_map(fn($item) => $item["name"], $pendingMigrations);
    }

    /**
     * Get's all plugin migrations in the database and compares them with all file
     * migrations and retuns all migrations that are not yet in the database.
     *
     * @param Plugin $plugin - The plugin to check for missing migrations
     * @return array - A list of migration file names that are missing in the database (or that can be rolled back if $rollback is true)
     */
    function getRollbackMigrations(Plugin $plugin): array {
        // array_filter will the values with their keys and leaves it in a 'broken' state, this is fixed by array_values.
        $ranMigrations = array_values(array_filter($this->getMigrationStatus($plugin), fn($item) => $item['ran'] === true));
        return array_reverse(array_map(fn($item) => $item["name"], $ranMigrations));
    }

}