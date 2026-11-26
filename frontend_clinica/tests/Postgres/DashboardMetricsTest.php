<?php

namespace Tests\Postgres;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

/** Fixtures revertidas y comprobación de lectura con los permisos reales de clinica_app. */
class DashboardMetricsTest extends TestCase
{
    private ?Connection $clinical = null;

    private ?PDO $runtime = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICAL_PG_TESTS') !== '1') {
            $this->markTestSkipped('Requiere PostgreSQL clínico aislado.');
        }
        $this->clinical = DB::connection('clinical');
        $this->assertSame('clinica_laravel_phase1_tests', $this->clinical->scalar('SELECT current_database()'));
        $this->assertSame('clinica_app', $this->clinical->scalar('SELECT current_user'));
        $this->runtime = $this->clinical->getPdo();
        $owner = new PDO('pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests',
            'clinica_owner', getenv('CLINICAL_OWNER_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->clinical->setPdo($owner);
        $this->clinical->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->clinical !== null && $this->runtime !== null) {
            if ($this->clinical->transactionLevel() > 0) {
                $this->clinical->rollBack();
            }
            $this->clinical->setPdo($this->runtime);
        }
        Auth::guard('web')->logout();
        parent::tearDown();
    }

    public function test_metrics_count_people_once_fill_missing_days_and_do_not_duplicate_rescheduled_demand(): void
    {
        $db = $this->clinical;
        $admin = $this->identity('ROL004');
        $this->actingAs($admin, 'web');
        $from = (string) $db->scalar('SELECT (fecha_clinica()+1)::text');
        $to = (string) $db->scalar('SELECT (fecha_clinica()+2)::text');
        $filters = ['periodo' => 'personalizado', 'desde' => $from, 'hasta' => $to];
        $before = app(DashboardService::class)->snapshot($filters);
        $doctor = $this->identity('ROL003');
        $secretary = $this->identity('ROL002');
        $doctorCode = $db->scalar('INSERT INTO medicos(cod_usu,matricula_profesional,duracion_consulta_min) VALUES (?, ?, 20) RETURNING cod_med', [$doctor->cod_usu, 'DASH-'.bin2hex(random_bytes(6))]);
        $secretaryCode = $db->scalar('INSERT INTO secretarias(cod_usu) VALUES (?) RETURNING cod_sec', [$secretary->cod_usu]);
        $tag = bin2hex(random_bytes(6));
        $specialties = [];
        foreach (['A', 'B'] as $suffix) {
            $specialties[$suffix] = $db->scalar('INSERT INTO especialidades(nombre_especialidad,duracion_consulta_min) VALUES (?,20) RETURNING cod_esp', ["Dashboard $tag $suffix"]);
            $db->insert('INSERT INTO medico_especialidades(cod_med,cod_esp) VALUES (?,?)', [$doctorCode, $specialties[$suffix]]);
        }
        $patients = [];
        foreach ([$from.' 00:00:00', $to.' 23:59:59', $from.' 00:00:00'] as $index => $created) {
            $user = $this->identity('ROL001');
            $patients[] = $db->scalar(<<<'SQL'
                INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,email_verificado,creado_en)
                VALUES (?,?,'1995-01-01','70000000','La Paz',true,?::timestamp AT TIME ZONE 'America/La_Paz') RETURNING cod_pac
                SQL, [$user->cod_usu, 'DASH-'.bin2hex(random_bytes(6)), $created]);
        }
        $db->update("UPDATE pacientes SET creado_en=creado_en-interval '1 second' WHERE cod_pac=?", [$patients[2]]);
        $appointments = [];
        $schedules = [];
        foreach ([[$from, 'A', '08:00', 'Atendida', 'Web', 0], [$from, 'A', '08:20', 'Atendida', 'Ventanilla', 0],
            [$from, 'A', '08:40', 'Confirmada', 'Web', 1], [$from, 'A', '09:00', 'Reprogramada', 'Admin', 0],
            [$to, 'B', '08:00', 'Atendida', 'Derivacion', 0]] as [$date, $specialty, $time, $state, $channel, $patient]) {
            $schedule = $schedules[$date] ?? null;
            if ($schedule === null) {
                $schedule = $db->scalar(<<<'SQL'
                    INSERT INTO horarios(cod_med,dia_semana,hora_inicio,hora_fin)
                    VALUES (?,extract(isodow FROM ?::date)::int,'08:00','10:00') RETURNING cod_hor
                    SQL, [$doctorCode, $date]);
                $schedules[$date] = $schedule;
            }
            $slot = $db->scalar(<<<'SQL'
                INSERT INTO slots_agenda(cod_hor,cod_med,cod_esp,inicio_at,fin_at)
                VALUES (?,?,?,?::date+?::time,?::date+?::time+interval '20 minutes')
                ON CONFLICT DO NOTHING RETURNING cod_slot
                SQL, [$schedule, $doctorCode, $specialties[$specialty], $date, $time, $date, $time]);
            if ($slot === null) {
                $slot = $db->scalar('SELECT cod_slot FROM slots_agenda WHERE cod_hor=? AND cod_esp=? AND inicio_at=?::date+?::time', [$schedule, $specialties[$specialty], $date, $time]);
            }
            $attended = $state === 'Atendida';
            $appointments[] = $db->scalar(<<<'SQL'
                INSERT INTO citas(cod_pac,cod_slot,estado,canal_reserva,cod_sec,hora_llegada_checkin,checkin_por_usu,fecha_atencion,atendida_por_usu)
                VALUES (?,?,?,?,?,?::timestamptz,?,?::timestamptz,?) RETURNING cod_cit
                SQL, [$patients[$patient], $slot, $state, $channel, $secretaryCode,
                $attended ? $date.' 08:00:00-04' : null, $attended ? $secretary->cod_usu : null,
                $attended ? $date.' 08:20:00-04' : null, $attended ? $doctor->cod_usu : null]);
        }
        $db->update('UPDATE citas SET cod_cit_padre=? WHERE cod_cit=?', [$appointments[3], $appointments[4]]);
        foreach ([$from.' 00:00:00', $to.' 23:59:59'] as $created) {
            $db->insert(<<<'SQL'
                INSERT INTO derivaciones(cod_cit_origen,cod_med_emisor,cod_esp_destino,motivo_derivacion,fecha_derivacion)
                VALUES (?,?,?,'Prueba dashboard',?::timestamp AT TIME ZONE 'America/La_Paz')
                SQL, [$appointments[0], $doctorCode, $specialties['B'], $created]);
        }
        $after = app(DashboardService::class)->snapshot($filters);

        $this->assertSame($before['totals']['appointments'] + 5, $after['totals']['appointments']);
        $this->assertSame($before['totals']['attendedPatients'] + 1, $after['totals']['attendedPatients']);
        $this->assertSame($before['totals']['newPatients'] + 2, $after['totals']['newPatients']);
        $this->assertSame($before['totals']['doctors'] + 1, $after['totals']['doctors']);
        $this->assertSame($before['totals']['secretaries'] + 1, $after['totals']['secretaries']);
        $this->assertCount(2, $after['daily']);
        foreach ([0, 1] as $day) {
            $this->assertSame($before['daily'][$day]['value'] + 1, $after['daily'][$day]['value']);
        }
        $specialtyCounts = array_column($after['specialties'], 'value', 'label');
        $this->assertSame(3, $specialtyCounts["Dashboard $tag A"]);
        $this->assertSame(1, $specialtyCounts["Dashboard $tag B"]);
        $this->assertSame($after['totals']['appointments'], array_sum(array_column($after['states'], 'value')));
        $this->assertSame($after['totals']['appointments'], array_sum(array_column($after['channels'], 'value')));
        $this->assertSame(array_sum(array_column($before['referrals'], 'value')) + 2, array_sum(array_column($after['referrals'], 'value')));
    }

    public function test_presets_use_the_database_clinical_date_and_empty_history_has_real_zeroes(): void
    {
        $this->actingAs($this->identity('ROL004'), 'web');
        $this->clinical->rollBack();
        $this->clinical->setPdo($this->runtime);
        $this->runtime = null;
        $this->assertSame('clinica_app', $this->clinical->scalar('SELECT current_user'));
        $service = app(DashboardService::class);
        $today = (string) $this->clinical->scalar('SELECT fecha_clinica()::text');
        $day = $service->snapshot(['periodo' => 'hoy']);
        $this->assertSame($today, $day['period']['from']);
        $this->assertSame($today, $day['period']['to']);
        $this->assertCount(1, $day['daily']);
        $week = $service->snapshot(['periodo' => 'semana']);
        $this->assertSame(date('Y-m-d', strtotime('monday this week', strtotime($today))), $week['period']['from']);
        $month = $service->snapshot();
        $this->assertSame(substr($today, 0, 7).'-01', $month['period']['from']);
        $empty = $service->snapshot(['periodo' => 'personalizado', 'desde' => '1900-01-01', 'hasta' => '1900-01-03']);
        $this->assertSame([0, 0, 0], array_column($empty['daily'], 'value'));
        $this->assertSame(0, $empty['totals']['appointments']);
        $this->assertSame([], $empty['specialties']);
        $this->assertSame(0, array_sum(array_column($empty['channels'], 'value')));
    }

    public function test_service_rejects_a_non_administrator(): void
    {
        $this->actingAs($this->identity('ROL001'), 'web');
        $this->expectException(ClinicalOperationException::class);
        $this->expectExceptionMessage('No tienes permisos');
        app(DashboardService::class)->snapshot();
    }

    public function test_waiting_thresholds_and_observed_samples_use_their_own_denominators(): void
    {
        $db = $this->clinical;
        $this->actingAs($this->identity('ROL004'), 'web');
        $doctor = $this->identity('ROL003')->cod_usu;
        $secretary = $this->identity('ROL002')->cod_usu;
        $patientUser = $this->identity('ROL001')->cod_usu;
        $tag = bin2hex(random_bytes(6));
        $doctorCode = $db->scalar('INSERT INTO medicos(cod_usu,matricula_profesional,duracion_consulta_min) VALUES (?,?,20) RETURNING cod_med', [$doctor, "WAIT-$tag"]);
        $secretaryCode = $db->scalar('INSERT INTO secretarias(cod_usu) VALUES (?) RETURNING cod_sec', [$secretary]);
        $patient = $db->scalar("INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,email_verificado)
            VALUES (?,?,'1995-01-01','70000000','La Paz',true) RETURNING cod_pac", [$patientUser, "WAIT-$tag"]);
        $specialty = $db->scalar('INSERT INTO especialidades(nombre_especialidad,duracion_consulta_min) VALUES (?,20) RETURNING cod_esp', ["Espera $tag"]);
        $db->insert('INSERT INTO medico_especialidades(cod_med,cod_esp) VALUES (?,?)', [$doctorCode, $specialty]);
        $date = $db->scalar('SELECT fecha_clinica()+2');
        $schedule = $db->scalar("INSERT INTO horarios(cod_med,dia_semana,hora_inicio,hora_fin)
            VALUES (?,extract(isodow FROM ?::date)::int,'08:00','12:00') RETURNING cod_hor", [$doctorCode, $date]);
        $idleDoctors = [];
        foreach (['sin_demanda', 'sin_capacidad'] as $expected) {
            $idle = $db->scalar('INSERT INTO medicos(cod_usu,matricula_profesional,duracion_consulta_min) VALUES (?,?,20) RETURNING cod_med',
                [$this->identity('ROL003')->cod_usu, "$expected-$tag"]);
            $idleDoctors[$idle] = $expected;
            $db->insert('INSERT INTO medico_especialidades(cod_med,cod_esp) VALUES (?,?)', [$idle, $specialty]);
            if ($expected === 'sin_demanda') {
                $db->insert("INSERT INTO horarios(cod_med,dia_semana,hora_inicio,hora_fin) VALUES (?,extract(isodow FROM ?::date)::int,'08:00','12:00')",
                    [$idle, $date]);
            }
        }
        $capture = CarbonImmutable::parse($db->scalar('SELECT clock_timestamp()'))->setTimezone('America/La_Paz')->startOfSecond();
        $format = static fn (?CarbonImmutable $time): ?string => $time?->toIso8601String();
        $waiting = 'En Espera';
        $attended = 'Atendida';
        $fixtures = [
            [$waiting, $capture->subMinutes(15), null, null],
            [$waiting, $capture->subMinutes(30), null, null],
            [$waiting, $capture->subMinutes(30)->subSecond(), null, null],
            [$waiting, $capture->addSecond(), null, null],
            [$waiting, $capture->subMinutes(60), $capture->subMinutes(20), null],
            [$attended, $capture->subMinutes(40), $capture->subMinutes(30), $capture->subMinutes(5)],
            [$attended, $capture->subMinutes(30), null, $capture->subMinutes(10)],
            [$attended, $capture->subMinutes(10), $capture->subMinutes(35), $capture->subMinutes(20)],
        ];
        foreach ($fixtures as $index => [$state, $arrival, $start, $finish]) {
            $minutesAfterEight = $index * 20;
            $slot = $db->scalar("SELECT cod_slot FROM slots_agenda WHERE cod_hor=? AND cod_esp=? AND inicio_at=?::date+time '08:00'+make_interval(mins=>?)",
                [$schedule, $specialty, $date, $minutesAfterEight]);
            $db->insert(<<<'SQL'
                INSERT INTO citas(cod_pac,cod_slot,estado,canal_reserva,cod_sec,hora_llegada_checkin,
                    checkin_por_usu,hora_inicio_atencion,fecha_atencion,atendida_por_usu)
                VALUES (?,?,?,'Ventanilla',?,?::timestamptz,?,?::timestamptz,?::timestamptz,?)
                SQL, [
                $patient, $slot, $state, $secretaryCode, $format($arrival), $secretary,
                $format($start), $format($finish), $finish !== null ? $doctor : null,
            ]);
        }

        Date::setTestNow($capture);
        try {
            $service = app(DashboardService::class);
            $data = $service->snapshot(['periodo' => 'personalizado', 'desde' => $date, 'hasta' => $date, 'medico' => $doctorCode]);
            $queues = $data['queues'];
            $doctorWait = $queues['espera_actual']['por_medico'][$doctorCode];
            $this->assertSame([4, 1, 1, 1, 1, 1], array_map(
                fn (string $key) => $doctorWait[$key],
                ['total', 'en_atencion', 'dentro', 'moderada', 'elevada', 'sin_tiempo'],
            ));
            $this->assertEqualsWithDelta(100 / 3, $doctorWait['porcentaje'], 1e-9);
            $this->assertEqualsWithDelta(30 + 1 / 60, $doctorWait['maximo'], 1e-9);
            $this->assertSame($capture->format('d/m/Y H:i:s'), $queues['espera_actual']['actualizado']);
            $this->assertArrayNotHasKey('simulacion', $queues);
            $this->assertSame([
                'espera' => 25.0, 'muestra_espera' => 2, 'consulta' => 25.0, 'programada' => 20.0,
                'muestra_consulta' => 1, 'total' => 27.5, 'muestra_total' => 2,
            ], $queues['resumen_global']['observado']);
            $this->assertSame(27.5, $queues['resumen_global']['w_empirico_minutos']);
            $this->assertSame(2, $queues['evolucion_espera'][0]['sample']);
            $bySpecialty = $service->snapshot(['periodo' => 'personalizado', 'desde' => $date, 'hasta' => $date, 'especialidad' => $specialty]);
            $doctors = array_column($bySpecialty['queues']['por_medico'], null, 'cod_med');
            foreach ($idleDoctors as $idle => $expected) {
                $this->assertSame($expected, $doctors[$idle]['utilizacion']['estado']);
                $this->assertSame($expected === 'sin_demanda' ? 0.0 : null, $doctors[$idle]['utilizacion']['valor']);
                $this->assertSame(0, $doctors[$idle]['en_espera_hoy']);
            }
            $emptyHistory = $service->snapshot(['periodo' => 'personalizado', 'desde' => '1900-01-01', 'hasta' => '1900-01-03', 'especialidad' => $specialty]);
            $this->assertSame($doctorWait, $emptyHistory['queues']['espera_actual']['por_medico'][$doctorCode]);
            $this->assertSame([], $emptyHistory['queues']['evolucion_espera']);
        } finally {
            Date::setTestNow();
        }
    }

    private function identity(string $role): Usuario
    {
        $row = $this->clinical->selectOne(<<<'SQL'
            INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol)
            VALUES ('Prueba Dashboard',?,?,?) RETURNING *
            SQL, [bin2hex(random_bytes(12)).'@dashboard.example.test', Hash::make(bin2hex(random_bytes(18))), $role]);

        return (new Usuario)->forceFill((array) $row);
    }
}
