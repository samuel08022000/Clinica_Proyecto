<?php

namespace Tests\Postgres;

use App\Exceptions\ClinicalOperationException;
use App\Models\Usuario;
use App\Services\Administration\BitacoraService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Tests\TestCase;

class BitacoraQueryTest extends TestCase
{
    private ?Connection $db = null;

    private ?PDO $runtime = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLINICAL_PG_TESTS') !== '1') {
            $this->markTestSkipped('Requiere PostgreSQL clínico aislado.');
        }
        $this->db = DB::connection('clinical');
        $this->assertSame('clinica_laravel_phase1_tests', $this->db->scalar('SELECT current_database()'));
        $this->runtime = $this->db->getPdo();
        $this->db->setPdo(new PDO('pgsql:host=127.0.0.1;port=35443;dbname=clinica_laravel_phase1_tests',
            'clinica_owner', getenv('CLINICAL_OWNER_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->runtime) {
            $this->db->rollBack();
            $this->db->setPdo($this->runtime);
        }
        Auth::guard('web')->logout();
        parent::tearDown();
    }

    public function test_person_filters_pagination_and_export_use_the_same_events_without_leaking_private_fields(): void
    {
        $f = $this->fixture();
        $tag = 'AUD-'.bin2hex(random_bytes(5));
        $before = ['cod_pac' => $f['patient'], 'cod_med' => $f['doctor'], 'cod_sec' => $f['secretary'], 'estado' => 'Confirmada'];
        $after = $before + ['password_hash' => 'NEVER-EXPOSE-HASH', 'token_qr' => 'NEVER-EXPOSE-QR',
            'resultado' => 'NEVER-EXPOSE-RESULT', 'email' => 'private-never@example.test', 'ci' => 'NEVER-EXPOSE-CI',
            'motivo_derivacion' => 'NEVER-EXPOSE-CLINICAL-NOTE'];
        $after['estado'] = 'Atendida';
        for ($index = 0; $index < 31; $index++) {
            $this->event('citas', $tag.'-'.$index, $f['secretaryUser']->cod_usu, $before, $after);
        }
        $this->event('citas', $tag.'-foreign', $f['secretaryUser']->cod_usu,
            array_replace($before, ['cod_pac' => $f['otherPatient']]), array_replace($after, ['cod_pac' => $f['otherPatient']]));
        $filters = ['persona' => $f['patient'], 'q' => $tag];
        $service = app(BitacoraService::class);
        $first = $service->listing('pacientes', $filters);
        $second = $service->listing('pacientes', $filters + ['page' => 1]);
        $all = $service->listing('pacientes', $filters + ['page' => 1], true);
        $this->assertSame(31, $first['total']);
        $this->assertCount(25, $first['records']);
        $this->assertTrue($first['hayMas']);
        $this->assertCount(6, $second['records']);
        $this->assertFalse($second['hayMas']);
        $this->assertCount(31, $all['records']);
        $this->assertSame(array_merge(array_column($first['records'], 'id'), array_column($second['records'], 'id')), array_column($all['records'], 'id'));
        $this->assertSame('Confirmada', $first['records'][0]['changes'][0]['before']);
        $this->assertSame('Atendida', $first['records'][0]['changes'][0]['after']);
        $encoded = json_encode($all);
        $this->assertStringNotContainsString('NEVER-EXPOSE', $encoded);
        $this->assertStringNotContainsString('private-never', $encoded);
        $this->assertStringNotContainsString('password_hash', $encoded);
        $this->assertSame(32, $service->listing('secretarias', ['persona' => $f['secretary'], 'q' => $tag, 'relacion' => 'realizados', 'accion' => 'UPDATE'])['total']);
        $this->assertSame(0, $service->listing('pacientes', $filters + ['accion' => 'INSERT'])['total']);
        $this->assertSame(0, $service->listing('pacientes', ['persona' => $f['patient'], 'q' => "' OR 1=1 --"])['total']);
    }

    public function test_changes_about_a_doctor_are_distinct_from_actions_performed_by_the_doctor(): void
    {
        $f = $this->fixture();
        $id = $this->event('medicos', $f['doctor'], $f['admin']->cod_usu, ['estado' => 'Activo'], ['estado' => 'Inactivo']);
        $service = app(BitacoraService::class);
        $filters = ['persona' => $f['doctor'], 'q' => $f['admin']->nombre_completo];
        $related = $service->listing('medicos', $filters);
        $performed = $service->listing('medicos', $filters + ['relacion' => 'realizados']);
        $this->assertSame([$id], array_column($related['records'], 'id'));
        $this->assertSame($f['admin']->cod_usu, $related['records'][0]['actorCode']);
        $this->assertSame(0, $performed['total']);
        $tag = 'PERFORMED-'.bin2hex(random_bytes(5));
        $this->event('citas', $tag, $f['doctorUser']->cod_usu, [], ['cod_pac' => $f['patient'], 'cod_med' => $f['doctor'], 'estado' => 'Atendida']);
        $this->assertSame(1, $service->listing('medicos', ['persona' => $f['doctor'], 'q' => $tag, 'relacion' => 'realizados'])['total']);
        $this->assertSame(0, $service->listing('secretarias', ['persona' => $f['secretary'], 'q' => $tag, 'relacion' => 'realizados'])['total']);
    }

    public function test_local_date_boundaries_unknown_actors_and_literal_search_are_preserved(): void
    {
        $f = $this->fixture();
        $today = (string) $this->db->scalar('SELECT fecha_clinica()::text');
        $previous = (string) $this->db->scalar('SELECT (fecha_clinica()-1)::text');
        $next = (string) $this->db->scalar('SELECT (fecha_clinica()+1)::text');
        $tag = 'BOUND_100%-'.bin2hex(random_bytes(4));
        $payload = ['cod_pac' => $f['patient'], 'estado' => 'Confirmada'];
        foreach ([$previous.' 23:59:59-04', $today.' 00:00:00-04', $today.' 23:59:59-04', $next.' 00:00:00-04'] as $date) {
            $this->event('citas', $tag, null, [], $payload, $date);
        }
        $data = app(BitacoraService::class)->listing('pacientes', ['desde' => $today, 'hasta' => $today, 'persona' => $f['patient'], 'q' => $tag]);
        $this->assertSame(2, $data['total']);
        $this->assertSame('Sin responsable registrado', $data['records'][0]['actor']);
        $this->assertNull($data['records'][0]['actorCode']);
        $this->assertSame('23:59:59', substr($data['records'][0]['date'], -8));
    }

    public function test_runtime_permissions_allow_reading_each_section_but_not_editing_history(): void
    {
        $admin = $this->identity('ROL004');
        $this->actingAs($admin, 'web');
        $this->db->rollBack();
        $this->db->setPdo($this->runtime);
        $this->runtime = null;
        $this->assertSame('clinica_app', $this->db->scalar('SELECT current_user'));
        foreach (array_keys(BitacoraService::SECTIONS) as $section) {
            $data = app(BitacoraService::class)->listing($section);
            $this->assertIsInt($data['total']);
            $this->assertLessThanOrEqual(25, count($data['records']));
        }
        $this->assertFalse($this->db->scalar("SELECT has_table_privilege(current_user,'bitacora','UPDATE')"));
        $this->assertFalse($this->db->scalar("SELECT has_table_privilege(current_user,'bitacora','DELETE')"));
    }

    public function test_referral_affecting_two_doctors_is_one_event_with_two_related_profiles(): void
    {
        $f = $this->fixture();
        $destinationUser = $this->identity('ROL003');
        $destination = $this->db->scalar('INSERT INTO medicos(cod_usu,matricula_profesional) VALUES (?,?) RETURNING cod_med', [$destinationUser->cod_usu, 'AUD-'.bin2hex(random_bytes(5))]);
        $tag = 'REFERRAL-'.bin2hex(random_bytes(5));
        $id = $this->event('derivaciones', $tag, $f['doctorUser']->cod_usu, [], [
            'cod_med_emisor' => $f['doctor'], 'cod_med_destino' => $destination, 'estado' => 'Pendiente',
            'motivo_derivacion' => 'PRIVATE-NOTE-NOT-IN-REPORT',
        ]);
        $data = app(BitacoraService::class)->listing('medicos', ['q' => $tag]);
        $this->assertSame(1, $data['total']);
        $this->assertSame($id, $data['records'][0]['id']);
        $this->assertCount(2, $data['records'][0]['people']);
        $this->assertStringNotContainsString('PRIVATE-NOTE', json_encode($data));
    }

    public function test_large_exports_fail_explicitly_instead_of_returning_only_the_first_records(): void
    {
        $f = $this->fixture();
        $tag = 'LIMIT-'.bin2hex(random_bytes(5));
        $payload = json_encode(['cod_pac' => $f['patient'], 'estado' => 'Confirmada']);
        $this->db->insert(<<<'SQL'
            INSERT INTO bitacora(cod_usu,nombre_tabla,accion,clave_registro,datos_anteriores,datos_nuevos)
            SELECT ?,'citas','UPDATE',?||n::text,'{}'::jsonb,?::jsonb FROM generate_series(1,?) AS n
            SQL, [$f['admin']->cod_usu, $tag, $payload, BitacoraService::MAX_EXPORT_ROWS + 1]);
        try {
            app(BitacoraService::class)->listing('pacientes', ['persona' => $f['patient'], 'q' => $tag], true);
            $this->fail('Se exportó un reporte truncado.');
        } catch (ClinicalOperationException $error) {
            $this->assertSame(422, $error->status);
            $this->assertSame('REPORTE_EXTENSO', $error->errorCode);
        }
    }

    public function test_service_rejects_a_patient_even_when_called_without_the_controller(): void
    {
        $this->actingAs($this->identity('ROL001'), 'web');
        $this->expectException(ClinicalOperationException::class);
        $this->expectExceptionMessage('No tienes permisos');
        app(BitacoraService::class)->listing('pacientes');
    }

    private function fixture(): array
    {
        $admin = $this->identity('ROL004');
        $doctorUser = $this->identity('ROL003');
        $secretaryUser = $this->identity('ROL002');
        $doctor = $this->db->scalar('INSERT INTO medicos(cod_usu,matricula_profesional) VALUES (?,?) RETURNING cod_med', [$doctorUser->cod_usu, 'AUD-'.bin2hex(random_bytes(5))]);
        $secretary = $this->db->scalar('INSERT INTO secretarias(cod_usu) VALUES (?) RETURNING cod_sec', [$secretaryUser->cod_usu]);
        $patients = [];
        foreach ([0, 1] as $index) {
            $user = $this->identity('ROL001');
            $patients[] = $this->db->scalar("INSERT INTO pacientes(cod_usu,ci,fecha_nacimiento,telefono,ciudad_residencia,email_verificado) VALUES (?,?,'1995-01-01','70000000','La Paz',true) RETURNING cod_pac", [$user->cod_usu, 'AUD-'.bin2hex(random_bytes(5))]);
        }
        $this->actingAs($admin, 'web');

        return compact('admin', 'doctorUser', 'secretaryUser', 'doctor', 'secretary') + ['patient' => $patients[0], 'otherPatient' => $patients[1]];
    }

    private function identity(string $role): Usuario
    {
        $tag = bin2hex(random_bytes(6));
        $row = $this->db->selectOne('INSERT INTO usuarios(nombre_completo,email,password_hash,cod_rol) VALUES (?,?,?,?) RETURNING *',
            ['Auditoría '.$tag, $tag.'@audit.example.test', Hash::make(bin2hex(random_bytes(16))), $role]);

        return (new Usuario)->forceFill((array) $row);
    }

    private function event(string $table, string $reference, ?string $actor, array $before, array $after, ?string $date = null): string
    {
        return (string) $this->db->scalar(<<<'SQL'
            INSERT INTO bitacora(cod_usu,nombre_tabla,accion,clave_registro,datos_anteriores,datos_nuevos,registrado_en)
            VALUES (?,?,'UPDATE',?,?::jsonb,?::jsonb,coalesce(?::timestamptz,now())) RETURNING cod_bit
            SQL, [$actor, $table, $reference, json_encode((object) $before), json_encode((object) $after), $date]);
    }
}
