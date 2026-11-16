@props(['title' => 'No hay registros', 'description' => 'Prueba con otros filtros o registra el primer elemento.'])
<div class="a-empty"><h2>{{ $title }}</h2><p>{{ $description }}</p>{{ $slot }}</div>
