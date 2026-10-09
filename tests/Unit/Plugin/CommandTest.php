<?php
namespace Tests\Unit\Plugin;

use App\Models\Plugin\Command;
use App\Services\Plugin\CommandService;
use App\Services\Plugin\HookService;
use App\Plugin;
use App\Services\PluginManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\Support\PluginTemplate;
use Tests\Support\Templates\HookTemplate;
use Tests\Support\PluginGenerator;
use Tests\TestCase;

class CommandTest extends TestCase
{

    protected PluginGenerator $pluginGenerator;
    protected Plugin $plugin;

    public function setUp(): void
    {
        parent::setUp();
    }
    
    public function getTemplate(){
                $pluginTemplate = new PluginTemplate("CommandTest", "0.1.3");
        // "<commands src="/Path/To/Your/CommandsDirectory" />"
        $pluginTemplate
            ->addBasic()
            ->addXml("commands", null, [["src" => "commands"]])
            ->addFile("Commands/FooCommand.php", 
<<<FOO_COMMAND
<?php
    namespace App\Plugins\CommandTest\Commands;

    use Illuminate\Console\Command;

    class FooCommand extends Command {
        protected \$signature = 'plugin:ct:foo';

        protected \$description = 'Prints "foo" in the terminal.';
        
        public function handle(){
            \$this->line('foo');
        }
    }
FOO_COMMAND
            )->addFile("Commands/BarCommand.php", 
<<<BAR_COMMAND
<?php
    namespace App\Plugins\CommandTest\Commands;

    use Illuminate\Console\Command;

    class BarCommand extends Command {
        protected \$signature = 'plugin:ct:bar';

        protected \$description = 'Prints "bar" in the terminal.';
        
        public function handle(){
            \$this->line('bar');
        }
    }
BAR_COMMAND
            )->generate();
            
        return $pluginTemplate;
    }
    
    public function tearDown(): void
    {
        // $this->pluginGenerator->tearDown();
        parent::tearDown();
    }
    
    public function resetKernel(){
        // Simulate a fresh CLI boot: the kernel loads commands only once per process,
        // which happened before the plugin was installed.
        $this->app->forgetInstance(\Illuminate\Contracts\Console\Kernel::class);
        \Illuminate\Support\Facades\Artisan::clearResolvedInstance(\Illuminate\Contracts\Console\Kernel::class);
    }
    
    public function testWorkingWhenInstall() {
        PluginGenerator::with([$this->getTemplate()], function(){
            $this->resetKernel();
            
            $this->artisan('plugin:ct:foo')
                ->expectsOutput("foo")
                ->assertSuccessful();
            
            $this->artisan('plugin:ct:bar')
                ->expectsOutput("bar")
                ->assertSuccessful();
        }, true);
    }
    
    public function testNotWorkingWhenUninstalled() {
        PluginGenerator::with([$this->getTemplate()->uninstalled()], function(){
            $this->resetKernel();
            
            $this->assertThrows(function(){
                $this->artisan('plugin:ct:foo');
            }, CommandNotFoundException::class, "The command \"plugin:ct:foo\" does not exist.");
            
            $this->assertThrows(function(){
                $this->artisan('plugin:ct:bar');
            }, CommandNotFoundException::class, "The command \"plugin:ct:bar\" does not exist.");
        }, true);
    }
}
