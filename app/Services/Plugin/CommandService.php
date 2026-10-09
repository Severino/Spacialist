<?php
namespace App\Services\Plugin;

use App\Exceptions\PluginLifecycleException;
use App\Models\Plugin\Command;
use App\Plugin;
use App\Plugin\PluginDirectory;
use App\Plugin\PluginManifest;
use App\Support\BootstrapCache;

/**
 * Adds the capability for plugins to register custom attributes.
 * 
 * '''xml
 * ...
 *    <commands src="/Path/To/Your/CommandsDirectory" />
 * ...
 * '''
 */
class CommandService extends PluginService {

    use BootstrapCache;
    
    public function getCacheName(): string
    {
        return 'plugin-commands';
    }
    
    protected function fetch(): array
    {
        $commands = Command::with('plugin')->get()->toArray();            
        return array_map(function($command) {
            return [
                'id' => $command['id'],
                'plugin_id' => $command['plugin_id'],
                'plugin_name' => $command['plugin']['name'] ?? null,
                'src' => $command['src'],
                'created_at' => $command['created_at'],
                'updated_at' => $command['updated_at'],
            ];
        }, $commands);
    }
    
    public function getFromManifest(Plugin $plugin, PluginManifest $manifest): array {
        $xmlNodes = $manifest->getTagNodes('commands');
        
        if(empty($xmlNodes)) {
            return [];
        }
        
        if(count($xmlNodes) > 1) {
            throw new PluginLifecycleException($plugin, "Multiple <commands> tags found in plugin manifest of {$manifest->getName()}. Only one is allowed.");
        }
        info($xmlNodes);
        $commands = [];
        foreach($xmlNodes as $xmlNode) {

            if(!isset($xmlNode['attributes']) || !isset($xmlNode['attributes']['src'])) {
                throw new PluginLifecycleException($plugin, "Invalid command declaration in plugin manifest of {$manifest->getName()}. Missing 'src' attribute.");             
            }
        
            $src = (string) $xmlNode['attributes']['src'];
            
            $commands[] = [
                'src' => $src,
            ];
        }
    
        return $commands;
    }
    
    public function install(Plugin $plugin, PluginManifest $manifest): void {
        $items = $this->getFromManifest($plugin, $manifest);
        if(empty($items)) {
            return;
        }
        
        if(!isset($items[0]['src'])) {
            throw new PluginLifecycleException($plugin, "Invalid command declaration in plugin manifest of {$manifest->getName()}. Missing 'src' attribute.");             
        }
        
        $directoryRelative = $items[0]['src'];
        $pluginDirectory = PluginDirectory::fromPlugin($plugin);
        $pluginProjectPath = $pluginDirectory->getPluginPath($directoryRelative);
            
        if(!is_dir($pluginDirectory->getAbsolutePluginPath($directoryRelative))) {
            throw new PluginLifecycleException($plugin, "Command directory at '{$pluginProjectPath}' not found for plugin '{$manifest->getName()}'.");
        }
        
        Command::create([
            'plugin_id' => $plugin->id,
            'src' => $pluginProjectPath,
        ]);
        
        $this->cache();
    }
    
    public function update(Plugin $plugin, PluginManifest $manifest): void {
        $this->uninstall($plugin, $manifest);
        $this->install($plugin, $manifest);
    }
    
    public function uninstall(Plugin $plugin, PluginManifest $manifest): void {
        Command::where('plugin_id', $plugin->id)->delete();
        $this->cache();
    }
}
