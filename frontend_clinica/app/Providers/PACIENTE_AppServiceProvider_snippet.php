<?php
// === Agregar en app/Providers/AppServiceProvider.php ===
//
// use App\Contracts\PacienteApi;
// use App\Services\Paciente\MockPacienteApi;
// use App\Services\Paciente\QuarkusPacienteApi;
//
// public function register(): void
// {
//     $this->app->bind(PacienteApi::class, fn ($app) => config('clinic.patient_api.driver') === 'quarkus'
//         ? $app->make(QuarkusPacienteApi::class)
//         : $app->make(MockPacienteApi::class));
// }
