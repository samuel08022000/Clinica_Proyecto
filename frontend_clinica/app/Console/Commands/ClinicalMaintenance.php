<?php

namespace App\Console\Commands;

use App\Services\Clinical\ClinicalMaintenanceService;
use Illuminate\Console\Command;
use Throwable;

final class ClinicalMaintenance extends Command
{
    protected $signature = 'clinical:maintenance {task : slots o reception}';

    protected $description = 'Ejecuta un trabajo clínico idempotente de PostgreSQL';

    public function handle(ClinicalMaintenanceService $maintenance): int
    {
        try {
            $result = match ($this->argument('task')) {
                'slots' => ['slots_procesados' => $maintenance->generateSlots()],
                'reception' => $maintenance->closeReception(),
                default => null,
            };
            if ($result === null) {
                $this->error('Tarea desconocida.');

                return self::INVALID;
            }
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se pudo completar el mantenimiento clínico. Revise el registro privado.');

            return self::FAILURE;
        }
    }
}
