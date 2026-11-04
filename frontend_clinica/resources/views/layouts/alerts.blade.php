@if (session('success'))
    <div class="alert alert--success" role="status" aria-live="polite">
        <svg class="alert__icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="m5 12 4 4L19 6" />
        </svg>
        <div>
            <strong>Acción completada</strong>
            <p>{{ session('success') }}</p>
        </div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert--error" role="alert" aria-live="assertive" tabindex="-1" data-error-summary>
        <svg class="alert__icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 8v5m0 4h.01M4.9 20h14.2a2 2 0 0 0 1.73-3L13.73 4.7a2 2 0 0 0-3.46 0L3.17 17a2 2 0 0 0 1.73 3Z" />
        </svg>
        <div>
            <strong>Revisa la información</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
