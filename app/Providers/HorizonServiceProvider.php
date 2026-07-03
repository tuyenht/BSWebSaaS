<?php

namespace App\Providers;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    #[\Override]
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    #[\Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            /** @var list<string> $authorizedEmails */
            $authorizedEmails = [
                // Add operator emails allowed to view Horizon in non-local envs.
            ];

            return $user instanceof User && in_array($user->email, $authorizedEmails, true);
        });
    }
}
