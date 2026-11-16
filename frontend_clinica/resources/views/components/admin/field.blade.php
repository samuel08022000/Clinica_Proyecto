@props(['name', 'label', 'type' => 'text', 'value' => '', 'required' => false, 'help' => null, 'options' => [], 'id' => null])
@php
    $id = $id ?? str_replace(['.', '[', ']'], '-', $name);
    $invalid = $errors->has($name);
    $current = $type === 'password' ? '' : old($name, $value);
    $described = trim(($help ? $id.'-help ' : '').($invalid ? $id.'-error' : ''));
@endphp
<div class="a-field">
    <label for="{{ $id }}">{{ $label }}@if($required) <span aria-hidden="true">*</span><span class="sr-only"> (obligatorio)</span>@endif</label>
    @if($type === 'select')
        <select name="{{ $name }}" id="{{ $id }}" @required($required) @if($invalid) aria-invalid="true" @endif @if($described) aria-describedby="{{ $described }}" @endif {{ $attributes }}>
            @foreach($options as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
        </select>
    @elseif($type === 'textarea')
        <textarea name="{{ $name }}" id="{{ $id }}" @required($required) @if($invalid) aria-invalid="true" @endif @if($described) aria-describedby="{{ $described }}" @endif {{ $attributes }}>{{ $current }}</textarea>
    @else
        <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" value="{{ $current }}" @required($required) @if($invalid) aria-invalid="true" @endif @if($described) aria-describedby="{{ $described }}" @endif {{ $attributes }}>
    @endif
    @if($help)<p id="{{ $id }}-help" class="a-help">{{ $help }}</p>@endif
    @error($name)<p id="{{ $id }}-error" class="a-field-error">{{ $message }}</p>@enderror
</div>
