@extends('layouts.administracion')
@section('admin_title', 'Bitácoras')
@section('breadcrumb')Bitácoras<span aria-hidden="true">/</span>{{ $title }}@endsection
@section('admin_content')
    @php
        $exportFilters = array_diff_key($filters, ['page' => true]);
        $profileLabel = match($section) { 'pacientes' => 'paciente', 'medicos' => 'médico', 'secretarias' => 'secretario' };
        $actions = ['' => 'Todas las acciones', 'INSERT' => 'Registro', 'UPDATE' => 'Actualización', 'DELETE' => 'Eliminación'];
    @endphp
    <x-admin.heading title="Bitácoras" description="Historial de cambios y responsables de la actividad de la clínica.">
        <x-admin.button :href="route('administracion.bitacoras.pdf', ['seccion' => $section] + $exportFilters)"><x-admin.icon name="download" />Exportar PDF</x-admin.button>
    </x-admin.heading>
    <nav class="audit-tabs" aria-label="Apartados de bitácora">
        @foreach(\App\Services\Administration\BitacoraService::SECTIONS as $key => $label)
            <a href="{{ route('administracion.bitacoras.show', ['seccion' => $key, 'desde' => $filters['desde'], 'hasta' => $filters['hasta']]) }}" @if($section === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('administracion.bitacoras.show', ['seccion' => $section]) }}" class="audit-filter">
        <div class="audit-filter-wide"><x-admin.field name="q" label="Buscar" :value="$filters['q'] ?? ''" placeholder="Persona, responsable o código" maxlength="120" /></div>
        <div class="audit-filter-wide"><x-admin.field name="relacion" label="Tipo de actividad" type="select" :value="$filters['relacion']" :options="['relacionados' => 'Registros relacionados con el perfil', 'realizados' => 'Acciones realizadas por el perfil']" /></div>
        <x-admin.field name="desde" label="Desde" type="date" :value="$filters['desde']" />
        <x-admin.field name="hasta" label="Hasta" type="date" :value="$filters['hasta']" />
        <div class="a-actions audit-filter-wide"><x-admin.button variant="secondary">Aplicar filtros</x-admin.button><a href="{{ route('administracion.bitacoras.show', ['seccion' => $section]) }}">Limpiar</a></div>
        <details class="audit-more" @if(filled($filters['persona'] ?? null) || filled($filters['accion'] ?? null) || filled($filters['entidad'] ?? null)) open @endif>
            <summary>Más filtros</summary>
            <div class="audit-extra-fields">
                <x-admin.field name="persona" :label="'Código de '.$profileLabel" :value="$filters['persona'] ?? ''" :placeholder="match($section) { 'pacientes' => 'PAC000001', 'medicos' => 'MED000001', 'secretarias' => 'SEC000001' }" maxlength="20" />
                <x-admin.field name="accion" label="Acción" type="select" :value="$filters['accion'] ?? ''" :options="$actions" />
                <x-admin.field name="entidad" label="Registro" type="select" :value="$filters['entidad'] ?? ''" :options="['' => 'Todos los registros'] + \App\Services\Administration\BitacoraService::ENTITIES" />
            </div>
        </details>
    </form>
    <section class="audit-results" aria-labelledby="audit-results-title">
        <header class="audit-results-heading">
            <div><h2 id="audit-results-title">{{ $title }} · {{ $filters['relacion'] === 'realizados' ? 'Acciones realizadas' : 'Registros relacionados' }}</h2><p class="a-help">{{ $filters['desde'] }} al {{ $filters['hasta'] }} · Hora de Bolivia</p></div>
            <p class="audit-total"><strong>{{ number_format($total, 0, ',', '.') }}</strong> {{ $total === 1 ? 'movimiento' : 'movimientos' }}</p>
        </header>
        @if($total > \App\Services\Administration\BitacoraService::MAX_EXPORT_ROWS)
            <x-admin.notice type="warning">Para exportar todos los resultados en un PDF, reduce el período o selecciona una persona. El máximo por documento es {{ \App\Services\Administration\BitacoraService::MAX_EXPORT_ROWS }} movimientos.</x-admin.notice>
        @endif
        @if(count($records))
            <table class="audit-table">
                <caption class="sr-only">Actividad de {{ mb_strtolower($title) }}, ordenada de más reciente a más antigua</caption>
                <thead><tr><th scope="col">Fecha y hora</th><th scope="col">Perfil</th><th scope="col">Responsable</th><th scope="col">Movimiento</th><th scope="col">Cambios auditados</th></tr></thead>
                <tbody>
                    @foreach($records as $record)
                        <tr>
                            <td data-label="Fecha y hora"><time>{{ $record['date'] }}</time><small>{{ $record['id'] }}</small></td>
                            <td data-label="Perfil">@foreach($record['people'] as $person)<div class="audit-person"><a href="{{ route('administracion.'.$section.'.show', $person['code']) }}">{{ $person['name'] }}</a><small>{{ $person['code'] }}</small></div>@endforeach</td>
                            <td data-label="Responsable">{{ $record['actor'] }}@if($record['actorCode'])<small>{{ $record['actorCode'] }}</small>@endif</td>
                            <td data-label="Movimiento"><span class="audit-action">{{ $record['actionLabel'] }}</span><span>{{ $record['entity'] }}</span><small>{{ $record['reference'] ?: 'Sin referencia' }}</small></td>
                            <td data-label="Cambios auditados">
                                @if(count($record['changes']))
                                    <details class="audit-changes"><summary>{{ count($record['changes']) }} {{ count($record['changes']) === 1 ? 'campo' : 'campos' }} · Ver cambios</summary><dl>
                                        @foreach($record['changes'] as $change)<div><dt>{{ $change['field'] }}</dt><dd><span>{{ $change['before'] }}</span><span aria-label="cambió a"> → </span><strong>{{ $change['after'] }}</strong></dd></div>@endforeach
                                    </dl></details>
                                @else<p class="a-help">Sin cambios visibles en los campos auditados.</p>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-admin.pagination :page="['page' => $page, 'hayMas' => $hayMas]" :count="count($records)" />
        @else
            <div class="audit-empty"><h3>{{ $total > 0 ? 'Esta página no tiene movimientos.' : 'No hay movimientos con estos filtros.' }}</h3><p>{{ $total > 0 ? 'Vuelve a la primera página para consultar los resultados.' : 'Prueba otro período o cambia los filtros de búsqueda.' }}</p><x-admin.button variant="secondary" :href="route('administracion.bitacoras.show', ['seccion' => $section] + ($total > 0 ? $exportFilters : []))">{{ $total > 0 ? 'Primera página' : 'Restablecer filtros' }}</x-admin.button></div>
        @endif
        <p class="audit-footnote">Los movimientos se conservan como historial de solo lectura. Se muestran únicamente los campos auditables; las credenciales y los resultados clínicos no se incluyen.</p>
    </section>
@endsection
