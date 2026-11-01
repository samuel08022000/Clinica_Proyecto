@if (session('success'))
    <div style="background:#dcfce7; color:#166534; padding:12px 16px; border-radius:8px; margin-bottom:16px; border:1px solid #86efac;">
        <strong>¡Éxito!</strong> {{ session('success') }}
    </div>
@endif

@if ($errors->any())
    <div style="background:#fee2e2; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:16px; border:1px solid #fca5a5;">
        <strong>Aviso del Sistema:</strong>
        <ul style="margin:6px 0 0 20px;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif