<?php

namespace OTGH\GHARM\CloudImage;

use App\Events\ImageBuildCancelling;
use App\Services\Builds\BuilderRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CloudImageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudImageBuilder::class);
        $this->app->singleton(CloudImageBuildCancellation::class);

        $this->app->afterResolving(BuilderRegistry::class, function (BuilderRegistry $registry): void {
            $registry->register($this->app->make(CloudImageBuilder::class));
        });
    }

    public function boot(): void
    {
        Event::listen(ImageBuildCancelling::class, CloudImageBuildCancellation::class);

        if ($this->app->runningInConsole()) {
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->call(fn (): int => $this->app->make(CloudImageGuestBuildReconciler::class)->reconcile())
                    ->name('cloudimage:reconcile-builds')
                    ->everyMinute()
                    ->withoutOverlapping(15);
            });
        }
    }
}
