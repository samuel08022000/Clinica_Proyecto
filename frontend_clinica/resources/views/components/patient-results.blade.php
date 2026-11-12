@props(['patients', 'destination' => 'profile'])
@if($patients === null)
    <x-admin.empty title="Busca un paciente para continuar" description="Introduce un CI, nombre o correo con al menos tres letras o números." />
@elseif(count($patients) === 0)
    <x-admin.empty title="No se encontraron pacientes" description="Comprueba el documento o utiliza otro nombre o correo." />
@else
    <p class="a-small a-muted">{{ count($patients) }} coincidencias · búsqueda limitada a 20 resultados.</p>
    <x-admin.table :headings="['Paciente', 'CI', 'Correo', 'Estado', 'Acciones']" caption="Resultados de pacientes">
        @foreach($patients as $patient)
            <tr><td>{{ $patient['nombreCompleto'] }}<small>{{ $patient['codPac'] }}</small></td><td>{{ $patient['ci'] ?? 'No informado' }}</td><td>{{ $patient['email'] }}</td><td><x-admin.badge :value="$patient['estadoCuenta']" /></td>
                <td><div class="a-actions">
                    @if($destination === 'reservation')<a href="{{ route('secretaria.reservas.create', ['codPac' => $patient['codPac']]) }}">Seleccionar paciente</a>
                    @else<a href="{{ route('secretaria.pacientes.show', $patient['codPac']) }}">Ver perfil</a><a href="{{ route('secretaria.historial.show', $patient['codPac']) }}">Historial</a><a href="{{ route('secretaria.reservas.create', ['codPac' => $patient['codPac']]) }}">Nueva reserva</a>@endif
                </div></td>
            </tr>
        @endforeach
    </x-admin.table>
@endif
