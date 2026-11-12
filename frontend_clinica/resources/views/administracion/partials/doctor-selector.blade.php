@props(['doctors', 'doctor' => null])
<x-admin.field name="medico" label="Médico" type="select" :value="$doctor['codMed'] ?? request('medico')" :options="['' => 'Selecciona un médico'] + collect($doctors)->mapWithKeys(fn($item) => [$item['codMed'] => $item['nombreCompleto'].' · '.$item['codMed'].' · '.$item['estado']])->all()" required />
