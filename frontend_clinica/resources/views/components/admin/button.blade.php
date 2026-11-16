@props(['href' => null, 'variant' => 'primary', 'type' => 'submit'])
@if($href)
    <a href="{{ $href }}" {{ $attributes->class(['a-button', 'a-button--'.$variant]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class(['a-button', 'a-button--'.$variant]) }}>{{ $slot }}</button>
@endif
