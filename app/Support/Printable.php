<?php

namespace App\Support;

use Dompdf\Dompdf;
use Symfony\Component\HttpFoundation\Response;

/** A printout: a PDF download, or (when the server lacks the dom extension) the same page to print from the browser. */
final class Printable
{
    public static function response(string $html, string $name): Response
    {
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        if (Compat::pdf() && config('exports.format') !== 'csv') {
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml($html);
            $pdf->setPaper('A4');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"$file.pdf\"", 'Cache-Control' => 'private, no-store']);
        }
        $bar = '<div style="font:14px sans-serif;margin:0 0 12px;padding:8px;border:1px solid #ccc;background:#f8f8f8" class="no-print">'
            .'Use your browser\'s Print (or Save as PDF). <button onclick="window.print()" style="font:inherit;padding:6px 12px">Print</button></div>'
            .'<style>@media print{.no-print{display:none}}</style>';
        $html = preg_replace('/<body([^>]*)>/i', '<body$1>'.$bar, $html, 1) ?? $html;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
