@props(['value'])
<span @class(['a-badge', 'a-badge--positive' => in_array($value, ['Activo', 'Habilitado', 'Vigente', 'Completa'], true), 'a-badge--warning' => in_array($value, ['Bloqueado_Temporal', 'Restringido', 'Pendiente', 'Incompleta'], true)])>{{ str_replace('_', ' ', $value ?? 'No informado') }}</span>
