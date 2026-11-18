<?php

namespace Tests\Postgres;

use App\Contracts\ClinicalActorProvider;
use App\Repositories\Clinical\ClinicalCatalog;
use App\Services\Clinical\ClinicalSqlException;
use App\Services\Clinical\ClinicalTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/** PostgreSQL real, opt-in. No RefreshDatabase, migrate:fresh ni escrituras en la base principal. */
final class ClinicalInfrastructureTest extends TestCase
{
    private PDO $owner;

    private Connection $clinical;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICAL_PG_TESTS') !== '1') {
            $this->markTestSkipped('Requiere Invoke-Laravel.ps1 -TestDatabase y PostgreSQL 16 aislado.');
        }
        $this->assertTrue(extension_loaded('pdo_pgsql'));
        $config = config('database.connections.clinical');
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame('35443', (string) $config['port']);
        $this->assertSame('clinica_laravel_phase1_tests', $config['database']);
        $this->owner = new PDO('pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests', 'clinica_owner', getenv('CLINICAL_OWNER_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('laravel-phase1-isolated', $this->owner->query("SELECT shobj_description(oid,'pg_database') FROM pg_database WHERE datname=current_database()")->fetchColumn());
        $this->clinical = app(DatabaseManager::class)->connection('clinical');
    }

    public function test_complete_schema_matches_original_sql_and_v10(): void
    {
        $expectedTables = explode(' ', 'roles usuarios pacientes secretarias medicos especialidades medico_especialidades horarios bloqueos_agenda slots_agenda citas derivaciones incidencias_sanciones analisis verificaciones_otp tokens_recuperacion configuracion_seguridad notificaciones bitacora usuarios_identidades_externas bloqueos_citas_afectadas');
        sort($expectedTables);
        $this->assertSame($expectedTables, $this->owner->query("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename <> 'flyway_schema_history' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['v_agenda_disponibilidad', 'v_historial_citas'], $this->owner->query("SELECT viewname FROM pg_views WHERE schemaname='public' ORDER BY viewname")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['B9__instalacion_sin_datos_demo.sql', 'V10__derivaciones_recepcion_enlazadas.sql', 'R__verificacion_email_otp.sql'], $this->owner->query('SELECT script FROM flyway_schema_history WHERE success ORDER BY installed_rank')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(['btree_gist', 'pgcrypto'], $this->owner->query("SELECT extname FROM pg_extension WHERE extname IN ('btree_gist','pgcrypto') ORDER BY extname")->fetchAll(PDO::FETCH_COLUMN));
        $definitions = [];
        $triggers = [];
        foreach (['B9__instalacion_sin_datos_demo.sql', 'V10__derivaciones_recepcion_enlazadas.sql', 'R__verificacion_email_otp.sql', '../upgrade/V11__permisos_notificaciones.sql'] as $file) {
            $sql = str_replace("\r\n", "\n", file_get_contents(base_path('../database_clinica/migration/'.$file)));
            preg_match_all('/CREATE(?: OR REPLACE)? FUNCTION public\.(\w+)\s*\(.*?\bAS \$\$(.*?)\$\$/si', $sql, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $definitions[$match[1]] = $match[2];
            }
            preg_match_all('/CREATE TRIGGER (\w+)/i', $sql, $matches);
            $triggers = array_merge($triggers, $matches[1]);
        }
        $this->assertCount(42, $definitions);
        foreach ($definitions as $name => $body) {
            $query = $this->owner->prepare("SELECT prosrc FROM pg_proc WHERE pronamespace='public'::regnamespace AND proname=?");
            $query->execute([$name]);
            $this->assertSame($body, str_replace("\r\n", "\n", $query->fetchColumn()), $name.' debe conservar la última definición SQL original');
        }
        $triggers = array_values(array_unique($triggers));
        sort($triggers);
        $actualTriggers = $this->owner->query("SELECT tgname FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND NOT t.tgisinternal ORDER BY tgname")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(32, $actualTriggers);
        $this->assertSame($triggers, $actualTriggers);
        $this->assertSame(0, (int) $this->owner->query("SELECT count(*) FROM pg_constraint WHERE connamespace='public'::regnamespace AND NOT convalidated")->fetchColumn());
        $this->assertSame(0, (int) $this->owner->query("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE c.relnamespace='public'::regnamespace AND t.tgenabled='D'")->fetchColumn());
        $this->assertSame(2, (int) $this->owner->query("SELECT count(*) FROM pg_constraint WHERE conname IN ('ex_citas_medico_sin_solape','ex_citas_paciente_sin_solape') AND contype='x'")->fetchColumn());
        $this->assertSame(32, (int) $this->owner->query("SELECT octet_length(secreto) FROM configuracion_seguridad WHERE nombre='QR_HMAC'")->fetchColumn());
        $this->assertSame('ALWAYS', $this->owner->query("SELECT is_generated FROM information_schema.columns WHERE table_schema='public' AND table_name='usuarios' AND column_name='cod_usu'")->fetchColumn());
        $this->assertSame('false', $this->owner->query("SELECT column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='pacientes' AND column_name='email_verificado'")->fetchColumn());
    }

    public function test_laravel_reads_views_functions_and_bound_parameters_with_limited_permissions(): void
    {
        $this->assertSame('sqlite', config('database.default'), 'No se reemplaza la infraestructura de las pruebas existentes');
        $this->assertSame('clinica_app', $this->clinical->scalar('SELECT current_user'));
        $this->assertStringStartsWith('16.', $this->clinical->scalar("SELECT current_setting('server_version')"));
        $this->assertSame('America/La_Paz', $this->clinical->scalar("SELECT current_setting('TimeZone')"));
        $catalog = app(ClinicalCatalog::class);
        $this->assertSame('ROL004', $catalog->role('ROL004')->cod_rol);
        $this->assertNull($catalog->role("ROL004' OR 1=1 --"));
        $this->assertSame($this->clinical->scalar("SELECT (now() AT TIME ZONE 'America/La_Paz')::date"), $catalog->clinicalDate());
        $this->assertLessThanOrEqual(1, count($this->clinical->select('SELECT * FROM public.v_agenda_disponibilidad LIMIT 1')));
        $this->assertLessThanOrEqual(1, count($this->clinical->select('SELECT * FROM public.v_historial_citas LIMIT 1')));
        $this->assertFalse($this->clinical->scalar('SELECT rolsuper OR rolcreatedb OR rolcreaterole FROM pg_roles WHERE rolname=current_user'));
        $this->assertFalse($this->clinical->scalar("SELECT has_table_privilege(current_user,'public.configuracion_seguridad','SELECT')"));
        $this->assertFalse($this->clinical->scalar("SELECT has_table_privilege(current_user,'public.verificaciones_otp','SELECT')"));
        $this->assertTrue($this->clinical->scalar("SELECT has_function_privilege(current_user,'public.crear_otp_email(text)','EXECUTE')"));
        $this->assertFalse($this->clinical->scalar("SELECT has_schema_privilege(current_user,'public','CREATE')"));
        $this->assertFalse($this->clinical->scalar("SELECT has_table_privilege(current_user,'public.bitacora','INSERT')"));
    }

    public function test_actor_commit_rollback_and_isolation_on_the_same_connection(): void
    {
        [$firstActor, $specialty] = $this->fixture();
        [$secondActor] = $this->fixture();
        $transaction = $this->transactionFor($firstActor);
        $result = $transaction->run(function (Connection $db) use ($specialty, $firstActor) {
            $this->assertSame($firstActor, $db->scalar('SELECT public.actor_actual()'));
            $db->update('UPDATE especialidades SET descripcion=? WHERE cod_esp=?', ['Prueba confirmada', $specialty]);

            return 'committed';
        });
        $this->assertSame('committed', $result);
        $this->assertNull($this->clinical->scalar('SELECT public.actor_actual()'));
        $audit = $this->clinical->selectOne("SELECT cod_usu, datos_nuevos->>'descripcion' AS description FROM bitacora WHERE nombre_tabla='especialidades' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$specialty]);
        $this->assertSame($firstActor, $audit->cod_usu);
        $this->assertSame('Prueba confirmada', $audit->description);
        $count = $this->clinical->scalar('SELECT count(*) FROM bitacora');
        try {
            $transaction->run(function (Connection $db) use ($specialty) {
                $db->update('UPDATE especialidades SET descripcion=? WHERE cod_esp=?', ['Debe revertirse', $specialty]);
                throw new RuntimeException('rollback-controlado');
            });
            $this->fail('No se propagó rollback');
        } catch (RuntimeException $error) {
            $this->assertSame('rollback-controlado', $error->getMessage());
        }
        $this->assertSame('Prueba confirmada', $this->clinical->scalar('SELECT descripcion FROM especialidades WHERE cod_esp=?', [$specialty]));
        $this->assertSame($count, $this->clinical->scalar('SELECT count(*) FROM bitacora'));
        $this->assertNull($this->clinical->scalar('SELECT public.actor_actual()'));
        $this->transactionFor($secondActor)->run(fn (Connection $db) => $db->update('UPDATE especialidades SET descripcion=? WHERE cod_esp=?', ['Segundo actor', $specialty]));
        $this->assertSame($secondActor, $this->clinical->scalar("SELECT cod_usu FROM bitacora WHERE nombre_tabla='especialidades' AND clave_registro=? ORDER BY secuencial DESC LIMIT 1", [$specialty]));
        $this->assertNull($this->clinical->scalar('SELECT public.actor_actual()'));
        try {
            $transaction->run(fn (Connection $db) => $db->selectOne('SELECT 1 / 0'));
            $this->fail('Se esperaba un error PostgreSQL real');
        } catch (ClinicalSqlException $error) {
            $this->assertSame('22012', $error->sqlState);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('SELECT', $error->getMessage());
        }
        $this->assertSame(0, $this->clinical->transactionLevel());
        $this->assertNull($this->clinical->scalar('SELECT public.actor_actual()'));
    }

    public function test_unconfigured_invalid_inactive_and_nested_actors_are_rejected(): void
    {
        $this->assertRejected(app(ClinicalTransaction::class));
        $this->assertRejected($this->transactionFor('USU999999999999'));
        $this->assertRejected($this->transactionFor("USU1'; SELECT 1; --"));
        [$actor] = $this->fixture();
        $statement = $this->owner->prepare("UPDATE usuarios SET estado='Inactivo' WHERE cod_usu=?");
        $statement->execute([$actor]);
        $this->assertRejected($this->transactionFor($actor));
        $this->clinical->beginTransaction();
        try {
            $this->assertRejected($this->transactionFor($actor));
            $this->assertSame(1, $this->clinical->transactionLevel());
        } finally {
            $this->clinical->rollBack();
        }
        $this->assertNull($this->clinical->scalar('SELECT public.actor_actual()'));
    }

    public function test_foreign_keys_checks_and_generated_codes_are_enforced_by_postgresql(): void
    {
        $cases = [
            ["INSERT INTO secretarias(cod_usu) VALUES ('USU999999999999')", '23503'],
            ["INSERT INTO roles(cod_rol,nombre_rol,estado) VALUES ('BAD','Prueba inválida','Otro')", '23514'],
            ["INSERT INTO roles(cod_rol,nombre_rol) VALUES ('ROL001','Duplicado')", '23505'],
            ["INSERT INTO usuarios(cod_usu,nombre_completo,email,cod_rol) VALUES ('USUFORGED','Prueba sintética','generated@example.invalid','ROL004')", '428C9'],
        ];
        foreach ($cases as [$sql, $expected]) {
            $this->owner->beginTransaction();
            try {
                $this->owner->exec($sql);
                $this->fail('PostgreSQL aceptó una violación de integridad');
            } catch (PDOException $error) {
                $this->assertSame($expected, $error->getCode());
            } finally {
                $this->owner->rollBack();
            }
        }
    }

    private function assertRejected(ClinicalTransaction $transaction): void
    {
        $called = false;
        try {
            $transaction->run(function () use (&$called) {
                $called = true;
            });
            $this->fail('Se aceptó una identidad no habilitada');
        } catch (LogicException) {
            $this->assertFalse($called);
        }
    }

    private function transactionFor(string $actor): ClinicalTransaction
    {
        // Adaptador exclusivo del test: no está registrado en la aplicación ni recibe HTTP.
        $identity = new class($actor) implements ClinicalActorProvider
        {
            public function __construct(private string $actor) {}

            public function authenticatedUserCode(): string
            {
                return $this->actor;
            }
        };

        return new ClinicalTransaction(app(DatabaseManager::class), $identity);
    }

    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $statement = $this->owner->prepare("INSERT INTO usuarios(nombre_completo,email,cod_rol) VALUES ('Identidad sintética fase uno',?,'ROL004') RETURNING cod_usu");
        $statement->execute(['phase1-'.$suffix.'@example.invalid']);
        $actor = $statement->fetchColumn();
        $statement = $this->owner->prepare('INSERT INTO especialidades(nombre_especialidad,descripcion,duracion_consulta_min) VALUES (?,?,30) RETURNING cod_esp');
        $statement->execute(['Prueba aislada '.$suffix, 'Original']);
        $specialty = $statement->fetchColumn();
        $this->assertMatchesRegularExpression('/^USU\d+$/', $actor);
        $this->assertMatchesRegularExpression('/^ESP\d+$/', $specialty);

        return [$actor, $specialty];
    }
}
