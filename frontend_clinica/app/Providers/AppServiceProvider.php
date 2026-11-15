<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\PacienteApi;
use App\Services\Paciente\MockPacienteApi;
use App\Services\Paciente\QuarkusPacienteApi;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
   public function register(): void
{
    $this->app->bind(PacienteApi::class, fn ($app) => config('clinic.patient_api.driver') === 'quarkus'
        ? $app->make(QuarkusPacienteApi::class)
        : $app->make(MockPacienteApi::class));
}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
