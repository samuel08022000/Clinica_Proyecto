<?php

namespace App\Services\Administration;

use App\Services\Clinical\ClinicalRoleGuard;
use App\Services\Clinical\ClinicalSqlException;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

/**
 * Servicio de solo lectura para análisis de Teoría de Colas (M/M/1 y M/M/c).
 *
 * Toda la matemática es pura y determinista. Las consultas a PostgreSQL son
 * exclusivamente SELECT bajo el rol autorizado ROL004.
 */
class ColaMetricaService
{
    private const EPSILON = 1e-12;

    private const HORAS_RESPALDO_POR_DIA = 8.0;

    private const DURACION_DEFECTO_MIN = 30;

    private const MAX_SERVIDORES_SIMULADOR = 50;

    public function __construct(
        private DatabaseManager $database,
        private ClinicalRoleGuard $roles,
    ) {}

    /**
     * Calcula el número mínimo de servidores necesarios para estabilizar la cola (rho < 1).
     */
    public function calcularServidoresMinimos(float $lambda, float $mu): int
    {
        if ($lambda <= self::EPSILON || $mu <= self::EPSILON) {
            return 0;
        }

        $intensidad = $lambda / $mu;

        return (int) floor($intensidad + self::EPSILON) + 1;
    }

    /**
     * Calcula métricas analíticas en estado estable para el modelo M/M/1 (disciplina FIFO).
     *
     * @return array{
     *     modelo: string,
     *     lambda: float,
     *     mu: float,
     *     servidores: int,
     *     capacidad_total: float,
     *     intensidad_trafico: ?float,
     *     rho: ?float,
     *     rho_porcentaje: ?float,
     *     es_estable: bool,
     *     estado: string,
     *     etiqueta: string,
     *     p0: ?float,
     *     p0_porcentaje: ?float,
     *     p_espera: ?float,
     *     p_espera_porcentaje: ?float,
     *     lq: ?float,
     *     l: ?float,
     *     wq_horas: ?float,
     *     wq_minutos: ?float,
     *     w_horas: ?float,
     *     w_minutos: ?float,
     *     servidores_minimos: int,
     *     recomendacion: string
     * }
     */
    public function calcularMM1(float $lambda, float $mu): array
    {
        $lambda = max(0.0, $lambda);
        $mu = max(0.0, $mu);

        if ($lambda <= self::EPSILON || $mu <= self::EPSILON) {
            return $this->resultadoSinFlujo('M/M/1', $lambda, $mu, 1);
        }

        $intensidad = $lambda / $mu;
        $rho = $intensidad;
        $cMin = $this->calcularServidoresMinimos($lambda, $mu);

        if ($rho >= 1.0 - self::EPSILON) {
            return $this->resultadoSaturado('M/M/1', $lambda, $mu, 1, $intensidad, $rho, $cMin);
        }

        $p0 = 1.0 - $rho;
        $pEspera = $rho;
        $denominador = $mu - $lambda;
        $lq = ($lambda * $lambda) / ($mu * $denominador);
        $l = $lambda / $denominador;
        $wqHoras = $lambda / ($mu * $denominador);
        $wHoras = 1.0 / $denominador;

        return $this->construirResultadoEstable(
            'M/M/1',
            $lambda,
            $mu,
            1,
            $intensidad,
            $rho,
            $p0,
            $pEspera,
            $lq,
            $l,
            $wqHoras,
            $wHoras,
            $cMin,
        );
    }

    /**
     * Calcula métricas analíticas en estado estable para el modelo M/M/c (Erlang C, disciplina FIFO).
     * Cuando $servidores === 1, las fórmulas colapsan exactamente al modelo M/M/1.
     *
     * @return array{
     *     modelo: string,
     *     lambda: float,
     *     mu: float,
     *     servidores: int,
     *     capacidad_total: float,
     *     intensidad_trafico: ?float,
     *     rho: ?float,
     *     rho_porcentaje: ?float,
     *     es_estable: bool,
     *     estado: string,
     *     etiqueta: string,
     *     p0: ?float,
     *     p0_porcentaje: ?float,
     *     p_espera: ?float,
     *     p_espera_porcentaje: ?float,
     *     lq: ?float,
     *     l: ?float,
     *     wq_horas: ?float,
     *     wq_minutos: ?float,
     *     w_horas: ?float,
     *     w_minutos: ?float,
     *     servidores_minimos: int,
     *     recomendacion: string
     * }
     */
    public function calcularMMc(float $lambda, float $mu, int $servidores): array
    {
        $lambda = max(0.0, $lambda);
        $mu = max(0.0, $mu);
        $servidores = max(0, $servidores);
        $modelo = $servidores === 1 ? 'M/M/1' : 'M/M/c';

        if ($lambda <= self::EPSILON || $mu <= self::EPSILON) {
            return $this->resultadoSinFlujo($modelo, $lambda, $mu, $servidores);
        }

        $intensidad = $lambda / $mu;
        $cMin = $this->calcularServidoresMinimos($lambda, $mu);

        if ($servidores < 1) {
            return $this->resultadoSaturado($modelo, $lambda, $mu, 0, $intensidad, null, $cMin);
        }

        $rho = $lambda / ($servidores * $mu);

        if ($rho >= 1.0 - self::EPSILON) {
            return $this->resultadoSaturado($modelo, $lambda, $mu, $servidores, $intensidad, $rho, $cMin);
        }

        $suma = 1.0;
        $termino = 1.0;
        for ($n = 1; $n < $servidores; $n++) {
            $termino = ($termino * $intensidad) / $n;
            $suma += $termino;
        }

        $terminoC = ($termino * $intensidad) / $servidores;
        $colaErlang = $terminoC / (1.0 - $rho);
        $denominador = $suma + $colaErlang;

        if (! is_finite($denominador) || $denominador <= self::EPSILON) {
            return $this->resultadoSaturado($modelo, $lambda, $mu, $servidores, $intensidad, $rho, $cMin);
        }

        $p0 = 1.0 / $denominador;
        $pEspera = $colaErlang * $p0;
        $lq = $pEspera * ($rho / (1.0 - $rho));
        $wqHoras = $lq / $lambda;
        $wHoras = $wqHoras + (1.0 / $mu);
        $l = $lq + $intensidad;

        return $this->construirResultadoEstable(
            $modelo,
            $lambda,
            $mu,
            $servidores,
            $intensidad,
            $rho,
            $p0,
            $pEspera,
            $lq,
            $l,
            $wqHoras,
            $wHoras,
            $cMin,
        );
    }

