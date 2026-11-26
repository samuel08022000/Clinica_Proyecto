@extends('layouts.administracion')
@section('admin_title', 'Teoría de colas')
@section('breadcrumb', 'Teoría de colas')

@php
    $resumenMetricas = $resumen_global['metricas'];
    $simParametros = $simulacion['parametros'];
    $simActual = $simulacion['actual'];
    $simComparativa = $simulacion['comparativa'];

    $especialidadOptions = ['' => 'Todas las especialidades'] + collect($catalogos['especialidades'])
        ->mapWithKeys(fn (array $item): array => [
            $item['codEsp'] => $item['nombreEspecialidad'].' · '.$item['duracionConsultaMin'].' min'.($item['estado'] !== 'Activo' ? ' ('.$item['estado'].')' : ''),
        ])
        ->all();

    $medicoOptions = ['' => 'Todos los médicos'] + collect($catalogos['medicos'])
        ->mapWithKeys(fn (array $item): array => [
            $item['codMed'] => $item['nombreCompleto'].' · '.$item['codMed'].' · '.$item['estado'],
        ])
        ->all();

    $fmtCola = static function (?float $valor, string $estado, int $decimales = 2, string $sufijo = ''): string {
        if ($valor !== null) {
            return number_format($valor, $decimales, '.', '').$sufijo;
        }

        return $estado === 'saturado' ? 'Saturado (∞)' : 'N/A';
    };

    $fmtProb = static function (?float $valor, string $estado): string {
        if ($valor !== null) {
            return number_format($valor, 1, '.', '').'%';
        }

        return $estado === 'saturado' ? '—' : 'N/A';
    };

    $fmtRho = static function (?float $valor, string $estado): string {
        if ($valor !== null) {
            return number_format($valor, 1, '.', '').'%';
        }

        return $estado === 'saturado' ? 'Sin capacidad' : 'N/A';
    };

    $etiquetaBaseHoras = static function (string $base, float $horas): string {
        return match ($base) {
            'horarios' => 'Horarios activos ('.number_format($horas, 1, '.', '').' h)',
            'respaldo_8h' => 'Respaldo 8 h/día ('.number_format($horas, 1, '.', '').' h)',
            default => 'Sin horas programadas',
        };
    };

    $haySaturado = collect($alertas)->contains(fn (array $alerta): bool => $alerta['estado'] === 'saturado');
    $baseFiltrosQuery = array_filter([
        'fecha_inicio' => $filtros_aplicados['fecha_inicio'],
        'fecha_fin' => $filtros_aplicados['fecha_fin'],
        'especialidad' => $filtros_aplicados['especialidad'],
        'medico' => $filtros_aplicados['medico'],
    ], static fn (mixed $v): bool => $v !== null && $v !== '');
@endphp

