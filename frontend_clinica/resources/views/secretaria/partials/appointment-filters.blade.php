@php($history = $history ?? false)
<details class="r-filters" @if(count(array_diff_key($filters, ['page' => true, 'size' => true]))) open @endif>
    <summary>Filtrar {{ $history ? 'historial' : 'citas' }}</summary>
    <form method="GET" class="a-form" data-appointment-filters action="{{ $history ? route('secretaria.historial.show', $patient['codPac']) : route('secretaria.citas.index') }}">
        <div class="a-form-grid">
            @unless($history)<x-admin.field name="fecha" label="Fecha exacta" type="date" :value="$filters['fecha'] ?? ''" help="Usa una fecha exacta o un rango; no combines ambos." />@endunless
            <x-admin.field name="desde" label="Desde" type="date" :value="$filters['desde'] ?? ''" />
            <x-admin.field name="hasta" label="Hasta" type="date" :value="$filters['hasta'] ?? ''" />
            <x-admin.field name="estado" label="Estado" type="select" :value="$filters['estado'] ?? ''" :options="['' => 'Todos los estados'] + array_combine(\App\Support\AppointmentPresentation::STATES, \App\Support\AppointmentPresentation::STATES)" />
            @unless($history)<x-admin.field name="codPac" label="Código de paciente" :value="$filters['codPac'] ?? ''" maxlength="64" /><x-admin.field name="codMed" label="Código de médico" :value="$filters['codMed'] ?? ''" maxlength="64" />@endunless
            <x-admin.field name="size" label="Registros por página" type="number" :value="$filters['size']" min="1" max="50" step="1" />
        </div>
        <div class="a-actions"><x-admin.button>Aplicar filtros</x-admin.button><x-admin.button variant="text" :href="$history ? route('secretaria.historial.show', $patient['codPac']) : route('secretaria.citas.index')">Limpiar</x-admin.button></div>
    </form>
</details>
