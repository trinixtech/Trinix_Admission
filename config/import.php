<?php

return [
    // Default reservation categories accepted in imports (the admin-editable rule sets come in Phase 6).
    'categories' => ['GEN', 'SC', 'ST', 'OBC-NCL', 'EWS'],
    'genders' => ['Male', 'Female', 'Transgender'],
    'exams' => ['CAT', 'IPMAT', 'OTHER'],
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 100000),
    'max_file_kb' => (int) env('IMPORT_MAX_FILE_KB', 51200),
    'chunk' => 1000,
    'api_max_records' => 1000,
    'max_preferences' => 6,
    'queue' => env('IMPORT_QUEUE', 'imports'),
];
