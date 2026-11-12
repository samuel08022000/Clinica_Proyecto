@props(['headings', 'caption'])
<div class="a-table-block">
    <p class="a-table-hint">Desplaza la tabla horizontalmente para consultar todas las columnas.</p>
    <div class="a-table-region" role="region" aria-label="{{ $caption }}" tabindex="0">
        <table class="a-table">
            <caption class="sr-only">{{ $caption }}</caption>
            <thead><tr>@foreach($headings as $heading)<th scope="col" @class(['a-numeric' => in_array($heading, ['Duración', 'Citas afectadas', 'Días'], true)])>{{ $heading }}</th>@endforeach</tr></thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
</div>
