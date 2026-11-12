@if(session('success'))<x-admin.notice type="success">{{ session('success') }}</x-admin.notice>@endif
@if($errors->any())
    <x-admin.notice type="error" tabindex="-1" data-error-summary aria-labelledby="admin-error-title">
        <h2 id="admin-error-title">{{ session('api_status') === 409 ? 'La operación tiene un conflicto' : 'Revisa la solicitud' }}</h2>
        <ul>
            @foreach($errors->messages() as $field => $messages)
                @foreach($messages as $message)
                    <li>@if($field === 'api'){{ $message }}@else<a href="#{{ str_replace(['[', ']'], '-', explode('.', $field)[0]) }}" data-error-field="{{ explode('.', $field)[0] }}">{{ $message }}</a>@endif</li>
                @endforeach
            @endforeach
        </ul>
    </x-admin.notice>
@endif
