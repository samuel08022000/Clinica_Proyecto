@extends('administracion.pdf.layout')
@section('pdf_title', 'Bitácora de '.mb_strtolower($title))
@section('pdf_content')
    <div class="scope">
        <p><strong>Período:</strong> {{ $filters['desde'] }} al {{ $filters['hasta'] }} · <strong>Movimientos:</strong> {{ number_format($total, 0, ',', '.') }}</p>
        <p><strong>Actividad:</strong> {{ $filters['relacion'] === 'realizados' ? 'Acciones realizadas por el perfil' : 'Registros relacionados con el perfil' }}@if(!empty($filters['persona'])) · <strong>Perfil:</strong> {{ $filters['persona'] }}@endif</p>
        @if(!empty($filters['accion']) || !empty($filters['entidad']) || !empty($filters['q']))
            <p>@if(!empty($filters['accion']))<strong>Acción:</strong> {{ ['INSERT' => 'Registro', 'UPDATE' => 'Actualización', 'DELETE' => 'Eliminación'][$filters['accion']] }} @endif @if(!empty($filters['entidad']))<strong>Registro:</strong> {{ \App\Services\Administration\BitacoraService::ENTITIES[$filters['entidad']] }} @endif @if(!empty($filters['q']))<strong>Búsqueda:</strong> {{ $filters['q'] }}@endif</p>
        @endif
    </div>
    @if(count($records))
        <table><thead><tr><th style="width: 13%">Fecha y hora</th><th style="width: 18%">Perfil</th><th style="width: 17%">Responsable</th><th style="width: 17%">Movimiento</th><th style="width: 35%">Cambios auditados</th></tr></thead><tbody>
            @foreach($records as $record)
                <tr>
                    <td>{{ $record['date'] }}<small>{{ $record['id'] }}</small></td>
                    <td>@foreach($record['people'] as $person)<div>{{ $person['name'] }}<small>{{ $person['code'] }}</small></div>@endforeach</td>
                    <td>{{ $record['actor'] }}@if($record['actorCode'])<small>{{ $record['actorCode'] }}</small>@endif</td>
                    <td><strong>{{ $record['actionLabel'] }}</strong><br>{{ $record['entity'] }}<small>{{ $record['reference'] ?: 'Sin referencia' }}</small></td>
                    <td>@forelse($record['changes'] as $change)<p class="change"><strong>{{ $change['field'] }}:</strong> {{ $change['before'] }} → {{ $change['after'] }}</p>@empty<span class="muted">Sin cambios visibles en los campos auditados.</span>@endforelse</td>
                </tr>
            @endforeach
        </tbody></table>
    @else<p class="empty">No hay movimientos con los filtros seleccionados.</p>@endif
    <p class="muted">Historial de solo lectura. Este reporte incluye todos los resultados de los filtros seleccionados. No incluye credenciales ni resultados clínicos.</p>
@endsection
