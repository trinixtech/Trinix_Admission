<?php

/*
| Documents and scrutiny. The "suggested_*" lists are starting points the admin can load and then edit.
| Suggested eligibility rules are created only as drafts marked "suggested": they must be checked against
| IIFT's official admission policy and approved with a source reference before they are used (decision D23).
*/

return [
    'scanner' => env('DOCUMENT_SCANNER', 'clamav'), // clamav | eicar (development and tests only)
    'clamav' => [
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 60),
    ],
    'download_link_minutes' => 5,
    'zip' => [
        'max_files' => 50000,
        'max_unpacked_bytes' => 2 * 1024 * 1024 * 1024,
        'max_ratio' => 100,
    ],
    'url' => [
        'timeout' => 30,
        'max_redirects' => 3,
    ],
    'mime' => ['pdf' => 'application/pdf', 'jpeg' => 'image/jpeg', 'png' => 'image/png'],

    'suggested_document_types' => [
        'CLASS10' => 'Class 10 marksheet',
        'CLASS12' => 'Class 12 marksheet',
        'GRAD_MARKSHEET' => 'Graduation marksheets',
        'GRAD_DEGREE' => 'Graduation degree or provisional certificate',
        'SCORECARD' => 'CAT/IPMAT scorecard',
        'CATEGORY' => 'Category certificate (SC/ST/OBC-NCL/EWS)',
        'PWBD' => 'PwBD certificate',
        'WORK_EXP' => 'Work experience letter',
        'PHOTO_ID' => 'Photo identity proof',
    ],

    // By course level: [document code, required, condition]

];
