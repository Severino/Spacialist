<?php
namespace App\Services\Plugin;

use App\Events\AttributeValueDeleted;
use App\Events\AttributeValueSaved;
use App\Events\EntityCreated;
use App\Events\EntityDeleted;
use App\Events\EntityTypeAttributeRemoved;
use App\Models\Plugin\Listener;
use App\Plugin;
use App\Plugin\Directory as PluginDirectory;
use App\Plugin\Manifest as PluginManifest;
use App\Support\BootstrapCache;
use App\Support\Method;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Service that manages plugin event listeners. Listeners allow plugins to react
 * to core domain events. The event names are an allow-list, see `EVENTS`.
 *
 * Listeners with a higher order are executed later. The default order is 0.
 *
 * ```xml
 * <listeners>
 *     <listener on="attribute_value.saved" src="Listeners/WktSync@onSaved" order="0"/>
 * </listeners>
 * ```
 */
class ListenerService extends PluginService {
    use BootstrapCache;

    /**
     * Allow-list of event names that plugins can listen to, mapped to the core event class.
     */
    const EVENTS = [
        'attribute_value.saved' => AttributeValueSaved::class,
        'attribute_value.deleted' => AttributeValueDeleted::class,
        'entity.created' => EntityCreated::class,
        'entity.deleted' => EntityDeleted::class,
        'entity_type.attribute_removed' => EntityTypeAttributeRemoved::class,
    ];

    protected function fetch(): array {
        return Listener::with('plugin')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(function ($listener) {
                $pluginName = $listener->plugin?->name ?? null;
                return [
                    "id" => $listener->id,
                    "plugin_id" => $listener->plugin_id,
                    "plugin-name" => $pluginName,
                    "plugin-namespace" => $pluginName ? PluginDirectory::namespaceOf($pluginName) : null,
                    "on" => $listener->on,
                    "src" => $listener->src,
                    "order" => $listener->order,
                ];
            })->toArray();
    }

    protected function getCacheName(): string {
        return 'plugin-listeners';
    }

    public function install(Plugin $plugin, PluginManifest $manifest): void {
        $this->updateOrInstall($plugin);
    }

    public function update(Plugin $plugin, PluginManifest $manifest): void {
        $this->updateOrInstall($plugin);
    }

    public function uninstall(Plugin $plugin, PluginManifest $manifest): void {
        Listener::where('plugin_id', $plugin->id)->delete();
    }

    public function updateOrInstall(Plugin $plugin): void {
        $manifest = PluginManifest::fromPlugin($plugin);
        $definitions = $manifest->getTagNodes('listeners/listener') ?? [];

        DB::transaction(function () use ($definitions, $plugin) {
            Listener::where('plugin_id', $plugin->id)->delete();
            foreach($definitions as $xml) {
                if(isset($xml['attributes'])) {
                    $this->addListener($xml['attributes'], $plugin);
                }
            }
        });

        $this->cache();
    }

    public function addListener(array $definition, Plugin $plugin): void {
        foreach(['on', 'src'] as $field) {
            if(!isset($definition[$field]) || trim($definition[$field]) === '') {
                throw new \Exception("Listener is missing field: $field");
            }
        }

        $on = trim($definition['on']);
        $src = trim($definition['src']);

        if(!array_key_exists($on, self::EVENTS)) {
            throw new \Exception("Listener on unknown event '$on'. Allowed events are: " . implode(", ", array_keys(self::EVENTS)));
        }

        $method = Method::parseFromString($src);
        if(!$method->isValid()) {
            throw new \Exception("Listener 'src' field must be in the format 'class@method'. Given: '$src'");
        }

        $pluginNamespace = PluginDirectory::namespaceOf($plugin->name);
        if(!$method->exists($pluginNamespace)) {
            $fullClass = $method->expandNamespace($pluginNamespace);
            throw new \Exception("Listener src '$src' does not exist in plugin '" . $plugin->name . "', Class was resolved to: '$fullClass'");
        }

        $listener = new Listener();
        $listener->plugin_id = $plugin->id;
        $listener->on = $on;
        $listener->src = $src;
        $listener->order = intval($definition['order'] ?? 0);
        $listener->save();
    }

    /**
     * Registers all cached plugin listeners on the event dispatcher.
     * Should be called during application boot.
     */
    public function registerListeners(): void {
        if(!$this->cacheExists()) {
            return;
        }

        try {
            $listeners = $this->load();
        } catch(\Throwable $e) {
            Log::error("Could not load plugin listeners: " . $e->getMessage());
            return;
        }

        usort($listeners, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        foreach($listeners as $listener) {
            $eventClass = self::EVENTS[$listener['on']] ?? null;
            if($eventClass === null) {
                continue;
            }

            Event::listen($eventClass, function ($event) use ($listener) {
                try {
                    $method = Method::parseFromString($listener['src']);
                    $fullClass = $method->expandNamespace($listener['plugin-namespace']);
                    app($fullClass)->{$method->method}($event);
                } catch(\Throwable $e) {
                    Log::error("Error executing listener '" . $listener['src'] . "' for plugin '" . $listener['plugin-name'] . "': " . $e->getMessage());
                }
            });
        }
    }
}
