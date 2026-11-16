@props(['validationErrors' => null])
@php($errors = $validationErrors ?? $errors)
@if(session('success'))<x-admin.notice type="success">{{ session('success') }}</x-admin.notice>@endif
@if($errors->any())
    <x-admin.notice type="error" tabindex="-1" data-error-summary aria-labelledby="admin-error-title">
        <h2 id="admin-error-title">{{ session('api_status') === 409 ? 'La operación tiene un conflicto' : 'Revisa la solicitud' }}</h2>
        <ul>
            @foreach($errors->messages() as $field => $messages)
                @php($baseField = explode('.', $field)[0])
                @php($targetId = session('error_field_ids.'.$baseField) ?? str_replace(['[', ']'], '-', $baseField))
                @foreach($messages as $message)
                    <li>@if($field === 'api'){{ $message }}@else<a href="#{{ $targetId }}" data-error-field="{{ $targetId }}">{{ $message }}</a>@endif</li>
                @endforeach
            @endforeach
        </ul>
    </x-admin.notice>
@endif