@section('admin_content')
    <x-admin.heading
        title="Análisis de Teoría de Colas"
        description="Evaluación de congestión, utilización y tiempos de espera mediante modelos M/M/1 y M/M/c (solo lectura)."
    >
        <x-admin.button href="#simulador-colas" variant="secondary">Simulador What-If</x-admin.button>
    </x-admin.heading>

    {{-- Filtros de análisis real --}}
    <section class="a-panel queue-section" aria-labelledby="colas-filtros-title">
        <div class="queue-section__head">
            <div>
                <h2 id="colas-filtros-title">Parámetros del periodo analizado</h2>
                <p class="a-muted a-small">
                    Consulta de solo lectura sobre llegadas efectivas (check-in / atendidas) entre
                    <strong class="a-number">{{ $filtros_aplicados['fecha_inicio'] }}</strong> y
                    <strong class="a-number">{{ $filtros_aplicados['fecha_fin'] }}</strong>
                    · Fecha clínica de referencia: <strong class="a-number">{{ $fecha_clinica_hoy }}</strong>.
                </p>
            </div>
        </div>
        <form method="GET" action="{{ route('administracion.colas.index') }}" class="a-filter queue-filter">
            <x-admin.field name="fecha_inicio" label="Fecha inicio" type="date" :value="$filtros_aplicados['fecha_inicio']" />
            <x-admin.field name="fecha_fin" label="Fecha fin" type="date" :value="$filtros_aplicados['fecha_fin']" />
            <x-admin.field name="especialidad" label="Especialidad" type="select" :value="$filtros_aplicados['especialidad'] ?? ''" :options="$especialidadOptions" />
            <x-admin.field name="medico" label="Médico" type="select" :value="$filtros_aplicados['medico'] ?? ''" :options="$medicoOptions" />
            <div class="a-actions queue-filter__actions">
                <x-admin.button type="submit">Aplicar análisis</x-admin.button>
                <x-admin.button :href="route('administracion.colas.index')" variant="secondary">Restablecer</x-admin.button>
            </div>
        </form>
    </section>

    {{-- Resumen Ejecutivo (KPIs) --}}
    <section class="a-stack" aria-labelledby="colas-kpi-title">
        <div class="queue-section__head">
            <div>
                <h2 id="colas-kpi-title">Resumen ejecutivo · {{ $resumen_global['ambito'] }}</h2>
                <p class="a-muted a-small">
                    Modelo aplicado: <strong>{{ $resumenMetricas['modelo'] }}</strong> · Base de horas: {{ $etiquetaBaseHoras($resumen_global['base_horas'], $resumen_global['horas_efectivas']) }}.
                </p>
            </div>
            <span class="queue-badge-wrap queue-badge-wrap--{{ $resumenMetricas['estado'] }}">
                <x-admin.badge :value="$resumenMetricas['etiqueta']" />
            </span>
        </div>

        <div class="queue-kpis">
            <article class="queue-kpi">
                <dl>
                    <dt>Tasa de llegada (λ)</dt>
                    <dd class="a-number">{{ number_format($resumenMetricas['lambda'], 2, '.', '') }} <span class="queue-kpi__unit">pac/h</span></dd>
                </dl>
                <small>{{ $resumen_global['total_llegadas'] }} llegadas en {{ number_format($resumen_global['horas_efectivas'], 1, '.', '') }} h efectivas</small>
            </article>

            <article class="queue-kpi">
                <dl>
                    <dt>Capacidad de servicio ({{ $resumenMetricas['servidores'] === 1 ? 'μ' : 'c·μ' }})</dt>
                    <dd class="a-number">{{ number_format($resumenMetricas['capacidad_total'], 2, '.', '') }} <span class="queue-kpi__unit">pac/h</span></dd>
                </dl>
                <small>{{ $resumenMetricas['servidores'] }} médico(s) activo(s) · μ = {{ number_format($resumenMetricas['mu'], 2, '.', '') }} pac/h</small>
            </article>

            <article class="queue-kpi queue-kpi--{{ $resumenMetricas['estado'] }}">
                <dl>
                    <dt>Utilización del sistema (ρ)</dt>
                    <dd class="a-number">{{ $fmtRho($resumenMetricas['rho_porcentaje'], $resumenMetricas['estado']) }}</dd>
                </dl>
                <small>{{ $resumenMetricas['etiqueta'] }}</small>
            </article>

            <article class="queue-kpi">
                <dl>
                    <dt>Espera promedio teórica (Wq)</dt>
                    <dd class="a-number">{{ $fmtCola($resumenMetricas['wq_minutos'], $resumenMetricas['estado'], 1, ' min') }}</dd>
                </dl>
                <small>
                    Tiempo total teórico (W): {{ $fmtCola($resumenMetricas['w_minutos'], $resumenMetricas['estado'], 1, ' min') }}
                </small>
            </article>

            <article class="queue-kpi">
                <dl>
                    <dt>Longitud promedio en cola (Lq)</dt>
                    <dd class="a-number">{{ $fmtCola($resumenMetricas['lq'], $resumenMetricas['estado'], 2) }}</dd>
                </dl>
                <small>
                    Pacientes en sistema (L): {{ $fmtCola($resumenMetricas['l'], $resumenMetricas['estado'], 2) }}
                </small>
            </article>

            <article class="queue-kpi queue-kpi--live">
                <dl>
                    <dt>En sala de espera actual (Real)</dt>
                    <dd class="a-number">{{ $resumen_global['en_espera_hoy'] }}</dd>
                </dl>
                <small>Captura {{ $espera_actual['actualizado'] ?? $fecha_clinica_hoy }} · Bolivia</small>
            </article>
        </div>
    </section>

    {{-- Panel de Diagnóstico y Alertas Automáticas --}}
    <section class="a-stack" aria-labelledby="colas-alertas-title">
        <h2 id="colas-alertas-title" class="sr-only">Diagnóstico y alertas automáticas</h2>
        @if(count($alertas) > 0)
            <x-admin.notice :type="$haySaturado ? 'error' : 'warning'">
                <h3>{{ $haySaturado ? 'Alerta operativa: saturación o congestión crítica detectada' : 'Alerta preventiva: alta congestión detectada (ρ > 85%)' }}</h3>
                <ul>
                    @foreach($alertas as $alerta)
                        <li>
                            <strong>{{ $alerta['ambito'] }} — {{ $alerta['nombre'] }}</strong>
                            ({{ $alerta['modelo'] }},
                            ρ = {{ $alerta['rho_porcentaje'] !== null ? number_format($alerta['rho_porcentaje'], 1, '.', '').'%' : 'Sin capacidad' }}):
                            {{ $alerta['mensaje'] }}
                        </li>
                    @endforeach
                </ul>
            </x-admin.notice>
        @elseif($resumenMetricas['estado'] === 'sin_flujo')
            <x-admin.notice type="info">
                <strong>Sin flujo suficiente en el periodo seleccionado.</strong>
                <span>No se registraron llegadas con check-in ni consultas atendidas entre {{ $filtros_aplicados['fecha_inicio'] }} y {{ $filtros_aplicados['fecha_fin'] }}. Amplía el rango de fechas o utiliza el Simulador «What-If» para proyectar capacidad.</span>
            </x-admin.notice>
        @else
            <x-admin.notice type="success">
                <strong>Operación dentro de parámetros estables.</strong>
                <span>Ninguna especialidad ni médico evaluado supera el umbral de alta congestión (ρ &gt; 85%) en el periodo filtrado.</span>
            </x-admin.notice>
        @endif
    </section>

    {{-- Tabla 1: Análisis por Especialidad (Modelo M/M/c) --}}
    <section class="a-stack" aria-labelledby="colas-especialidades-title">
        <div>
            <h2 id="colas-especialidades-title">Análisis por especialidad (Modelo M/M/c — Erlang C)</h2>
            <p class="a-muted a-small">
                μ corresponde al promedio aritmético de tasas de servicio de los médicos activos vinculados a cada especialidad; H efectivas representa el promedio de horas programadas por servidor activo (descontando pausas y bloqueos).
            </p>
        </div>

        @if(count($por_especialidad) === 0)
            <x-admin.empty
                title="No hay especialidades para evaluar"
                description="Ajusta los filtros de especialidad o médico para consultar el desempeño por especialidad."
            />
        @else
            <div class="queue-table-wrap">
                <x-admin.table
                    :headings="['Especialidad', 'Médicos (c)', 'Llegadas', 'λ (pac/h)', 'μ prom. (pac/h)', 'Utilización ρ', 'Prob. espera (Erlang C)', 'Cola prom. (Lq)', 'Espera prom. (Wq)', 'Tiempo total (W)', 'Diagnóstico', 'Acción']"
                    caption="Análisis de teoría de colas por especialidad usando modelo M/M/c"
                >
                    @foreach($por_especialidad as $fila)
                        @php
                            $m = $fila['metricas'];
                            $simLambdaFila = $m['lambda'] > 0 ? round($m['lambda'], 2) : round(max(1, $fila['servidores']) * $fila['mu_promedio'] * 0.75, 2);
                            $simServidoresFila = max(1, (int) $fila['servidores']);
                            $simQuery = array_merge($baseFiltrosQuery, [
                                'sim_lambda' => number_format($simLambdaFila, 2, '.', ''),
                                'sim_duracion' => $fila['duracion_sugerida'],
                                'sim_servidores' => $simServidoresFila,
                            ]);
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $fila['nombre'] }}</strong>
                                <small>{{ $fila['cod_esp'] }} · {{ $etiquetaBaseHoras($fila['base_horas'], $fila['horas_efectivas']) }}</small>
                            </td>
                            <td class="a-numeric">{{ $fila['servidores'] }}</td>
                            <td class="a-numeric">{{ $fila['llegadas'] }}</td>
                            <td class="a-numeric">{{ number_format($m['lambda'], 2, '.', '') }}</td>
                            <td class="a-numeric">{{ number_format($m['mu'], 2, '.', '') }}</td>
                            <td class="a-numeric">{{ $fmtRho($m['rho_porcentaje'], $m['estado']) }}</td>
                            <td class="a-numeric">{{ $fmtProb($m['p_espera_porcentaje'], $m['estado']) }}</td>
                            <td class="a-numeric">{{ $fmtCola($m['lq'], $m['estado'], 2) }}</td>
                            <td class="a-numeric">{{ $fmtCola($m['wq_minutos'], $m['estado'], 1, ' min') }}</td>
                            <td class="a-numeric">{{ $fmtCola($m['w_minutos'], $m['estado'], 1, ' min') }}</td>
                            <td>
                                <span class="queue-badge-wrap queue-badge-wrap--{{ $m['estado'] }}">
                                    <x-admin.badge :value="$m['etiqueta']" />
                                </span>
                                @if($m['estado'] === 'saturado' && $m['servidores_minimos'] > 0)
                                    <small>Mín. {{ $m['servidores_minimos'] }} médicos</small>
                                @endif
                            </td>
                            <td>
                                <a
                                    href="{{ route('administracion.colas.index', $simQuery) }}#simulador-colas"
                                    class="a-button a-button--secondary queue-sim-trigger"
                                    data-sim-preset
                                    data-sim-lambda="{{ number_format($simLambdaFila, 2, '.', '') }}"
                                    data-sim-duracion="{{ $fila['duracion_sugerida'] }}"
                                    data-sim-servidores="{{ $simServidoresFila }}"
                                    data-sim-label="{{ $fila['nombre'] }}"
                                >Simular</a>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        @endif
    </section>

    @if(isset($resumen_global['observado']))
        @php($observado = $resumen_global['observado'])
        <section class="a-stack" aria-labelledby="observado-title">
            <div>
                <h2 id="observado-title">Tiempos teóricos y observados</h2>
                <p class="a-muted a-small">Cada promedio usa las citas con las marcas necesarias. La duración programada usa las mismas consultas que la duración real.</p>
            </div>
            <div class="queue-table-wrap">
                <x-admin.table :headings="['Medición', 'Modelo o agenda', 'Promedio observado', 'Muestra']" caption="Comparación de tiempos del período y ámbito seleccionados">
                    @foreach([
                        ['Espera (check-in → inicio)', $resumenMetricas['wq_minutos'], $observado['espera'], $observado['muestra_espera'], $resumenMetricas['estado']],
                        ['Tiempo total (check-in → cierre)', $resumenMetricas['w_minutos'], $observado['total'], $observado['muestra_total'], $resumenMetricas['estado']],
                        ['Duración de consulta (inicio → cierre)', $observado['programada'], $observado['consulta'], $observado['muestra_consulta'], ''],
                    ] as [$medicion, $referencia, $real, $muestra, $estado])
                        <tr><th scope="row">{{ $medicion }}</th><td class="a-numeric">{{ $fmtCola($referencia, $estado, 1, ' min') }}</td><td class="a-numeric">{{ $real === null ? 'Sin muestra' : number_format($real, 1, '.', '').' min' }}</td><td class="a-numeric">{{ $muestra }}</td></tr>
                    @endforeach
                </x-admin.table>
            </div>
        </section>
    @endif

    <section class="a-stack" aria-labelledby="colas-medicos-title">
        <div>
            <h2 id="colas-medicos-title">Análisis por médico (Modelo M/M/1 — Teórico vs. Real)</h2>
            <p class="a-muted a-small">
                El <strong>W empírico: Check-in → Cierre</strong> conserva la serie histórica de atenciones con tiempos entre 0 y 12 horas. La espera observada usa el inicio real de consulta.
            </p>
        </div>

        @if(count($por_medico) === 0)
            <x-admin.empty
                title="No hay médicos para evaluar"
                description="Ajusta los filtros superiores para visualizar el análisis individual M/M/1."
            />
        @else
            <div class="queue-table-wrap">
                <x-admin.table
                    :headings="['Médico', 'Especialidad', 'Duración', 'Llegadas', 'λ (pac/h)', 'μ (pac/h)', 'Utilización ρ', 'Wq teórico', 'W teórico', 'W empírico real (Check-in → Cierre)', 'En espera actual', 'Diagnóstico']"
                    caption="Análisis individual por médico usando modelo M/M/1 comparado con el tiempo total real en clínica"
                >
                    @foreach($por_medico as $fila)
                        @php($m = $fila['metricas'])
                        <tr>
                            <td>
                                <strong>{{ $fila['nombre'] }}</strong>
                                <small>{{ $fila['cod_med'] }} · {{ $etiquetaBaseHoras($fila['base_horas'], $fila['horas_efectivas']) }}</small>
                            </td>
                            <td>{{ $fila['especialidades_texto'] }}</td>
                            <td class="a-numeric">{{ $fila['duracion_minutos'] }} min
                                @if(($fila['observado']['consulta'] ?? null) !== null)
                                    <small>Real {{ number_format($fila['observado']['consulta'], 1) }} min · n = {{ $fila['observado']['muestra_consulta'] }}</small>
                                @endif
                            </td>
                            <td class="a-numeric">{{ $fila['llegadas'] }}</td>
                            <td class="a-numeric">{{ number_format($m['lambda'], 2, '.', '') }}</td>
                            <td class="a-numeric">{{ number_format($m['mu'], 2, '.', '') }}</td>
                            <td class="a-numeric">{{ $fmtRho($m['rho_porcentaje'], $m['estado']) }}</td>
                            <td class="a-numeric">{{ $fmtCola($m['wq_minutos'], $m['estado'], 1, ' min') }}
                                @if(($fila['observado']['espera'] ?? null) !== null)
                                    <small>Real {{ number_format($fila['observado']['espera'], 1) }} min · n = {{ $fila['observado']['muestra_espera'] }}</small>
                                @endif
                            </td>
                            <td class="a-numeric">{{ $fmtCola($m['w_minutos'], $m['estado'], 1, ' min') }}</td>
                            <td class="a-numeric">
                                @if($fila['w_empirico_minutos'] !== null)
                                    {{ number_format($fila['w_empirico_minutos'], 1, '.', '') }} min
                                    <small>n = {{ $fila['muestra_empirica'] }} atendidas</small>
                                @else
                                    Sin muestra
                                @endif
                            </td>
                            <td class="a-numeric">{{ $fila['en_espera_hoy'] }}</td>
                            <td>
                                <span class="queue-badge-wrap queue-badge-wrap--{{ $m['estado'] }}">
                                    <x-admin.badge :value="$m['etiqueta']" />
                                </span>
                                @if($m['estado'] === 'saturado' && $m['servidores_minimos'] > 0)
                                    <small>Mín. {{ $m['servidores_minimos'] }} médicos</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        @endif
    </section>

    {{-- Bloque Extra: Simulador Interactivo What-If --}}
    <section id="simulador-colas" class="a-panel queue-simulator" data-queue-simulator aria-labelledby="simulador-title">
        <div class="queue-section__head">
            <div>
                <h2 id="simulador-title">Simulador interactivo «What-If» (M/M/1 y M/M/c)</h2>
                <p class="a-muted a-small">
                    Simula cambios en la demanda, duración de consulta o número de médicos sin modificar datos reales.
                    @if($simulacion_es_referencia)
                        <span>Precargado con valores ilustrativos de referencia al no existir flujo en el periodo actual.</span>
                    @endif
                </p>
            </div>
            <span class="queue-badge-wrap queue-badge-wrap--{{ $simActual['estado'] }}" data-sim-badge-wrap>
                <span class="a-badge" data-sim-badge>{{ $simActual['etiqueta'] }}</span>
            </span>
        </div>

        <p class="a-small a-muted queue-sim-preset-note" data-sim-preset-note hidden></p>

        <form method="GET" action="{{ route('administracion.colas.index') }}#simulador-colas" class="queue-sim-form" data-sim-form>
            @foreach($baseFiltrosQuery as $claveFiltro => $valorFiltro)
                <input type="hidden" name="{{ $claveFiltro }}" value="{{ $valorFiltro }}">
            @endforeach

            <div class="queue-sim-inputs">
                <x-admin.field
                    name="sim_lambda"
                    label="Tasa de llegada esperada λ (pac/h)"
                    type="number"
                    :value="number_format($simParametros['lambda'], 2, '.', '')"
                    step="0.1"
                    min="0"
                    max="500"
                    help="Pacientes que llegan por hora (ej. 5.5)."
                    data-sim-input="lambda"
                />

                <div class="queue-duration-group">
                    <x-admin.field
                        name="sim_duracion"
                        label="Duración de consulta d (min)"
                        type="number"
                        :value="$simParametros['duracion_minutos']"
                        step="1"
                        min="5"
                        max="180"
                        help="Valores habituales de clínica: 20, 30, 45 o 60 min."
                        data-sim-input="duracion"
                    />
                    <div class="queue-duration-presets" aria-label="Duraciones estándar de consulta">
                        @foreach([20, 30, 45, 60] as $duracionRapida)
                            <button type="button" class="queue-chip" data-sim-duration-chip="{{ $duracionRapida }}">{{ $duracionRapida }} min</button>
                        @endforeach
                    </div>
                </div>

                <x-admin.field
                    name="sim_servidores"
                    label="Número de médicos activos (c)"
                    type="number"
                    :value="$simParametros['servidores']"
                    step="1"
                    min="1"
                    max="50"
                    help="c = 1 aplica M/M/1; c &gt; 1 aplica M/M/c (Erlang C)."
                    data-sim-input="servidores"
                />
            </div>

            <div class="a-actions">
                <x-admin.button type="submit">Calcular escenario</x-admin.button>
            </div>
        </form>

        <div class="queue-sim-notice" data-sim-notice-container>
            <x-admin.notice
                :type="$simActual['estado'] === 'saturado' ? 'error' : ($simActual['estado'] === 'alerta_congestion' ? 'warning' : ($simActual['estado'] === 'optimo' ? 'success' : 'info'))"
                data-sim-notice
            >
                <span data-sim-recomendacion>{{ $simActual['recomendacion'] }}</span>
            </x-admin.notice>
        </div>

        <dl class="queue-sim-metrics" aria-live="polite">
            <div>
                <dt>Modelo aplicado</dt>
                <dd data-sim-out="modelo">{{ $simActual['modelo'] }}</dd>
            </div>
            <div>
                <dt>Tasa de servicio por médico (μ)</dt>
                <dd class="a-number" data-sim-out="mu">{{ number_format($simActual['mu'], 2, '.', '') }} pac/h</dd>
            </div>
            <div>
                <dt>Capacidad total (c·μ)</dt>
                <dd class="a-number" data-sim-out="capacidad">{{ number_format($simActual['capacidad_total'], 2, '.', '') }} pac/h</dd>
            </div>
            <div>
                <dt>Utilización (ρ)</dt>
                <dd class="a-number" data-sim-out="rho">{{ $fmtRho($simActual['rho_porcentaje'], $simActual['estado']) }}</dd>
            </div>
            <div>
                <dt>Prob. sistema vacío (P0)</dt>
                <dd class="a-number" data-sim-out="p0">{{ $fmtProb($simActual['p0_porcentaje'], $simActual['estado']) }}</dd>
            </div>
            <div>
                <dt>Prob. de espera (Erlang C)</dt>
                <dd class="a-number" data-sim-out="p_espera">{{ $fmtProb($simActual['p_espera_porcentaje'], $simActual['estado']) }}</dd>
            </div>
            <div>
                <dt>Cola promedio (Lq)</dt>
                <dd class="a-number" data-sim-out="lq">{{ $fmtCola($simActual['lq'], $simActual['estado'], 2) }}</dd>
            </div>
            <div>
                <dt>En sistema promedio (L)</dt>
                <dd class="a-number" data-sim-out="l">{{ $fmtCola($simActual['l'], $simActual['estado'], 2) }}</dd>
            </div>
            <div>
                <dt>Espera en cola (Wq)</dt>
                <dd class="a-number" data-sim-out="wq">{{ $fmtCola($simActual['wq_minutos'], $simActual['estado'], 1, ' min') }}</dd>
            </div>
            <div>
                <dt>Tiempo total en sistema (W)</dt>
                <dd class="a-number" data-sim-out="w">{{ $fmtCola($simActual['w_minutos'], $simActual['estado'], 1, ' min') }}</dd>
            </div>
            <div>
                <dt>Médicos mínimos estables (c mín.)</dt>
                <dd class="a-number" data-sim-out="c_min">{{ $simActual['servidores_minimos'] > 0 ? $simActual['servidores_minimos'] : 'N/A' }}</dd>
            </div>
        </dl>

        <div class="a-stack queue-sensitivity" data-sim-sensitivity>
            <div>
                <h3>¿Qué pasa si agregamos médicos? (Análisis de sensibilidad)</h3>
                <p class="a-muted a-small">Comparación del escenario elegido frente a incrementos de capacidad o al mínimo requerido para estabilizar la cola.</p>
            </div>
            <div class="queue-table-wrap">
                <x-admin.table
                    :headings="['Escenario', 'Modelo', 'Médicos (c)', 'Capacidad (c·μ)', 'Utilización ρ', 'Prob. espera', 'Cola (Lq)', 'Espera (Wq)', 'Total (W)', 'Diagnóstico']"
                    caption="Comparativa de sensibilidad al variar el número de médicos activos"
                >
                    @foreach($simComparativa as $escenario)
                        @php($em = $escenario['metricas'])
                        <tr @class(['queue-row-highlight' => $escenario['es_actual']])>
                            <td><strong>{{ $escenario['etiqueta_escenario'] }}</strong></td>
                            <td>{{ $em['modelo'] }}</td>
                            <td class="a-numeric">{{ $em['servidores'] }}</td>
                            <td class="a-numeric">{{ number_format($em['capacidad_total'], 2, '.', '') }} pac/h</td>
                            <td class="a-numeric">{{ $fmtRho($em['rho_porcentaje'], $em['estado']) }}</td>
                            <td class="a-numeric">{{ $fmtProb($em['p_espera_porcentaje'], $em['estado']) }}</td>
                            <td class="a-numeric">{{ $fmtCola($em['lq'], $em['estado'], 2) }}</td>
                            <td class="a-numeric">{{ $fmtCola($em['wq_minutos'], $em['estado'], 1, ' min') }}</td>
                            <td class="a-numeric">{{ $fmtCola($em['w_minutos'], $em['estado'], 1, ' min') }}</td>
                            <td>
                                <span class="queue-badge-wrap queue-badge-wrap--{{ $em['estado'] }}">
                                    <x-admin.badge :value="$em['etiqueta']" />
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
            </div>
        </div>
    </section>

    {{-- Referencia Teórica Plegable --}}
    <details class="a-panel queue-reference">
        <summary>Referencia teórica: Notación de Kendall (M/M/1 y M/M/c) y glosario de variables</summary>
        <div class="queue-reference__body">
            <p class="a-small a-muted">
                La clínica se modela como un sistema de colas con llegadas de Poisson (M), tiempos de servicio exponenciales (M), 1 o c médicos paralelos y disciplina de atención <strong>FIFO</strong> (orden de llegada por check-in).
            </p>
            <dl class="queue-glossary">
                <div>
                    <dt>λ (Tasa de llegada)</dt>
                    <dd>Promedio de pacientes que ingresan al flujo por hora efectiva: λ = Llegadas / H efectivas (pac/h).</dd>
                </div>
                <div>
                    <dt>μ (Tasa de servicio)</dt>
                    <dd>Pacientes atendidos por hora por un médico según duración de consulta d (min): μ = 60 / d (pac/h).</dd>
                </div>
                <div>
                    <dt>c (Servidores activos)</dt>
                    <dd>Cantidad de médicos activos en paralelo. Con c = 1 se aplica M/M/1; con c &gt; 1 se aplica M/M/c (Erlang C).</dd>
                </div>
                <div>
                    <dt>a y ρ (Tráfico y Utilización)</dt>
                    <dd>Intensidad de tráfico a = λ / μ y factor de utilización ρ = λ / (c · μ). El estado estable exige ρ &lt; 100%.</dd>
                </div>
                <div>
                    <dt>P0 y P espera (Erlang C)</dt>
                    <dd>P0 es la probabilidad de sistema vacío; P espera es la probabilidad de que un paciente deba esperar al llegar.</dd>
                </div>
                <div>
                    <dt>Lq y L (Pacientes promedio)</dt>
                    <dd>Lq es el promedio de pacientes esperando en cola; L = Lq + a incluye también a quienes están en consulta.</dd>
                </div>
                <div>
                    <dt>Wq y W (Tiempos promedio)</dt>
                    <dd>Wq es la espera promedio en cola (min); W = Wq + 1/μ es el tiempo total en clínica (espera + consulta).</dd>
                </div>
                <div>
                    <dt>c mín. (Servidores mínimos)</dt>
                    <dd>Mínimo de médicos para estabilizar un sistema saturado: c mín. = ⌊λ / μ⌋ + 1.</dd>
                </div>
            </dl>
            <p class="a-small a-muted">
                <strong>Semáforo operativo:</strong> Óptimo / Holgado (ρ &lt; 70%) · Estable (70% ≤ ρ ≤ 85%) · Alerta: Alta Congestión (85% &lt; ρ &lt; 100%) · Saturado / Inestable (ρ ≥ 100%).
            </p>
        </div>
    </details>
@endsection
