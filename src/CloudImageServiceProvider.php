<?php

namespace OTGH\GHARM\CloudImage;

use App\Services\Builds\BuilderRegistry;
use Illuminate\Support\ServiceProvider;

class CloudImageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudImageBuilder::class);

        $this->app->afterResolving(BuilderRegistry::class, function (BuilderRegistry $registry): void {
            $registry->register($this->app->make(CloudImageBuilder::class));
        });
    }
}
