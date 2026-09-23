<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clínica - Pacientes</title>
    <!-- Importamos Bootstrap directo de internet (sin Node.js) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Lista de Pacientes</h1>
        <table class="table table-bordered mt-3">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                </tr>
            </thead>
            <tbody>
                <!-- Aquí iteramos los datos que llegaron de Quarkus -->
                @if(!empty($pacientes))
                    @foreach($pacientes as $paciente)
                        <tr>
                            <td>{{ $paciente['id'] }}</td>
                            <td>{{ $paciente['nombre'] }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr><td colspan="2">No hay pacientes registrados.</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</body>
</html>