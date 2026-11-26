<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $noticeTitle }} | {{ config('clinic.identity.name', config('app.name')) }}</title>
</head>
<body style="margin: 0; padding: 32px 16px; background-color: #f1f6f7; color: #0b233f; font-family: 'Instrument Sans', 'Helvetica Neue', Arial, sans-serif; line-height: 1.5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 580px; margin: 0 auto;">
        <!-- Cabecera institucional -->
        <tr>
            <td style="padding-bottom: 20px;">
                <span style="font-size: 18px; font-weight: 600; color: #0b233f; letter-spacing: -0.02em;">
                    {{ config('clinic.identity.name', config('app.name')) }}
                </span>
            </td>
        </tr>

        <!-- Tarjeta principal (.pac-panel) -->
        <tr>
            <td style="background-color: #ffffff; border: 1px solid #dce5e8; border-radius: 8px; padding: 32px;">
                <span style="display: inline-block; padding: 4px 12px; border-radius: 999px; background-color: #e4f3ed; color: #176c55; font-size: 13px; font-weight: 600; margin-bottom: 16px;">
                    {{ $noticeTitle }}
                </span>

                <h1 style="margin: 0 0 12px; font-size: 24px; font-weight: 600; color: #0b233f; line-height: 1.2;">
                    {{ $qrPngBinary !== '' ? 'Pase QR de asistencia' : 'Aviso de tu cita' }}
                </h1>

                <p style="margin: 0 0 24px; font-size: 15px; color: #526574;">
                    Hola <strong style="color: #0b233f;">{{ $passData['nombrePaciente'] }}</strong>. {{ $passData['mensaje'] ?? 'Tu reserva ha sido registrada. Presenta este QR en recepción para acreditar tu llegada.' }}
                </p>

                @if($qrPngBinary !== '')
                <!-- Contenedor QR (.pac-cita style) -->
                <div style="background-color: #f1f6f7; border-left: 4px solid #087f83; border-radius: 4px; padding: 24px; text-align: center; margin-bottom: 24px;">
                    <div style="display: inline-block; background-color: #ffffff; padding: 12px; border: 1px solid #dce5e8; border-radius: 4px;">
                        <img src="{{ $message->embedData($qrPngBinary, 'qr-cita.png', 'image/png') }}"
                             alt="Código QR de la cita {{ $passData['codCit'] }}"
                             width="210" height="210"
                             style="display: block; width: 210px; height: 210px;">
                    </div>
                    <p style="margin: 12px 0 0; font-size: 13px; color: #526574;">
                        Código de cita: <strong style="color: #0b233f;">{{ $passData['codCit'] }}</strong>
                    </p>
                </div>

                @endif
                <!-- Datos del paciente y de la cita (.pac-data style) -->
                <h2 style="margin: 0 0 14px; font-size: 16px; font-weight: 600; color: #0b233f;">
                    Información de la cita
                </h2>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top: 1px solid #dce5e8; font-size: 14px;">
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #526574; width: 38%;">Paciente</td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #0b233f; font-weight: 600;">{{ $passData['nombrePaciente'] }}</td>
                    </tr>
                    @if(!empty($passData['ci']))
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #526574;">Documento (CI)</td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #0b233f; font-weight: 600;">{{ $passData['ci'] }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #526574;">Especialidad</td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #0b233f; font-weight: 600;">{{ $passData['nombreEspecialidad'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #526574;">Médico</td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #0b233f; font-weight: 600;">{{ $passData['nombreMedico'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #526574;">Fecha</td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #0b233f; font-weight: 600;">{{ $passData['fecha'] }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #526574;">Horario</td>
                        <td style="padding: 10px 0; border-bottom: 1px solid #dce5e8; color: #0b233f; font-weight: 600;">{{ substr($passData['horaInicio'], 0, 5) }}@if(isset($passData['horaFin']))–{{ substr($passData['horaFin'], 0, 5) }}@endif (Bolivia)</td>
                    </tr>
                </table>

                @if($qrPngBinary !== '')
                <p style="margin: 20px 0 0; font-size: 13px; color: #526574;">
                    También encontrarás la imagen PNG del código QR adjunta a este correo para guardarla en tu dispositivo.
                </p>
                @endif
            </td>
        </tr>

        <!-- Pie de página -->
        <tr>
            <td style="padding-top: 20px; text-align: center; font-size: 12px; color: #526574;">
                Este es un mensaje automático de {{ config('clinic.identity.name', config('app.name')) }}. Por favor, no respondas a este correo.
            </td>
        </tr>
    </table>
</body>
</html>
