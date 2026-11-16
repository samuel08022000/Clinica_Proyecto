<?php

return [
    'identity' => [
        'name' => env('CLINIC_NAME', 'Clínica privada'),
        'short_name' => env('CLINIC_SHORT_NAME', 'Clínica'),
        'description' => env(
            'CLINIC_DESCRIPTION',
            'Atención médica y gestión de citas desde un portal claro y seguro.'
        ),
    ],

    'contact' => [
        'phone' => env('CLINIC_PHONE'),
        'email' => env('CLINIC_EMAIL'),
        'address' => env('CLINIC_ADDRESS'),
        'city' => env('CLINIC_CITY'),
    ],

    'hours' => [
        'weekdays' => env('CLINIC_HOURS_WEEKDAYS'),
        'saturday' => env('CLINIC_HOURS_SATURDAY'),
        'sunday' => env('CLINIC_HOURS_SUNDAY'),
    ],

    'features' => [
        'google_oauth' => env('CLINIC_GOOGLE_OAUTH_ENABLED', false),
        'google_client_id' => env('GOOGLE_CLIENT_ID'),
        'google_client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'google_redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://127.0.0.1:8000/auth/google/callback'),
        'email_verification' => env('CLINIC_EMAIL_VERIFICATION_ENABLED', false),
    ],

];
