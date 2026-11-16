@props(['message', 'id' => 'confirmacion', 'showErrors' => true])
<div>
    <label class="a-check"><input id="{{ $id }}" type="checkbox" name="confirmacion" value="1" required @if($showErrors && $errors->has('confirmacion')) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif><span>{{ $message }}</span></label>
    @if($showErrors)
        @error('confirmacion')<p id="{{ $id }}-error" class="a-field-error">{{ $message }}</p>@enderror
    @endif
</div>
