@props(['value'])
<span @class(['a-badge a-badge--wrap r-status', 'a-badge--positive' => in_array($value, ['En Espera', 'Atendida'], true), 'a-badge--warning' => in_array($value, ['No Asistió', 'Afectada/Pendiente Reprogramación'], true), 'a-badge--danger r-status--cancelled' => $value === 'Cancelada'])>{{ $value }}</span>
