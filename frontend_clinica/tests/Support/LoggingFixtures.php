<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/** Dobles deterministas para probar observabilidad; no abren PDO ni validan SQL de producción. */
final class RecordingClinicalLogger extends AbstractLogger
{
    public array $records = [];

    public ?Closure $onWrite = null;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        ($this->onWrite ?? static fn () => null)($level, (string) $message, $context);
        $this->records[] = ['level' => $level, 'event' => (string) $message, 'context' => $context];
    }
}

final class LoggingDatabaseManager extends DatabaseManager
{
    public function __construct(private Connection $fixture)
    {
        parent::__construct(app(), new ConnectionFactory(app()));
    }

    public function connection($name = null)
    {
        return $this->fixture;
    }
}

final class LoggingConnection extends PostgresConnection
{
    public string $role = 'ROL004';

    public string $state = 'Confirmada';

    public bool $hasUser = true;

    public bool $reuse = false;

    public int $changed = 1;

    public string $noticeState = 'Pendiente';

    public bool $active = false;

    public int $commits = 0;

    public ?Throwable $commitFailure = null;

    public ?Throwable $operationFailure = null;

    public string $passwordHash = '';

    public function __construct()
    {
        parent::__construct(static fn () => throw new \LogicException('PDO is forbidden in logging fixtures'),
            'logging-fixture', '', ['name' => 'clinical', 'driver' => 'pgsql']);
    }

    public function actor(): string
    {
        return 'USU00000'.substr($this->role, -1);
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->active = true;
        try {
            $result = $callback($this);
            if ($this->commitFailure) {
                throw $this->commitFailure;
            }
            $this->commits++;

            return $result;
        } finally {
            $this->active = false;
        }
    }

    public function transactionLevel()
    {
        return $this->active ? 1 : 0;
    }

    public function scalar($query, $bindings = [], $useReadPdo = true)
    {
        if (str_contains($query, 'actor_actual()')) {
            return null;
        }
        $this->maybeFail();

        return match (true) {
            str_contains($query, 'fecha_clinica()') => '2026-11-20',
            str_contains($query, 'reservar_cita('), str_contains($query, 'reprogramar_cita('),
            str_contains($query, 'registrar_checkin(') => 'CIT00000002',
            str_contains($query, 'registrar_sancion(') => 'INC00000001',
            str_contains($query, 'crear_derivacion(') => 'DER00000001',
            str_contains($query, 'INSERT INTO analisis') => 'ANA00000001',
            default => true,
        };
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->maybeFail();
        if (str_contains(strtolower($query), 'exists(')) {
            return [(object) ['exists' => true]];
        }
        if (str_contains($query, 'from "usuarios"') && ! $this->hasUser) {
            return [];
        }

        return [$this->row($query, $bindings)];
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $this->maybeFail();
        if (str_contains($query, 'SELECT cod_ana FROM analisis')
            || str_contains($query, 'SELECT cod_der FROM derivaciones')) {
            return $this->reuse ? $this->row($query, $bindings) : null;
        }

        return $this->row($query, $bindings);
    }

    public function update($query, $bindings = [])
    {
        $this->maybeFail();

        return $this->changed;
    }

    public function statement($query, $bindings = [])
    {
        $this->maybeFail();

        return true;
    }

    private function maybeFail(): void
    {
        if ($this->active && $this->operationFailure) {
            throw $this->operationFailure;
        }
    }

    private function row(string $query, array $bindings): object
    {
        $appointment = 'CIT00000002';
        foreach ($bindings as $value) {
            if (is_string($value) && preg_match('/^CIT[0-9]+$/D', $value)) {
                $appointment = $value;
            }
        }
        $state = match (true) {
            str_contains($query, 'SELECT * FROM analisis') => 'Pendiente',
            str_contains($query, 'SELECT estado FROM notificaciones') => 'EnProceso',
            str_contains($query, 'SELECT n.estado FROM notificaciones') => $this->noticeState,
            str_contains($query, 'SELECT estado FROM especialidades') => 'Activo',
            str_contains($query, 'from "usuarios"') => 'Activo',
            default => $this->state,
        };

        return (object) [
            'cod_usu' => $this->actor(), 'cod_rol' => $this->role, 'estado' => $state,
            'password_hash' => $this->passwordHash, 'nombre_completo' => 'PERSONAL_PRIVATE_SENTINEL',
            'email' => 'private-sentinel@example.invalid', 'usuario_estado' => 'Activo',
            'rol_estado' => 'Activo', 'perfil_estado' => 'Activo', 'perfil_presente' => true,
            'email_verificado' => true, 'bloqueado_hasta' => null, 'nombre_rol' => 'Fixture',
            'cod_med' => 'MED000001', 'cod_sec' => 'SEC000001', 'cod_pac' => 'PAC000001',
            'cod_esp' => 'ESP001', 'cod_hor' => 'HOR000001', 'cod_blo' => 'BLO000001',
            'cod_inc' => 'INC00000001', 'cod_der' => 'DER00000001', 'cod_ana' => 'ANA00000001',
            'cod_cit' => $appointment, 'duracion_consulta_min' => 30,
            'codMed' => 'MED000001', 'codSec' => 'SEC000001', 'codPac' => 'PAC000001',
            'codEsp' => 'ESP001', 'codHor' => 'HOR000001', 'codBlo' => 'BLO000001',
            'codInc' => 'INC00000001', 'codDer' => 'DER00000001', 'codAna' => 'ANA00000001',
            'codCit' => $appointment, 'codUsu' => $this->actor(), 'estadoReserva' => 'Activo',
            'especialidades' => [], 'fecha' => '2026-11-20', 'esVencida' => false,
            'qrDisponible' => true, 'esHoy' => true, 'admiteCheckin' => true,
            'horaLlegadaCheckin' => '09:00:00', 'checkinPorUsu' => 'USU000002',
            'hora_llegada_checkin' => '09:00:00', 'checkin_por_usu' => 'USU000002',
            'fecha_atencion' => null, 'atendida_por_usu' => null,
            'resultado' => null, 'archivo_pdf_url' => null, 'fecha_entrega_real' => null,
            'solicitado_por_usu' => $this->actor(),
            'motivo' => 'MEDICAL_PRIVATE_SENTINEL', 'motivo_derivacion' => 'MEDICAL_PRIVATE_SENTINEL',
            'token_qr' => 'QR_PRIVATE_SENTINEL', 'tokenQr' => 'QR_PRIVATE_SENTINEL',
            'nombreCompleto' => 'PERSONAL_PRIVATE_SENTINEL',
            'id_notificacion' => 1, 'cita' => $appointment, 'canal_salida' => 'Email',
            'usuario' => $this->actor(), 'numero_intento' => 1, 'creado_en' => '2026-11-20 08:00:00-04',
            'tipo' => 'Confirmacion', 'destino' => 'private-sentinel@example.invalid',
            'mensaje' => 'MEDICAL_PRIVATE_SENTINEL', 'vencida' => false,
        ];
    }
}
