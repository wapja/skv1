<?php

namespace App\Providers;

use App\Models\Organisation;
use App\Observers\OrganisationObserver;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment(['local', 'staging', 'production'])) {
            URL::forceScheme('https');
        }

        Gate::policy(Role::class, RolePolicy::class);

        Organisation::observe(OrganisationObserver::class);
    }
}
