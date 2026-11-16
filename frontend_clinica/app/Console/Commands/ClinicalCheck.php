<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class ClinicalCheck extends Command
{
    protected $signature = 'clinical:check';

    protected $description = 'Comprueba en solo lectura la conexión PostgreSQL clínica';

    public function handle(DatabaseManager $database): int
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->error('Falta pdo_pgsql.');

            return self::FAILURE;
        }
        try {
            $db = $database->connection('clinical');
            $info = $db->selectOne("SELECT current_database() AS database, current_user AS role, current_setting('server_version') AS version, current_setting('TimeZone') AS timezone, public.fecha_clinica() AS clinical_date");
            $tables = (int) $db->scalar("SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename <> 'flyway_schema_history'");
            $views = (int) $db->scalar("SELECT count(*) FROM pg_views WHERE schemaname='public'");
            $this->line(json_encode(['pdo_pgsql' => true, 'connection' => $info, 'domain_tables' => $tables, 'views' => $views], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $tables === 21 && $views === 2 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            // No imprimir QueryException: puede contener SQL, bindings o configuración privada.
            $this->error('No se pudo verificar la conexión clínica. Revise el entorno privado y PostgreSQL.');

            return self::FAILURE;
        }
    }
}
