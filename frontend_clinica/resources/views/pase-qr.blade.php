<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pase de Cita {{ $cita['codCit'] }} | {{ config('clinic.identity.name', config('app.name')) }}</title>
    <style>
        body { margin: 0; padding: 24px 16px; background: #f1f6f7; color: #0b233f; font-family: system-ui, -apple-system, sans-serif; }
        .card { max-width: 460px; margin: 0 auto; background: #fff; border: 1px solid #dce5e8; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(11,35,63,.06); }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; background: #e4f3ed; color: #176c55; }
        .badge.warn { background: #fef3c7; color: #92400e; }
        h1 { margin: 12px 0 4px; font-size: 22px; }
        .code { font-size: 14px; color: #087f83; font-weight: 700; margin-bottom: 20px; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 11px 0; border-top: 1px solid #dce5e8; font-size: 14px; }
        .label { color: #526574; }
        .val { font-weight: 600; text-align: right; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge {{ $cita['estado'] !== 'Confirmada' || $cita['esVencida'] ? 'warn' : '' }}">
            {{ $cita['esVencida'] && $cita['estado'] === 'Confirmada' ? 'Cita vencida' : $cita['estado'] }}
        </span>
        <h1>Pase de Cita Médica</h1>
        <div class="code">Código: {{ $cita['codCit'] }}</div>

        <div class="row"><span class="label">Paciente</span><span class="val">{{ $cita['nombrePaciente'] }}</span></div>
        @if(!empty($cita['ci']))
        <div class="row"><span class="label">CI</span><span class="val">{{ $cita['ci'] }}</span></div>
        @endif
        <div class="row"><span class="label">Especialidad</span><span class="val">{{ $cita['nombreEspecialidad'] }}</span></div>
        <div class="row"><span class="label">Médico</span><span class="val">{{ $cita['nombreMedico'] }}</span></div>
        <div class="row"><span class="label">Fecha</span><span class="val">{{ $cita['fecha'] }}</span></div>
        <div class="row"><span class="label">Horario</span><span class="val">{{ substr($cita['horaInicio'], 0, 5) }} - {{ substr($cita['horaFin'], 0, 5) }}</span></div>
    </div>
</body>
</html>