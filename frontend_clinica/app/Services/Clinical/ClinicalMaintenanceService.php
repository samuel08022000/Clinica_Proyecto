<?php

namespace App\Services\Clinical;

use Illuminate\Database\DatabaseManager;
use LogicException;

/** Trabajos idempotentes de PostgreSQL; no simulan una identidad humana. */
final class ClinicalMaintenanceService
{
    public function __construct(private DatabaseManager $database) {}

    public function generateSlots(): int
    {
        return $this->run(fn ($db) => (int) $db->scalar('SELECT generar_slots_agenda(fecha_clinica(),fecha_clinica()+14)'));
    }

    public function closeReception(): array
    {
        return $this->run(fn ($db) => [
            'inasistencias' => (int) $db->scalar('SELECT marcar_inasistencias_vencidas()'),
            'sanciones_desbloqueadas' => (int) $db->scalar('SELECT desbloquear_sanciones_vencidas()'),
        ]);
    }

    private function run(callable $operation): mixed
    {
        $db = $this->database->connection('clinical');
        if ($db->transactionLevel() !== 0 || $db->scalar('SELECT actor_actual()') !== null) {
            throw new LogicException('El trabajo clínico requiere una conexión sin actor ni transacción previa.');
        }

        return $db->transaction(function ($connection) use ($operation) {
            $connection->selectOne("SELECT set_config('app.cod_usu','',true)");

            return $operation($connection);
        });
    }
}
