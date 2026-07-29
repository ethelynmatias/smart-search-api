<?php

namespace App\Providers;

use App\Repositories\Contracts\LogRepositoryInterface;
use App\Repositories\Contracts\WebhookDetailRepositoryInterface;
use App\Repositories\LogRepository;
use App\Repositories\WebhookDetailRepository;
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
        $this->app->bind(WebhookDetailRepositoryInterface::class, WebhookDetailRepository::class);

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
