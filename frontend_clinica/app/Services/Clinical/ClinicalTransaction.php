<?php

namespace App\Services\Clinical;

use App\Contracts\ClinicalActorProvider;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;

final class ClinicalTransaction
{
    public function __construct(
        private DatabaseManager $database,
        private ClinicalActorProvider $identity,
    ) {}

    /** @param Closure(Connection): mixed $operation */
    public function run(Closure $operation): mixed
    {
        $connection = $this->database->connection('clinical');
        // Un solo propietario de la transacción evita cambios de actor en savepoints.
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('La operación clínica requiere una transacción propia.');
        }

        try {
            if ($connection->scalar('SELECT public.actor_actual()') !== null) {
                throw new LogicException('La conexión contiene un contexto de actor ajeno.');
            }

            return $connection->transaction(function (Connection $db) use ($operation) {
                $actor = $this->identity->authenticatedUserCode();
                if (! preg_match('/^USU[0-9]+$/D', $actor)) {
                    throw new LogicException('La identidad clínica del servidor no es válida.');
                }
                $active = $db->table('usuarios as u')->join('roles as r', 'r.cod_rol', '=', 'u.cod_rol')
                    ->where('u.cod_usu', $actor)->where('u.estado', 'Activo')->where('r.estado', 'Activo')
                    ->first(['u.cod_usu']);
                if ($active === null) {
                    throw new LogicException('La identidad clínica no está habilitada.');
                }
                $db->selectOne("SELECT set_config('app.cod_usu', ?, true)", [$actor]);

                // La autorización de módulo/rol/propietario sigue siendo obligatoria en cada servicio.
                return $operation($db);
            }, 1); // No repetir automáticamente operaciones con efectos externos.
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }
}
