<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Kernel extends ConsoleKernel
{
    /**
     * The framework only auto-discovers app/Console/Commands and routes/console.php
     * when the kernel is its own class; keep that behaviour for this subclass.
     */
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    /**
     * Same as the framework implementation, but builds the guarded Artisan application.
     *
     * @return \Illuminate\Console\Application
     */
    protected function getArtisan()
    {
        if (is_null($this->artisan)) {
            $this->artisan = (new GuardedArtisan($this->app, $this->events, $this->app->version()))
                ->resolveCommands($this->commands)
                ->setContainerCommandLoader();

            if ($this->symfonyDispatcher instanceof EventDispatcher) {
                $this->artisan->setDispatcher($this->symfonyDispatcher);
                $this->artisan->setSignalsToDispatchEvent();
            }
        }

        return $this->artisan;
    }
}