    /**
     * Evalúa el escenario elegido en el simulador What-If junto con escenarios comparativos de capacidad.
     *
     * @return array{
     *     parametros: array{lambda: float, duracion_minutos: int, mu: float, servidores: int},
     *     actual: array<string, mixed>,
     *     comparativa: list<array{etiqueta_escenario: string, es_actual: bool, metricas: array<string, mixed>}>
     * }
     */
    public function simularEscenariosComparativos(float $lambda, int $duracionMinutos, int $servidores): array
    {
        $lambda = max(0.0, $lambda);
        $duracionMinutos = max(1, $duracionMinutos);
        $servidores = max(1, min(self::MAX_SERVIDORES_SIMULADOR, $servidores));
        $mu = 60.0 / $duracionMinutos;

        $actual = $this->calcularMMc($lambda, $mu, $servidores);
        $comparativa = [
            [
                'etiqueta_escenario' => 'Escenario elegido (c = '.$servidores.')',
                'es_actual' => true,
                'metricas' => $actual,
            ],
        ];

        $candidatos = [];
        if ($actual['estado'] === 'saturado' && $actual['servidores_minimos'] > $servidores) {
            $base = $actual['servidores_minimos'];
            $candidatos = [
                [$base, 'Mínimo estable (c = '.$base.')'],
                [$base + 1, 'Estable +1 médico (c = '.($base + 1).')'],
                [$base + 2, 'Estable +2 médicos (c = '.($base + 2).')'],
            ];
        } else {
            $candidatos = [
                [$servidores + 1, '+1 médico (c = '.($servidores + 1).')'],
                [$servidores + 2, '+2 médicos (c = '.($servidores + 2).')'],
            ];
        }

        $incluidos = [$servidores => true];
        foreach ($candidatos as [$cCandidato, $etiqueta]) {
            if ($cCandidato < 1 || $cCandidato > self::MAX_SERVIDORES_SIMULADOR || isset($incluidos[$cCandidato])) {
                continue;
            }
            $incluidos[$cCandidato] = true;
            $comparativa[] = [
                'etiqueta_escenario' => $etiqueta,
                'es_actual' => false,
                'metricas' => $this->calcularMMc($lambda, $mu, $cCandidato),
            ];
        }

        return [
            'parametros' => [
                'lambda' => $lambda,
                'duracion_minutos' => $duracionMinutos,
                'mu' => $mu,
                'servidores' => $servidores,
            ],
            'actual' => $actual,
            'comparativa' => $comparativa,
        ];
    }

    /**
     * Calcula las horas efectivas programadas de un médico en un rango de fechas,
     * descontando pausas configuradas y bloqueos de agenda registrados.
     *
     * @param  list<array{dia_semana: int, hora_inicio: string, hora_fin: string, pausa_inicio: ?string, pausa_fin: ?string}>  $horarios
     * @param  list<array{fecha_bloqueo: string, hora_inicio: ?string, hora_fin: ?string}>  $bloqueos
     */
    public function calcularHorasEfectivasMedico(
        array $horarios,
        array $bloqueos,
        string $fechaInicio,
        string $fechaFin,
    ): float {
        if ($horarios === []) {
            return 0.0;
        }

        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);
        if (! $inicio || ! $fin || $fin < $inicio) {
            return 0.0;
        }

        /** @var array<int, list<array{0: int, 1: int}>> $intervalosPorDia */
        $intervalosPorDia = [];
        foreach ($horarios as $horario) {
            $dia = (int) $horario['dia_semana'];
            $hInicio = $this->tiempoASegundos((string) $horario['hora_inicio']);
            $hFin = $this->tiempoASegundos((string) $horario['hora_fin']);
            if ($hFin <= $hInicio) {
                continue;
            }

            $pInicio = $horario['pausa_inicio'] !== null ? $this->tiempoASegundos((string) $horario['pausa_inicio']) : null;
            $pFin = $horario['pausa_fin'] !== null ? $this->tiempoASegundos((string) $horario['pausa_fin']) : null;

            if ($pInicio !== null && $pFin !== null && $pFin > $pInicio && $pInicio >= $hInicio && $pFin <= $hFin) {
                if ($pInicio > $hInicio) {
                    $intervalosPorDia[$dia][] = [$hInicio, $pInicio];
                }
                if ($hFin > $pFin) {
                    $intervalosPorDia[$dia][] = [$pFin, $hFin];
                }
            } else {
                $intervalosPorDia[$dia][] = [$hInicio, $hFin];
            }
        }

        if ($intervalosPorDia === []) {
            return 0.0;
        }

        /** @var array<string, list<?array{0: int, 1: int}>> $bloqueosPorFecha */
        $bloqueosPorFecha = [];
        foreach ($bloqueos as $bloqueo) {
            $fecha = (string) $bloqueo['fecha_bloqueo'];
            if ($bloqueo['hora_inicio'] === null || $bloqueo['hora_fin'] === null) {
                $bloqueosPorFecha[$fecha][] = null;
            } else {
                $bInicio = $this->tiempoASegundos((string) $bloqueo['hora_inicio']);
                $bFin = $this->tiempoASegundos((string) $bloqueo['hora_fin']);
                if ($bFin > $bInicio) {
                    $bloqueosPorFecha[$fecha][] = [$bInicio, $bFin];
                }
            }
        }

        $totalSegundos = 0;
        $unDia = new DateInterval('P1D');
        for ($cursor = $inicio; $cursor <= $fin; $cursor = $cursor->add($unDia)) {
            $isoDia = (int) $cursor->format('N');
            if (! isset($intervalosPorDia[$isoDia])) {
                continue;
            }

            $segmentos = $intervalosPorDia[$isoDia];
            $claveFecha = $cursor->format('Y-m-d');
            if (isset($bloqueosPorFecha[$claveFecha])) {
                foreach ($bloqueosPorFecha[$claveFecha] as $bloqueoDia) {
                    if ($bloqueoDia === null) {
                        $segmentos = [];
                        break;
                    }
                    $segmentos = $this->restarIntervalo($segmentos, $bloqueoDia[0], $bloqueoDia[1]);
                    if ($segmentos === []) {
                        break;
                    }
                }
            }

            foreach ($segmentos as [$segInicio, $segFin]) {
                $totalSegundos += max(0, $segFin - $segInicio);
            }
        }

