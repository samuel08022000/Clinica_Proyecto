<?php

namespace App\Providers;

use App\Contracts\ClinicalActorProvider;
use App\Contracts\GoogleIdentityProvider;
use App\Contracts\PatientAppointmentManager;
use App\Contracts\PatientAvailabilityProvider;
use App\Contracts\PatientProfileProvider;
use App\Services\Auth\UnavailableGoogleIdentityProvider;
use App\Services\Clinical\LaravelClinicalActorProvider;
use App\Services\Paciente\PatientAppointmentService;
use App\Services\Paciente\PatientAvailabilityService;
use App\Services\Paciente\PatientProfileService;
use Illuminate\Support\ServiceProvider;
use App\Services\Auth\GoogleOidcIdentityProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ClinicalActorProvider::class, LaravelClinicalActorProvider::class);
       $this->app->bind(GoogleIdentityProvider::class, GoogleOidcIdentityProvider::class);
        $this->app->bind(PatientProfileProvider::class, PatientProfileService::class);
        $this->app->bind(PatientAvailabilityProvider::class, PatientAvailabilityService::class);
        $this->app->bind(PatientAppointmentManager::class, PatientAppointmentService::class);
    }

    public function boot(): void
    {
    }
}
