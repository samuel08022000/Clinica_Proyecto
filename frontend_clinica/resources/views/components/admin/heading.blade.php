@props(['title', 'description' => null])
<header class="a-heading">
    <div><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>
    @if($slot->isNotEmpty())<div class="a-actions">{{ $slot }}</div>@endif
</header>
