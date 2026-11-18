<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class QrPassController extends Controller
{
    public function show(string $token): Response
    {
        if ($token === '' || strlen($token) > 4096) {
            abort(404);
        }

        try {
            $row = DB::connection('clinical')->selectOne(
                <<<'SQL'
            SELECT c.cod_cit AS "codCit",
                   up.nombre_completo AS "nombrePaciente",
                   um.nombre_completo AS "nombreMedico",
                   e.nombre_especialidad AS "nombreEspecialidad",
                   c.fecha,
                   c.hora_inicio AS "horaInicio",
                   c.hora_fin AS "horaFin",
                   c.estado,
                   ((c.fecha + c.hora_fin) <= ahora_clinica()) AS "esVencida"
            FROM public.verificar_token_qr(?) q
            JOIN citas c ON c.cod_cit = q.cod_cit AND c.token_qr = ?
            JOIN pacientes p ON p.cod_pac = c.cod_pac
            JOIN usuarios up ON up.cod_usu = p.cod_usu
            JOIN medicos m ON m.cod_med = c.cod_med
            JOIN usuarios um ON um.cod_usu = m.cod_usu
            JOIN especialidades e ON e.cod_esp = c.cod_esp
            WHERE c.estado='Confirmada' AND c.fecha+c.hora_fin>ahora_clinica()
            SQL,
                [$token, $token],
            );
        } catch (QueryException) {
            abort(503, 'El pase no está disponible temporalmente.');
        }

        if (! $row) {
            abort(404, 'El pase QR no es válido o no existe.');
        }

        $cita = (array) $row;
        $png = $this->renderPassPng($cita);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="pase-'.$cita['codCit'].'.png"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function renderPassPng(array $cita): string
    {
        $w = 720;
        $h = 880;
        $img = imagecreatetruecolor($w, $h);

        // Paleta clínica
        $bg = imagecolorallocate($img, 241, 246, 247);
        $white = imagecolorallocate($img, 255, 255, 255);
        $navy = imagecolorallocate($img, 11, 35, 63);
        $teal = imagecolorallocate($img, 8, 127, 131);
        $muted = imagecolorallocate($img, 82, 101, 116);
        $border = imagecolorallocate($img, 220, 229, 232);
        $badgeBg = imagecolorallocate($img, 228, 243, 237);
        $badgeFg = imagecolorallocate($img, 23, 108, 85);

        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // Tarjeta blanca central
        imagefilledrectangle($img, 40, 40, $w - 40, $h - 40, $white);
        imagerectangle($img, 40, 40, $w - 40, $h - 40, $border);

        // Franja superior
        imagefilledrectangle($img, 40, 40, $w - 40, 130, $navy);

        $font = is_file('C:\\Windows\\Fonts\\segoeuib.ttf') ? 'C:\\Windows\\Fonts\\segoeuib.ttf' : 'C:\\Windows\\Fonts\\arialbd.ttf';
        $fontReg = is_file('C:\\Windows\\Fonts\\segoeui.ttf') ? 'C:\\Windows\\Fonts\\segoeui.ttf' : 'C:\\Windows\\Fonts\\arial.ttf';
        $hasTtf = function_exists('imagettftext') && is_file($font) && is_file($fontReg);

        $draw = function (string $text, int $x, int $y, int $size, $color, bool $bold = false) use ($img, $font, $fontReg, $hasTtf): void {
            if ($hasTtf) {
                imagettftext($img, $size, 0, $x, $y, $color, $bold ? $font : $fontReg, $text);
            } else {
                imagestring($img, $bold ? 5 : 4, $x, $y - 14, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text), $color);
            }
        };

        $clinicName = (string) config('clinic.identity.name', config('app.name'));
        $draw($clinicName, 72, 95, 18, $white, true);

        // Estado y Código
        $estado = ($cita['esVencida'] && $cita['estado'] === 'Confirmada') ? 'Cita vencida' : (string) $cita['estado'];
        imagefilledrectangle($img, 72, 155, 290, 195, $badgeBg);
        $draw(strtoupper($estado), 88, 182, 12, $badgeFg, true);

        $draw('PASE DE CITA MEDICA', 72, 240, 22, $navy, true);
        $draw('Codigo: '.$cita['codCit'], 72, 275, 16, $teal, true);

        // Filas de datos
        $rows = [
            ['Paciente', (string) $cita['nombrePaciente']],
            ['Especialidad', (string) $cita['nombreEspecialidad']],
            ['Medico', (string) $cita['nombreMedico']],
            ['Fecha', (string) $cita['fecha']],
            ['Horario', substr((string) $cita['horaInicio'], 0, 5).' - '.substr((string) $cita['horaFin'], 0, 5)],
        ];

        $y = 325;
        foreach ($rows as [$label, $value]) {
            imageline($img, 72, $y, $w - 72, $y, $border);
            $draw($label, 72, $y + 34, 13, $muted, false);
            $draw($value, 72, $y + 62, 15, $navy, true);
            $y += 82;
        }

        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }
}
