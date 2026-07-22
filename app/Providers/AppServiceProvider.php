<?php

namespace App\Providers;

use App\Repositories\Contracts\LogRepositoryInterface;
use App\Repositories\LogRepository;
use App\Services\LogService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogRepositoryInterface::class, LogRepository::class);

        // Scoped so every log within the same request/process shares one log_group_id
        $this->app->scoped(LogService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
