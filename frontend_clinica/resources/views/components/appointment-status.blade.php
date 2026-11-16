@props(['value'])
<span @class(['a-badge r-status', 'a-badge--positive' => in_array($value, ['En Espera', 'Atendida'], true), 'a-badge--warning' => in_array($value, ['No Asistió', 'Afectada/Pendiente Reprogramación'], true), 'r-status--cancelled' => $value === 'Cancelada'])>{{ $value }}</span>
