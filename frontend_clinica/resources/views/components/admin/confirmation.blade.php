@props(['message', 'id' => 'confirmacion'])
<div>
    <label class="a-check"><input id="{{ $id }}" type="checkbox" name="confirmacion" value="1" required @error('confirmacion') aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror><span>{{ $message }}</span></label>
    @error('confirmacion')<p id="{{ $id }}-error" class="a-field-error">{{ $message }}</p>@enderror
</div>
