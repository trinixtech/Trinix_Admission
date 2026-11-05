<?php

function runCheck(string $up): array
{
    $file = tempnam(sys_get_temp_dir(), 'mig').'.php';
    file_put_contents($file, "<?php\nreturn new class {\n    public function up(): void\n    {\n{$up}\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('x');\n    }\n};\n");
    exec(PHP_BINARY.' '.escapeshellarg(__DIR__.'/../../tools/check-migrations.php').' '.escapeshellarg($file).' 2>&1', $out, $code);
    unlink($file);

    return [$code, implode("\n", $out)];
}

it('accepts additive migrations', function () {
    [$code] = runCheck("        Schema::table('x', fn (\$t) => \$t->string('new_col')->nullable());");
    expect($code)->toBe(0);
});

it('rejects dropping or renaming columns in up()', function (string $up) {
    [$code, $out] = runCheck($up);
    expect($code)->toBe(1)->and($out)->toContain('not safe');
})->with([
    "        Schema::table('x', fn (\$t) => \$t->dropColumn('old'));",
    "        Schema::table('x', fn (\$t) => \$t->renameColumn('a', 'b'));",
    "        Schema::dropIfExists('x');",
    "        Schema::table('x', fn (\$t) => \$t->string('a', 10)->change());",
]);

it('allows an explicitly marked second-step migration', function () {
    [$code] = runCheck("        // live-safe: column unused since release 1.4\n        Schema::table('x', fn (\$t) => \$t->dropColumn('old'));");
    expect($code)->toBe(0);
});