        return round($totalSegundos / 3600.0, 4);
    }

    /**
     * Obtiene en modo 100% solo lectura todos los datos analíticos del panel de Teoría de Colas.
     *
     * @param  array{
     *     fecha_inicio?: ?string,
     *     fecha_fin?: ?string,
     *     especialidad?: ?string,
     *     medico?: ?string,
     *     sim_lambda?: mixed,
     *     sim_duracion?: mixed,
     *     sim_servidores?: mixed
     * }  $filtros
     * @return array{
     *     fecha_clinica_hoy: string,
     *     filtros_aplicados: array{
     *         fecha_inicio: string,
     *         fecha_fin: string,
     *         especialidad: ?string,
     *         medico: ?string
     *     },
     *     catalogos: array{
     *         especialidades: list<array{codEsp: string, nombreEspecialidad: string, duracionConsultaMin: int, estado: string}>,
     *         medicos: list<array{codMed: string, nombreCompleto: string, estado: string}>
     *     },
     *     resumen_global: array{
     *         ambito: string,
     *         total_llegadas: int,
     *         total_atendidas: int,
     *         horas_efectivas: float,
     *         base_horas: string,
     *         en_espera_hoy: int,
     *         w_empirico_minutos: ?float,
     *         muestra_empirica: int,
     *         metricas: array<string, mixed>
     *     },
     *     por_especialidad: list<array<string, mixed>>,
     *     por_medico: list<array<string, mixed>>,
     *     alertas: list<array<string, mixed>>,
     *     simulacion: array<string, mixed>,
     *     simulacion_es_referencia: bool
     * }
     */
    public function obtenerDatosPanel(array $filtros): array
    {
        $this->roles->require('ROL004');

        try {
            $db = $this->db();
            $fechaHoy = $this->fechaClinicaActual($db);

            $fechaFinExplicita = $filtros['fecha_fin'] ?? null;
            $fechaInicioExplicita = $filtros['fecha_inicio'] ?? null;
            $fechaFin = $this->resolverFecha($fechaFinExplicita, $fechaHoy);
            $fechaInicioPorDefecto = $this->restarDias($fechaFin, 29);
            $fechaInicio = $this->resolverFecha($fechaInicioExplicita, $fechaInicioPorDefecto);
            if ($fechaInicio > $fechaFin) {
                if ($fechaFinExplicita === null || $fechaFinExplicita === '') {
                    $fechaFin = $fechaInicio;
                } else {
                    $fechaInicio = $fechaFin;
                }
            }

            $especialidadFiltro = isset($filtros['especialidad']) && $filtros['especialidad'] !== '' ? (string) $filtros['especialidad'] : null;
            $medicoFiltro = isset($filtros['medico']) && $filtros['medico'] !== '' ? (string) $filtros['medico'] : null;

            $especialidades = $this->consultarEspecialidades($db);
            $medicos = $this->consultarMedicos($db);
            $vinculos = $this->consultarVinculos($db);
            $horarios = $this->consultarHorariosActivos($db);
            $bloqueos = $this->consultarBloqueosEnRango($db, $fechaInicio, $fechaFin);
            $citasDiarias = $this->consultarCitasDiarias($db, $fechaInicio, $fechaFin, $especialidadFiltro, $medicoFiltro);
            $esperaHoyRows = $this->consultarEnEsperaHoy($db, $fechaHoy, $especialidadFiltro, $medicoFiltro);

            return $this->ensamblarPanel(
                $fechaHoy,
                $fechaInicio,
                $fechaFin,
                $especialidadFiltro,
                $medicoFiltro,
                $especialidades,
                $medicos,
                $vinculos,
                $horarios,
                $bloqueos,
                $citasDiarias,
                $esperaHoyRows,
                $filtros,
            );
        } catch (QueryException $error) {
            throw ClinicalSqlException::fromQuery($error);
        }
    }

    /**
     * @param  list<array{cod_esp: string, nombre_especialidad: string, duracion_consulta_min: int, estado: string}>  $especialidades
     * @param  list<array{cod_med: string, nombre_completo: string, duracion_consulta_min: ?int, estado_medico: string, estado_usuario: string, estado_rol: string, cod_rol: string}>  $medicos
     * @param  list<array{cod_med: string, cod_esp: string, duracion_vinculo: ?int, estado_vinculo: string, nombre_especialidad: string, duracion_especialidad: int, estado_especialidad: string}>  $vinculos
     * @param  list<array{cod_med: string, dia_semana: int, hora_inicio: string, hora_fin: string, pausa_inicio: ?string, pausa_fin: ?string}>  $horarios
     * @param  list<array{cod_med: string, fecha_bloqueo: string, hora_inicio: ?string, hora_fin: ?string}>  $bloqueos
     * @param  list<array{cod_esp: string, cod_med: string, fecha: string, llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float}>  $citasDiarias
     * @param  list<array{cod_esp: string, cod_med: string, en_espera: int}>  $esperaHoyRows
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function ensamblarPanel(
        string $fechaHoy,
        string $fechaInicio,
        string $fechaFin,
        ?string $especialidadFiltro,
        ?string $medicoFiltro,
        array $especialidades,
        array $medicos,
        array $vinculos,
        array $horarios,
        array $bloqueos,
        array $citasDiarias,
        array $esperaHoyRows,
        array $filtros,
    ): array {
        $especialidadesPorCodigo = [];
        foreach ($especialidades as $esp) {
            $especialidadesPorCodigo[$esp['cod_esp']] = $esp;
        }

        $medicosPorCodigo = [];
        foreach ($medicos as $med) {
            $med['habilitado'] = $med['estado_medico'] === 'Activo'
                && $med['estado_usuario'] === 'Activo'
                && $med['estado_rol'] === 'Activo'
                && $med['cod_rol'] === 'ROL003';
            $medicosPorCodigo[$med['cod_med']] = $med;
        }

        $horariosPorMedico = [];
        foreach ($horarios as $horario) {
            $horariosPorMedico[$horario['cod_med']][] = $horario;
        }

        $bloqueosPorMedico = [];
        foreach ($bloqueos as $bloqueo) {
            $bloqueosPorMedico[$bloqueo['cod_med']][] = $bloqueo;
        }

        $horasProgramadasPorMedico = [];
        foreach ($medicosPorCodigo as $codMed => $med) {
            $horasProgramadasPorMedico[$codMed] = $this->calcularHorasEfectivasMedico(
                $horariosPorMedico[$codMed] ?? [],
                $bloqueosPorMedico[$codMed] ?? [],
                $fechaInicio,
                $fechaFin,
            );
        }

        $vinculosPorEspecialidad = [];
        $vinculosPorMedico = [];
        $vinculosActivosPorEspecialidad = [];
        $vinculosActivosPorMedico = [];
        foreach ($vinculos as $vinculo) {
            $vinculosPorEspecialidad[$vinculo['cod_esp']][] = $vinculo;
            $vinculosPorMedico[$vinculo['cod_med']][] = $vinculo;

            $medicoActivo = ($medicosPorCodigo[$vinculo['cod_med']]['habilitado'] ?? false) === true;
            if ($vinculo['estado_vinculo'] === 'Activo' && $medicoActivo) {
                $vinculosActivosPorEspecialidad[$vinculo['cod_esp']][] = $vinculo;
                $vinculosActivosPorMedico[$vinculo['cod_med']][] = $vinculo;
            }
        }

        $esperaHoyGlobal = 0;
        $esperaHoyPorEspecialidad = [];
        $esperaHoyPorMedico = [];
        foreach ($esperaHoyRows as $filaEspera) {
            $cantidad = (int) $filaEspera['en_espera'];
            $esperaHoyGlobal += $cantidad;
            $esperaHoyPorEspecialidad[$filaEspera['cod_esp']] = ($esperaHoyPorEspecialidad[$filaEspera['cod_esp']] ?? 0) + $cantidad;
            $esperaHoyPorMedico[$filaEspera['cod_med']] = ($esperaHoyPorMedico[$filaEspera['cod_med']] ?? 0) + $cantidad;
        }

        $agregadoGlobal = $this->iniciarAgregado();
        $agregadoPorEspecialidad = [];
        $agregadoPorMedico = [];
        foreach ($citasDiarias as $filaCita) {
            $codEsp = $filaCita['cod_esp'];
            $codMed = $filaCita['cod_med'];
            $agregadoGlobal = $this->acumularAgregado($agregadoGlobal, $filaCita);
            $agregadoPorEspecialidad[$codEsp] = $this->acumularAgregado(
                $agregadoPorEspecialidad[$codEsp] ?? $this->iniciarAgregado(),
                $filaCita,
            );
            $agregadoPorMedico[$codMed] = $this->acumularAgregado(
                $agregadoPorMedico[$codMed] ?? $this->iniciarAgregado(),
                $filaCita,
            );
        }

        $porEspecialidad = [];
        foreach ($especialidades as $esp) {
            $codEsp = $esp['cod_esp'];
            if ($especialidadFiltro !== null && $codEsp !== $especialidadFiltro) {
                continue;
            }

            $stats = $agregadoPorEspecialidad[$codEsp] ?? $this->iniciarAgregado();
            $activosEsp = $vinculosActivosPorEspecialidad[$codEsp] ?? [];
            if ($medicoFiltro !== null) {
                $activosEsp = array_values(array_filter(
                    $activosEsp,
                    static fn (array $v): bool => $v['cod_med'] === $medicoFiltro,
                ));
                $tieneVinculoMedico = array_filter(
                    $vinculosPorEspecialidad[$codEsp] ?? [],
                    static fn (array $v): bool => $v['cod_med'] === $medicoFiltro,
                ) !== [];
                if (! $tieneVinculoMedico && $stats['llegadas'] === 0) {
                    continue;
                }
            }

            if ($esp['estado'] !== 'Activo' && $stats['llegadas'] === 0) {
                continue;
            }

            $servidores = count($activosEsp);
            $musActivos = [];
            $sumaHorasServidores = 0.0;
            foreach ($activosEsp as $vinculo) {
                $codMed = $vinculo['cod_med'];
                $duracion = $this->resolverDuracionEfectiva(
                    $vinculo['duracion_vinculo'],
                    $medicosPorCodigo[$codMed]['duracion_consulta_min'] ?? null,
                    $esp['duracion_consulta_min'],
                );
                $musActivos[] = 60.0 / $duracion;
                $sumaHorasServidores += $horasProgramadasPorMedico[$codMed] ?? 0.0;
            }

            $muPromedio = $musActivos !== []
                ? array_sum($musActivos) / count($musActivos)
                : 60.0 / max(1, (int) $esp['duracion_consulta_min']);
            $duracionSugerida = max(5, min(180, (int) round(60.0 / $muPromedio)));

            $horasPorServidor = $servidores > 0 ? ($sumaHorasServidores / $servidores) : 0.0;
            [$horasEfectivas, $baseHoras] = $this->resolverHorasEfectivas(
                $horasPorServidor,
                $stats['llegadas'],
                count($stats['fechas']),
            );

            $lambda = $horasEfectivas > self::EPSILON ? ($stats['llegadas'] / $horasEfectivas) : 0.0;
            $metricas = $this->calcularMMc($lambda, $muPromedio, $servidores);

            $porEspecialidad[] = [
                'cod_esp' => $codEsp,
                'nombre' => $esp['nombre_especialidad'],
                'estado_catalogo' => $esp['estado'],
                'servidores' => $servidores,
                'llegadas' => $stats['llegadas'],
                'atendidas' => $stats['atendidas'],
                'horas_efectivas' => $horasEfectivas,
                'base_horas' => $baseHoras,
                'mu_promedio' => $muPromedio,
                'duracion_sugerida' => $duracionSugerida,
                'en_espera_hoy' => $esperaHoyPorEspecialidad[$codEsp] ?? 0,
                'w_empirico_minutos' => $this->promedioEmpirico($stats),
                'muestra_empirica' => $stats['muestra_empirica'],
                'metricas' => $metricas,
            ];
        }

        $porMedico = [];
        foreach ($medicosPorCodigo as $codMed => $med) {
            if ($medicoFiltro !== null && $codMed !== $medicoFiltro) {
                continue;
            }

            $stats = $agregadoPorMedico[$codMed] ?? $this->iniciarAgregado();
            $vinculosMed = $vinculosActivosPorMedico[$codMed] ?? [];
            if ($especialidadFiltro !== null) {
                $perteneceEspecialidad = false;
                foreach ($vinculosPorMedico[$codMed] ?? [] as $v) {
                    if ($v['cod_esp'] === $especialidadFiltro) {
                        $perteneceEspecialidad = true;
                        break;
                    }
                }
                if (! $perteneceEspecialidad && $stats['llegadas'] === 0) {
                    continue;
                }
            }

            if (! $med['habilitado'] && $stats['llegadas'] === 0) {
                continue;
            }

            $duracionMin = $this->resolverDuracionMedico(
                $med,
                $vinculosPorMedico[$codMed] ?? [],
                $especialidadesPorCodigo,
                $especialidadFiltro,
            );
            $mu = 60.0 / $duracionMin;

            [$horasEfectivas, $baseHoras] = $this->resolverHorasEfectivas(
                $horasProgramadasPorMedico[$codMed] ?? 0.0,
                $stats['llegadas'],
                count($stats['fechas']),
            );

            $lambda = $horasEfectivas > self::EPSILON ? ($stats['llegadas'] / $horasEfectivas) : 0.0;
            $metricas = $this->calcularMM1($lambda, $mu);

            $nombresEspecialidades = [];
            foreach ($vinculosMed as $v) {
                if ($especialidadFiltro === null || $v['cod_esp'] === $especialidadFiltro) {
                    $nombresEspecialidades[] = $v['nombre_especialidad'];
                }
            }
            if ($nombresEspecialidades === [] && $especialidadFiltro !== null && isset($especialidadesPorCodigo[$especialidadFiltro])) {
                $nombresEspecialidades[] = $especialidadesPorCodigo[$especialidadFiltro]['nombre_especialidad'];
            }

            $porMedico[] = [
                'cod_med' => $codMed,
                'nombre' => $med['nombre_completo'],
                'estado_medico' => $med['estado_medico'],
                'especialidades_texto' => $nombresEspecialidades !== [] ? implode(', ', array_unique($nombresEspecialidades)) : 'Sin especialidad activa',
                'duracion_minutos' => $duracionMin,
                'llegadas' => $stats['llegadas'],
                'atendidas' => $stats['atendidas'],
                'horas_efectivas' => $horasEfectivas,
                'base_horas' => $baseHoras,
                'en_espera_hoy' => $esperaHoyPorMedico[$codMed] ?? 0,
                'w_empirico_minutos' => $this->promedioEmpirico($stats),
                'muestra_empirica' => $stats['muestra_empirica'],
                'metricas' => $metricas,
            ];
        }

        $resumenGlobal = $this->construirResumenGlobal(
            $especialidadFiltro,
            $medicoFiltro,
            $especialidadesPorCodigo,
            $medicosPorCodigo,
            $porEspecialidad,
            $porMedico,
            $agregadoGlobal,
            $esperaHoyGlobal,
            $horasProgramadasPorMedico,
        );

        $alertas = $this->construirAlertas($porEspecialidad, $porMedico);

        $parametrosSim = $this->resolverParametrosSimulador($filtros, $resumenGlobal, $especialidades);
        $simulacion = $this->simularEscenariosComparativos(
            $parametrosSim['lambda'],
            $parametrosSim['duracion'],
            $parametrosSim['servidores'],
        );

        return [
            'fecha_clinica_hoy' => $fechaHoy,
            'filtros_aplicados' => [
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'especialidad' => $especialidadFiltro,
                'medico' => $medicoFiltro,
            ],
            'catalogos' => [
                'especialidades' => array_map(static fn (array $e): array => [
                    'codEsp' => $e['cod_esp'],
                    'nombreEspecialidad' => $e['nombre_especialidad'],
                    'duracionConsultaMin' => (int) $e['duracion_consulta_min'],
                    'estado' => $e['estado'],
                ], $especialidades),
                'medicos' => array_map(static fn (array $m): array => [
                    'codMed' => $m['cod_med'],
                    'nombreCompleto' => $m['nombre_completo'],
                    'estado' => $m['estado_medico'],
                ], $medicos),
            ],
            'resumen_global' => $resumenGlobal,
            'por_especialidad' => $porEspecialidad,
            'por_medico' => $porMedico,
            'alertas' => $alertas,
            'simulacion' => $simulacion,
            'simulacion_es_referencia' => $parametrosSim['es_referencia'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $especialidadesPorCodigo
     * @param  array<string, array<string, mixed>>  $medicosPorCodigo
     * @param  list<array<string, mixed>>  $porEspecialidad
     * @param  list<array<string, mixed>>  $porMedico
     * @param  array{llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float, fechas: array<string, bool>}  $agregadoGlobal
     * @param  array<string, float>  $horasProgramadasPorMedico
     * @return array<string, mixed>
     */
    private function construirResumenGlobal(
        ?string $especialidadFiltro,
        ?string $medicoFiltro,
        array $especialidadesPorCodigo,
        array $medicosPorCodigo,
        array $porEspecialidad,
        array $porMedico,
        array $agregadoGlobal,
        int $esperaHoyGlobal,
        array $horasProgramadasPorMedico,
    ): array {
        if ($medicoFiltro !== null && count($porMedico) === 1) {
            $fila = $porMedico[0];

            return [
                'ambito' => 'Médico: '.$fila['nombre'],
                'total_llegadas' => $fila['llegadas'],
                'total_atendidas' => $fila['atendidas'],
                'horas_efectivas' => $fila['horas_efectivas'],
                'base_horas' => $fila['base_horas'],
                'en_espera_hoy' => $esperaHoyGlobal,
                'w_empirico_minutos' => $fila['w_empirico_minutos'],
                'muestra_empirica' => $fila['muestra_empirica'],
                'metricas' => $fila['metricas'],
            ];
        }

        if ($especialidadFiltro !== null && count($porEspecialidad) === 1) {
            $fila = $porEspecialidad[0];

            return [
                'ambito' => 'Especialidad: '.$fila['nombre'],
                'total_llegadas' => $fila['llegadas'],
                'total_atendidas' => $fila['atendidas'],
                'horas_efectivas' => $fila['horas_efectivas'],
                'base_horas' => $fila['base_horas'],
                'en_espera_hoy' => $esperaHoyGlobal,
                'w_empirico_minutos' => $fila['w_empirico_minutos'],
                'muestra_empirica' => $fila['muestra_empirica'],
                'metricas' => $fila['metricas'],
            ];
        }

        $medicosActivosEnAmbito = array_values(array_filter(
            $porMedico,
            static fn (array $m): bool => ($medicosPorCodigo[$m['cod_med']]['habilitado'] ?? false) === true,
        ));

        $servidores = count($medicosActivosEnAmbito);
        $sumaHoras = 0.0;
        $sumaMu = 0.0;
        foreach ($medicosActivosEnAmbito as $filaMed) {
            $sumaHoras += $horasProgramadasPorMedico[$filaMed['cod_med']] ?? 0.0;
            $sumaMu += (float) $filaMed['metricas']['mu'];
        }

        $muPromedio = $servidores > 0 ? ($sumaMu / $servidores) : (60.0 / self::DURACION_DEFECTO_MIN);
        $horasPorServidor = $servidores > 0 ? ($sumaHoras / $servidores) : 0.0;
        [$horasEfectivas, $baseHoras] = $this->resolverHorasEfectivas(
            $horasPorServidor,
            $agregadoGlobal['llegadas'],
            count($agregadoGlobal['fechas']),
        );
        $lambda = $horasEfectivas > self::EPSILON ? ($agregadoGlobal['llegadas'] / $horasEfectivas) : 0.0;
        $metricas = $this->calcularMMc($lambda, $muPromedio, $servidores);

        $ambito = 'Clínica completa';
        if ($especialidadFiltro !== null && isset($especialidadesPorCodigo[$especialidadFiltro])) {
            $ambito = 'Especialidad: '.$especialidadesPorCodigo[$especialidadFiltro]['nombre_especialidad'];
        } elseif ($medicoFiltro !== null && isset($medicosPorCodigo[$medicoFiltro])) {
            $ambito = 'Médico: '.$medicosPorCodigo[$medicoFiltro]['nombre_completo'];
        }

        return [
            'ambito' => $ambito,
            'total_llegadas' => $agregadoGlobal['llegadas'],
            'total_atendidas' => $agregadoGlobal['atendidas'],
            'horas_efectivas' => $horasEfectivas,
            'base_horas' => $baseHoras,
            'en_espera_hoy' => $esperaHoyGlobal,
            'w_empirico_minutos' => $this->promedioEmpirico($agregadoGlobal),
            'muestra_empirica' => $agregadoGlobal['muestra_empirica'],
            'metricas' => $metricas,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $porEspecialidad
     * @param  list<array<string, mixed>>  $porMedico
     * @return list<array{ambito: string, nombre: string, modelo: string, estado: string, etiqueta: string, rho_porcentaje: ?float, servidores_actuales: int, servidores_minimos: int, mensaje: string}>
     */
    private function construirAlertas(array $porEspecialidad, array $porMedico): array
    {
        $alertas = [];

        foreach ($porEspecialidad as $fila) {
            $estado = $fila['metricas']['estado'];
            if (! in_array($estado, ['saturado', 'alerta_congestion'], true)) {
                continue;
            }
            $alertas[] = [
                'ambito' => 'Especialidad',
                'nombre' => $fila['nombre'],
                'modelo' => $fila['metricas']['modelo'],
                'estado' => $estado,
                'etiqueta' => $fila['metricas']['etiqueta'],
                'rho_porcentaje' => $fila['metricas']['rho_porcentaje'],
                'servidores_actuales' => $fila['servidores'],
                'servidores_minimos' => $fila['metricas']['servidores_minimos'],
                'mensaje' => $fila['metricas']['recomendacion'],
            ];
        }

        foreach ($porMedico as $fila) {
            $estado = $fila['metricas']['estado'];
            if (! in_array($estado, ['saturado', 'alerta_congestion'], true)) {
                continue;
            }
            $alertas[] = [
                'ambito' => 'Médico',
                'nombre' => $fila['nombre'],
                'modelo' => $fila['metricas']['modelo'],
                'estado' => $estado,
                'etiqueta' => $fila['metricas']['etiqueta'],
                'rho_porcentaje' => $fila['metricas']['rho_porcentaje'],
                'servidores_actuales' => 1,
                'servidores_minimos' => $fila['metricas']['servidores_minimos'],
                'mensaje' => $fila['metricas']['recomendacion'],
            ];
        }

        usort($alertas, static function (array $a, array $b): int {
            if ($a['estado'] !== $b['estado']) {
                return $a['estado'] === 'saturado' ? -1 : 1;
            }

            return ($b['rho_porcentaje'] ?? INF) <=> ($a['rho_porcentaje'] ?? INF);
        });

        return $alertas;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<string, mixed>  $resumenGlobal
     * @param  list<array{cod_esp: string, nombre_especialidad: string, duracion_consulta_min: int, estado: string}>  $especialidades
     * @return array{lambda: float, duracion: int, servidores: int, es_referencia: bool}
     */
    private function resolverParametrosSimulador(array $filtros, array $resumenGlobal, array $especialidades): array
    {
        $tieneSimuladorExplicito = isset($filtros['sim_lambda']) && $filtros['sim_lambda'] !== ''
            || isset($filtros['sim_duracion']) && $filtros['sim_duracion'] !== ''
            || isset($filtros['sim_servidores']) && $filtros['sim_servidores'] !== '';

        $metricasGlobales = $resumenGlobal['metricas'];
        $muGlobal = (float) ($metricasGlobales['mu'] ?? (60.0 / self::DURACION_DEFECTO_MIN));
        $duracionBase = $muGlobal > self::EPSILON
            ? max(5, min(180, (int) round(60.0 / $muGlobal)))
            : (int) ($especialidades[0]['duracion_consulta_min'] ?? self::DURACION_DEFECTO_MIN);
        $servidoresBase = max(1, min(self::MAX_SERVIDORES_SIMULADOR, (int) ($metricasGlobales['servidores'] ?? 1)));
        $lambdaGlobal = (float) ($metricasGlobales['lambda'] ?? 0.0);

        $esReferencia = false;
        if (! $tieneSimuladorExplicito && $lambdaGlobal <= self::EPSILON) {
            $lambdaBase = round($servidoresBase * (60.0 / $duracionBase) * 0.75, 2);
            $esReferencia = true;
        } else {
            $lambdaBase = round($lambdaGlobal, 2);
        }

        $simLambda = (isset($filtros['sim_lambda']) && $filtros['sim_lambda'] !== '')
            ? max(0.0, min(500.0, (float) $filtros['sim_lambda']))
            : $lambdaBase;
        $simDuracion = (isset($filtros['sim_duracion']) && $filtros['sim_duracion'] !== '')
            ? max(5, min(180, (int) $filtros['sim_duracion']))
            : $duracionBase;
        $simServidores = (isset($filtros['sim_servidores']) && $filtros['sim_servidores'] !== '')
            ? max(1, min(self::MAX_SERVIDORES_SIMULADOR, (int) $filtros['sim_servidores']))
            : $servidoresBase;

        return [
            'lambda' => $simLambda,
            'duracion' => $simDuracion,
            'servidores' => $simServidores,
            'es_referencia' => $esReferencia,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function construirResultadoEstable(
        string $modelo,
        float $lambda,
        float $mu,
        int $servidores,
        float $intensidad,
        float $rho,
        float $p0,
        float $pEspera,
        float $lq,
        float $l,
        float $wqHoras,
        float $wHoras,
        int $cMin,
    ): array {
        if ($rho > 0.85) {
            $estado = 'alerta_congestion';
            $etiqueta = 'Alerta: Alta Congestión';
            $recomendacion = sprintf(
                'La utilización alcanza %.1f%% (> 85%%). Evalúa habilitar %d médicos activos o revisar la duración de consulta para reducir la espera en cola.',
                $rho * 100.0,
                $servidores + 1,
            );
        } elseif ($rho >= 0.70) {
            $estado = 'estable';
            $etiqueta = 'Estable (Carga Moderada)';
            $recomendacion = sprintf(
                'Operación estable con %.1f%% de utilización. Monitorea picos horarios para evitar superar el umbral de congestión del 85%%.',
                $rho * 100.0,
            );
        } else {
            $estado = 'optimo';
            $etiqueta = 'Óptimo / Holgado';
            $recomendacion = sprintf(
                'Capacidad holgada con %.1f%% de utilización y espera teórica estimada de %.1f min.',
                $rho * 100.0,
                $wqHoras * 60.0,
            );
        }

        return [
            'modelo' => $modelo,
            'lambda' => $lambda,
            'mu' => $mu,
            'servidores' => $servidores,
            'capacidad_total' => $servidores * $mu,
            'intensidad_trafico' => $intensidad,
            'rho' => $rho,
            'rho_porcentaje' => $rho * 100.0,
            'es_estable' => true,
            'estado' => $estado,
            'etiqueta' => $etiqueta,
            'p0' => $p0,
            'p0_porcentaje' => $p0 * 100.0,
            'p_espera' => $pEspera,
            'p_espera_porcentaje' => $pEspera * 100.0,
            'lq' => $lq,
            'l' => $l,
            'wq_horas' => $wqHoras,
            'wq_minutos' => $wqHoras * 60.0,
            'w_horas' => $wHoras,
            'w_minutos' => $wHoras * 60.0,
            'servidores_minimos' => $cMin,
            'recomendacion' => $recomendacion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resultadoSinFlujo(string $modelo, float $lambda, float $mu, int $servidores): array
    {
        $capacidad = $servidores * $mu;
        $rho = ($mu > self::EPSILON && $servidores > 0) ? 0.0 : null;

        return [
            'modelo' => $modelo,
            'lambda' => $lambda,
            'mu' => $mu,
            'servidores' => $servidores,
            'capacidad_total' => $capacidad,
            'intensidad_trafico' => $mu > self::EPSILON ? 0.0 : null,
            'rho' => $rho,
            'rho_porcentaje' => $rho !== null ? 0.0 : null,
            'es_estable' => false,
            'estado' => 'sin_flujo',
            'etiqueta' => 'Sin flujo suficiente',
            'p0' => null,
            'p0_porcentaje' => null,
            'p_espera' => null,
            'p_espera_porcentaje' => null,
            'lq' => null,
            'l' => null,
            'wq_horas' => null,
            'wq_minutos' => null,
            'w_horas' => null,
            'w_minutos' => null,
            'servidores_minimos' => 0,
            'recomendacion' => 'No se registró flujo suficiente en el periodo evaluado para estimar tiempos de espera en estado estable.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resultadoSaturado(
        string $modelo,
        float $lambda,
        float $mu,
        int $servidores,
        float $intensidad,
        ?float $rho,
        int $cMin,
    ): array {
        $etiquetaMedicos = $cMin === 1 ? '1 médico activo' : $cMin.' médicos activos';
        $recomendacion = sprintf(
            'Con una llegada de %.2f pac/h y servicio de %.2f pac/h por médico, se requieren al menos %s para estabilizar la cola (ρ < 100%%).',
            $lambda,
            $mu,
            $etiquetaMedicos,
        );

        return [
            'modelo' => $modelo,
            'lambda' => $lambda,
            'mu' => $mu,
            'servidores' => $servidores,
            'capacidad_total' => $servidores * $mu,
            'intensidad_trafico' => $intensidad,
            'rho' => $rho,
            'rho_porcentaje' => $rho !== null ? $rho * 100.0 : null,
            'es_estable' => false,
            'estado' => 'saturado',
            'etiqueta' => 'Saturado (Inestable)',
            'p0' => null,
            'p0_porcentaje' => null,
            'p_espera' => null,
            'p_espera_porcentaje' => null,
            'lq' => null,
            'l' => null,
            'wq_horas' => null,
            'wq_minutos' => null,
            'w_horas' => null,
            'w_minutos' => null,
            'servidores_minimos' => $cMin,
            'recomendacion' => $recomendacion,
        ];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function resolverHorasEfectivas(float $horasProgramadas, int $llegadas, int $diasConActividad): array
    {
        if ($horasProgramadas > self::EPSILON) {
            return [round($horasProgramadas, 4), 'horarios'];
        }

        if ($llegadas > 0) {
            $dias = max(1, $diasConActividad);

            return [round($dias * self::HORAS_RESPALDO_POR_DIA, 4), 'respaldo_8h'];
        }

        return [0.0, 'sin_horarios'];
    }

    private function resolverDuracionEfectiva(?int $duracionVinculo, ?int $duracionMedico, int $duracionEspecialidad): int
    {
        $duracion = $duracionVinculo ?? $duracionMedico ?? $duracionEspecialidad;

        return max(1, $duracion);
    }

    /**
     * @param  array<string, mixed>  $medico
     * @param  list<array<string, mixed>>  $vinculosMedico
     * @param  array<string, array<string, mixed>>  $especialidadesPorCodigo
     */
    private function resolverDuracionMedico(
        array $medico,
        array $vinculosMedico,
        array $especialidadesPorCodigo,
        ?string $especialidadFiltro,
    ): int {
        if ($especialidadFiltro !== null) {
            foreach ($vinculosMedico as $v) {
                if ($v['cod_esp'] === $especialidadFiltro) {
                    return $this->resolverDuracionEfectiva(
                        $v['duracion_vinculo'],
                        $medico['duracion_consulta_min'],
                        (int) $v['duracion_especialidad'],
                    );
                }
            }
            if (isset($especialidadesPorCodigo[$especialidadFiltro])) {
                return $this->resolverDuracionEfectiva(
                    null,
                    $medico['duracion_consulta_min'],
                    (int) $especialidadesPorCodigo[$especialidadFiltro]['duracion_consulta_min'],
                );
            }
        }

        if ($medico['duracion_consulta_min'] !== null) {
            return max(1, (int) $medico['duracion_consulta_min']);
        }

        $duraciones = [];
        foreach ($vinculosMedico as $v) {
            if ($v['estado_vinculo'] === 'Activo') {
                $duraciones[] = $this->resolverDuracionEfectiva(
                    $v['duracion_vinculo'],
                    null,
                    (int) $v['duracion_especialidad'],
                );
            }
        }

        if ($duraciones !== []) {
            return max(1, (int) round(array_sum($duraciones) / count($duraciones)));
        }

        return self::DURACION_DEFECTO_MIN;
    }

    /**
     * @return array{llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float, fechas: array<string, bool>}
     */
    private function iniciarAgregado(): array
    {
        return [
            'llegadas' => 0,
            'atendidas' => 0,
            'muestra_empirica' => 0,
            'suma_w_empirico_min' => 0.0,
            'fechas' => [],
        ];
    }

    /**
     * @param  array{llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float, fechas: array<string, bool>}  $base
     * @param  array{fecha: string, llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float}  $fila
     * @return array{llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float, fechas: array<string, bool>}
     */
    private function acumularAgregado(array $base, array $fila): array
    {
        $llegadas = (int) $fila['llegadas'];
        $base['llegadas'] += $llegadas;
        $base['atendidas'] += (int) $fila['atendidas'];
        $base['muestra_empirica'] += (int) $fila['muestra_empirica'];
        $base['suma_w_empirico_min'] += (float) $fila['suma_w_empirico_min'];
        if ($llegadas > 0) {
            $base['fechas'][(string) $fila['fecha']] = true;
        }

        return $base;
    }

    /**
     * @param  array{muestra_empirica: int, suma_w_empirico_min: float}  $stats
     */
    private function promedioEmpirico(array $stats): ?float
    {
        if ($stats['muestra_empirica'] <= 0) {
            return null;
        }

        return $stats['suma_w_empirico_min'] / $stats['muestra_empirica'];
    }

    private function tiempoASegundos(string $tiempo): int
    {
        $partes = explode(':', $tiempo);
        $horas = (int) ($partes[0] ?? 0);
        $minutos = (int) ($partes[1] ?? 0);
        $segundos = (int) ($partes[2] ?? 0);

        return ($horas * 3600) + ($minutos * 60) + $segundos;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $segmentos
     * @return list<array{0: int, 1: int}>
     */
    private function restarIntervalo(array $segmentos, int $corteInicio, int $corteFin): array
    {
        $resultado = [];
        foreach ($segmentos as [$inicio, $fin]) {
            if ($corteFin <= $inicio || $corteInicio >= $fin) {
                $resultado[] = [$inicio, $fin];

                continue;
            }
            if ($corteInicio > $inicio) {
                $resultado[] = [$inicio, $corteInicio];
            }
            if ($corteFin < $fin) {
                $resultado[] = [$corteFin, $fin];
            }
        }

        return $resultado;
    }

    private function db(): Connection
    {
        return $this->database->connection('clinical');
    }

    private function fechaClinicaActual(Connection $db): string
    {
        $fecha = $db->scalar('SELECT public.fecha_clinica()::text');
        if (is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1) {
            return $fecha;
        }

        return (new DateTimeImmutable('now', new \DateTimeZone('America/La_Paz')))->format('Y-m-d');
    }

    private function resolverFecha(?string $valor, string $defecto): string
    {
        if ($valor !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1) {
            return $valor;
        }

        return $defecto;
    }

    private function restarDias(string $fecha, int $dias): string
    {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (! $base) {
            return $fecha;
        }

        return $base->sub(new DateInterval('P'.$dias.'D'))->format('Y-m-d');
    }

    /**
     * @return list<array{cod_esp: string, nombre_especialidad: string, duracion_consulta_min: int, estado: string}>
     */
    private function consultarEspecialidades(Connection $db): array
    {
        $rows = $db->select(
            'SELECT e.cod_esp, e.nombre_especialidad, e.duracion_consulta_min, e.estado
             FROM public.especialidades e
             ORDER BY e.nombre_especialidad, e.cod_esp',
        );

        return array_map(static fn (object $r): array => [
            'cod_esp' => (string) $r->cod_esp,
            'nombre_especialidad' => (string) $r->nombre_especialidad,
            'duracion_consulta_min' => (int) $r->duracion_consulta_min,
            'estado' => (string) $r->estado,
        ], $rows);
    }

    /**
     * @return list<array{cod_med: string, nombre_completo: string, duracion_consulta_min: ?int, estado_medico: string, estado_usuario: string, estado_rol: string, cod_rol: string}>
     */
    private function consultarMedicos(Connection $db): array
    {
        $rows = $db->select(
            'SELECT m.cod_med, u.nombre_completo, m.duracion_consulta_min,
                    m.estado AS estado_medico, u.estado AS estado_usuario,
                    r.estado AS estado_rol, u.cod_rol
             FROM public.medicos m
             JOIN public.usuarios u ON u.cod_usu = m.cod_usu
             JOIN public.roles r ON r.cod_rol = u.cod_rol
             ORDER BY u.nombre_completo, m.cod_med',
        );

        return array_map(static fn (object $r): array => [
            'cod_med' => (string) $r->cod_med,
            'nombre_completo' => (string) $r->nombre_completo,
            'duracion_consulta_min' => $r->duracion_consulta_min !== null ? (int) $r->duracion_consulta_min : null,
            'estado_medico' => (string) $r->estado_medico,
            'estado_usuario' => (string) $r->estado_usuario,
            'estado_rol' => (string) $r->estado_rol,
            'cod_rol' => (string) $r->cod_rol,
        ], $rows);
    }

    /**
     * @return list<array{cod_med: string, cod_esp: string, duracion_vinculo: ?int, estado_vinculo: string, nombre_especialidad: string, duracion_especialidad: int, estado_especialidad: string}>
     */
    private function consultarVinculos(Connection $db): array
    {
        $rows = $db->select(
            'SELECT me.cod_med, me.cod_esp, me.duracion_consulta_min AS duracion_vinculo,
                    me.estado AS estado_vinculo, e.nombre_especialidad,
                    e.duracion_consulta_min AS duracion_especialidad, e.estado AS estado_especialidad
             FROM public.medico_especialidades me
             JOIN public.especialidades e ON e.cod_esp = me.cod_esp
             ORDER BY me.cod_med, e.nombre_especialidad, me.cod_esp',
        );

        return array_map(static fn (object $r): array => [
            'cod_med' => (string) $r->cod_med,
            'cod_esp' => (string) $r->cod_esp,
            'duracion_vinculo' => $r->duracion_vinculo !== null ? (int) $r->duracion_vinculo : null,
            'estado_vinculo' => (string) $r->estado_vinculo,
            'nombre_especialidad' => (string) $r->nombre_especialidad,
            'duracion_especialidad' => (int) $r->duracion_especialidad,
            'estado_especialidad' => (string) $r->estado_especialidad,
        ], $rows);
    }

    /**
     * @return list<array{cod_med: string, dia_semana: int, hora_inicio: string, hora_fin: string, pausa_inicio: ?string, pausa_fin: ?string}>
     */
    private function consultarHorariosActivos(Connection $db): array
    {
        $rows = $db->select(
            "SELECT h.cod_med, h.dia_semana,
                    to_char(h.hora_inicio, 'HH24:MI:SS') AS hora_inicio,
                    to_char(h.hora_fin, 'HH24:MI:SS') AS hora_fin,
                    to_char(h.pausa_inicio, 'HH24:MI:SS') AS pausa_inicio,
                    to_char(h.pausa_fin, 'HH24:MI:SS') AS pausa_fin
             FROM public.horarios h
             WHERE h.estado = 'Activo'
             ORDER BY h.cod_med, h.dia_semana, h.hora_inicio",
        );

        return array_map(static fn (object $r): array => [
            'cod_med' => (string) $r->cod_med,
            'dia_semana' => (int) $r->dia_semana,
            'hora_inicio' => (string) $r->hora_inicio,
            'hora_fin' => (string) $r->hora_fin,
            'pausa_inicio' => $r->pausa_inicio !== null ? (string) $r->pausa_inicio : null,
            'pausa_fin' => $r->pausa_fin !== null ? (string) $r->pausa_fin : null,
        ], $rows);
    }

    /**
     * @return list<array{cod_med: string, fecha_bloqueo: string, hora_inicio: ?string, hora_fin: ?string}>
     */
    private function consultarBloqueosEnRango(Connection $db, string $fechaInicio, string $fechaFin): array
    {
        $rows = $db->select(
            "SELECT b.cod_med, b.fecha_bloqueo::text AS fecha_bloqueo,
                    to_char(b.hora_inicio, 'HH24:MI:SS') AS hora_inicio,
                    to_char(b.hora_fin, 'HH24:MI:SS') AS hora_fin
             FROM public.bloqueos_agenda b
             WHERE b.fecha_bloqueo BETWEEN ? AND ?
             ORDER BY b.cod_med, b.fecha_bloqueo",
            [$fechaInicio, $fechaFin],
        );

        return array_map(static fn (object $r): array => [
            'cod_med' => (string) $r->cod_med,
            'fecha_bloqueo' => (string) $r->fecha_bloqueo,
            'hora_inicio' => $r->hora_inicio !== null ? (string) $r->hora_inicio : null,
            'hora_fin' => $r->hora_fin !== null ? (string) $r->hora_fin : null,
        ], $rows);
    }

    /**
     * @return list<array{cod_esp: string, cod_med: string, fecha: string, llegadas: int, atendidas: int, muestra_empirica: int, suma_w_empirico_min: float}>
     */
    private function consultarCitasDiarias(
        Connection $db,
        string $fechaInicio,
        string $fechaFin,
        ?string $especialidad,
        ?string $medico,
    ): array {
        $sql = "SELECT c.cod_esp, c.cod_med, c.fecha::text AS fecha,
                       COUNT(*) AS llegadas,
                       COUNT(*) FILTER (WHERE c.estado = 'Atendida') AS atendidas,
                       COUNT(*) FILTER (
                           WHERE c.estado = 'Atendida'
                             AND c.hora_llegada_checkin IS NOT NULL
                             AND c.fecha_atencion IS NOT NULL
                             AND c.fecha_atencion >= c.hora_llegada_checkin
                             AND EXTRACT(EPOCH FROM (c.fecha_atencion - c.hora_llegada_checkin)) <= 43200
                       ) AS muestra_empirica,
                       COALESCE(SUM(EXTRACT(EPOCH FROM (c.fecha_atencion - c.hora_llegada_checkin)) / 60.0) FILTER (
                           WHERE c.estado = 'Atendida'
                             AND c.hora_llegada_checkin IS NOT NULL
                             AND c.fecha_atencion IS NOT NULL
                             AND c.fecha_atencion >= c.hora_llegada_checkin
                             AND EXTRACT(EPOCH FROM (c.fecha_atencion - c.hora_llegada_checkin)) <= 43200
                       ), 0) AS suma_w_empirico_min
                FROM public.citas c
                WHERE c.fecha BETWEEN ? AND ?
                  AND (c.estado IN ('En Espera', 'Atendida') OR c.hora_llegada_checkin IS NOT NULL)";

        $bindings = [$fechaInicio, $fechaFin];
        if ($especialidad !== null) {
            $sql .= ' AND c.cod_esp = ?';
            $bindings[] = $especialidad;
        }
        if ($medico !== null) {
            $sql .= ' AND c.cod_med = ?';
            $bindings[] = $medico;
        }

        $sql .= ' GROUP BY c.cod_esp, c.cod_med, c.fecha';

        $rows = $db->select($sql, $bindings);

        return array_map(static fn (object $r): array => [
            'cod_esp' => (string) $r->cod_esp,
            'cod_med' => (string) $r->cod_med,
            'fecha' => (string) $r->fecha,
            'llegadas' => (int) $r->llegadas,
            'atendidas' => (int) $r->atendidas,
            'muestra_empirica' => (int) $r->muestra_empirica,
            'suma_w_empirico_min' => (float) $r->suma_w_empirico_min,
        ], $rows);
    }

    /**
     * @return list<array{cod_esp: string, cod_med: string, en_espera: int}>
     */
    private function consultarEnEsperaHoy(
        Connection $db,
        string $fechaHoy,
        ?string $especialidad,
        ?string $medico,
    ): array {
        $sql = "SELECT c.cod_esp, c.cod_med, COUNT(*) AS en_espera
                FROM public.citas c
                WHERE c.estado = 'En Espera'
                  AND c.fecha = ?";

        $bindings = [$fechaHoy];
        if ($especialidad !== null) {
            $sql .= ' AND c.cod_esp = ?';
            $bindings[] = $especialidad;
        }
        if ($medico !== null) {
            $sql .= ' AND c.cod_med = ?';
            $bindings[] = $medico;
        }

        $sql .= ' GROUP BY c.cod_esp, c.cod_med';

        $rows = $db->select($sql, $bindings);

        return array_map(static fn (object $r): array => [
            'cod_esp' => (string) $r->cod_esp,
            'cod_med' => (string) $r->cod_med,
            'en_espera' => (int) $r->en_espera,
        ], $rows);
    }
}
