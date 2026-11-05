<?php

/**
 * Live-safe migration check (no downtime): a migration's up() must not drop or rename
 * tables or columns, or change column types, while older app servers may still use them.
 * Do it in two releases instead: stop using the column first, drop it in a later release,
 * and mark that later migration with a comment "live-safe: <why it is safe now>".
 *
 * Usage: php tools/check-migrations.php [migration files...]   (default: database/migrations/*.php)
 */
$files = array_slice($argv, 1) ?: glob(__DIR__.'/../database/migrations/*.php');

$patterns = [
    '/->dropColumn\s*\(/' => 'drops a column',
    '/->renameColumn\s*\(/' => 'renames a column',
    '/Schema::(drop|dropIfExists)\s*\(/' => 'drops a table',
    '/Schema::rename\s*\(/' => 'renames a table',
    '/->change\s*\(\s*\)/' => 'changes a column type',
    '/->dropPrimary\s*\(|->dropUnique\s*\(/' => 'drops a key',
];

$problems = [];
foreach ($files as $file) {
    $code = file_get_contents($file);
    if (preg_match('/live-safe:/', $code)) {
        continue;
    }
    // Only up() matters; down() is allowed to undo.
    if (! preg_match('/function\s+up\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s', $code, $m)) {
        continue;
    }
    foreach ($patterns as $regex => $what) {
        if (preg_match($regex, $m[1])) {
            $problems[] = basename($file).": up() {$what}";
        }
    }
}

if ($problems) {
    fwrite(STDERR, "Migrations that are not safe to run while the app is live:\n  ".implode("\n  ", $problems)."\n");
    fwrite(STDERR, "Split the change over two releases, then mark the later migration with 'live-safe: <reason>'.\n");
    exit(1);
}

echo 'Migrations are live-safe ('.count($files)." checked).\n";
