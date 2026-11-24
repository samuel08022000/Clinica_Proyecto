<?php

namespace App\Services\Administration;

use App\Exceptions\ClinicalOperationException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class AdministrativePdfService
{
    public function audit(array $data, string $author): Response
    {
        return $this->render('administracion.pdf.bitacora', $data + ['author' => $author],
            'bitacora-'.$data['section'].'-'.$data['filters']['desde'].'-'.$data['filters']['hasta'].'.pdf', 'landscape');
    }

    public function profile(string $section, array $record, string $author): Response
    {
        $code = $record[match ($section) {
            'pacientes' => 'codPac', 'medicos' => 'codMed', 'secretarias' => 'codSec',
        }];

        return $this->render('administracion.pdf.perfil', [
            'section' => $section, 'record' => $record, 'author' => $author,
            'generatedAt' => now('America/La_Paz')->format('d/m/Y H:i:s'),
            'title' => 'Ficha de '.match ($section) {
                'pacientes' => 'paciente', 'medicos' => 'médico', 'secretarias' => 'secretario'
            },
            'code' => $code,
        ], 'ficha-'.$code.'.pdf', 'portrait');
    }

    private function render(string $view, array $data, string $filename, string $orientation): Response
    {
        try {
            $cache = storage_path('framework/cache/pdf');
            File::ensureDirectoryExists($cache);
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setIsFontSubsettingEnabled(true);
            $options->setDefaultFont('DejaVu Sans');
            $options->setTempDir($cache);
            $options->setFontCache($cache);
            $options->setChroot([public_path()]);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view($view, $data)->render(), 'UTF-8');
            $pdf->setPaper('A4', $orientation);
            $pdf->render();
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $canvas->page_text($canvas->get_width() - 140, $canvas->get_height() - 26,
                'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.32, 0.38, 0.46]);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable) {
            throw new ClinicalOperationException(503, 'No se pudo generar el PDF. Vuelve a intentarlo.', 'PDF_NO_DISPONIBLE');
        }
    }
}
