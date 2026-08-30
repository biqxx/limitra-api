<?php

namespace App\Providers;

use App\Social\Channels\MetaChannel;
use App\Social\Channels\XChannel;
use App\Social\SocialChannelManager;
use Illuminate\Support\ServiceProvider;

class SocialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialChannelManager::class, function () {
            return new SocialChannelManager([
                new MetaChannel,
                new XChannel,
            ]);
        });
    }
}
