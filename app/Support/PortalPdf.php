<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

class PortalPdf
{
    public static function response(string $view, array $data, string $name): Response
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setChroot([public_path('assets')]);
        $cache = storage_path('framework/pdf');
        if (! is_dir($cache)) {
            mkdir($cache, 0700, true);
        }$options->setFontCache($cache);
        $options->setTempDir($cache);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view($view, [...$data, 'logo' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('assets/gta-logo.jpg')))])->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(455, 812, 'Page {PAGE_NUM} / {PAGE_COUNT}', null, 8, [.3, .39, .47]);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$safe.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
