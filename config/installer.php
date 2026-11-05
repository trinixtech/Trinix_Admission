<?php

return [
    // Send every page to /install until the first Super Admin exists (Stage A). Off in automated tests.
    'enforce' => (bool) env('INSTALLER_ENFORCE', env('APP_ENV') !== 'testing'),
    // Marker written once the first Super Admin exists (each app server writes its own after seeing that admin).
    'lock' => env('INSTALLER_LOCK'),
];
