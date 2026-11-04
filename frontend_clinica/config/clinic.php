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

    'endpoints' => [
        'specialties' => env('QUARKUS_SPECIALTIES_ENDPOINT'),
        'login' => env('QUARKUS_LOGIN_ENDPOINT'),
        'register' => env('QUARKUS_REGISTER_ENDPOINT'),
        'verify_otp' => env('QUARKUS_VERIFY_OTP_ENDPOINT'),
        'password_recovery' => env('QUARKUS_PASSWORD_RECOVERY_ENDPOINT'),
    ],

    'features' => [
        'google_oauth' => env('CLINIC_GOOGLE_OAUTH_ENABLED', false),
        'email_verification' => env('CLINIC_EMAIL_VERIFICATION_ENABLED', false),
    ],

    'demo' => [
        'enabled' => env('CLINIC_DEMO_ENABLED', true),
        'password' => env('CLINIC_DEMO_PASSWORD'),
        'users' => [
            ['role' => 'Paciente', 'role_code' => 'ROL001', 'name' => 'Samuel Paciente', 'email' => 'samuel.paciente@unifranz.edu.bo'],
            ['role' => 'Secretaría', 'role_code' => 'ROL002', 'name' => 'Angela Secretaria', 'email' => 'angela.secretaria@clinica.bo'],
            ['role' => 'Médico', 'role_code' => 'ROL003', 'name' => 'Dr. Dilan Doctor', 'email' => 'dilan.doctor@clinica.bo'],
            ['role' => 'Administración', 'role_code' => 'ROL004', 'name' => 'Yesid Administrador', 'email' => 'yesid.admin@clinica.bo'],
        ],
    ],
];
