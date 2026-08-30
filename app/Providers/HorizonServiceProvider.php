<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Schedule::command('horizon:snapshot')
            ->everyFiveMinutes()
            ->onOneServer();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (): bool => true);
    }
}
