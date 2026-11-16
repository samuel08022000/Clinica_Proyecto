<?php

namespace App\Repositories\Clinical;

use App\Services\Clinical\ClinicalSqlException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/** Lecturas mínimas de infraestructura; no sustituye controladores ni permisos de dominio. */
final class ClinicalCatalog
{
    public function __construct(private DatabaseManager $database) {}

    public function role(string $code): ?object
    {
        try {
            return $this->database->connection('clinical')->selectOne(
                'SELECT cod_rol, nombre_rol, estado FROM public.roles WHERE cod_rol = ?', [$code],
            );
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }

    public function clinicalDate(): string
    {
        try {
            return (string) $this->database->connection('clinical')->scalar('SELECT public.fecha_clinica()');
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }
}
