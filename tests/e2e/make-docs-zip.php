<?php

// Builds the sample documents ZIP used by the walk-through: argv[1] = output path.
$pdf = fn (string $t) => "%PDF-1.4\n% $t\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
$zip = new ZipArchive;
$zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach (['CLASS10', 'CLASS12', 'GRAD_MARKSHEET', 'SCORECARD', 'PHOTO_ID', 'CATEGORY'] as $code) {
    $zip->addFromString("PRTM0000007_$code.pdf", $pdf("PRTM0000007 $code"));
}
$zip->addFromString('PRTM0000009/CLASS10.pdf', $pdf('PRTM0000009 CLASS10'));
$zip->addFromString('PRTM0000008_CLASS10.pdf', $pdf('X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'));
$zip->close();
