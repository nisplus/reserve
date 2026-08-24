<?php

declare(strict_types=1);

/**
 * Case-sensitivity guard for the autoloader.
 *
 * NTFS resolves BookingService from a file named bookingservice.php; ext4 does
 * not. A mismatch works all through development on Windows and dies on the
 * first request after deploying to the Linux host, so every class file is
 * checked here: the declared namespace + class must equal the src/ path
 * byte-for-byte.
 */

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$srcDir = APP_ROOT . DIRECTORY_SEPARATOR . 'src';
$failures = 0;
$checked = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $code = (string) file_get_contents($path);

    // helpers.php and friends declare no class; nothing to check.
    if (!preg_match('/^namespace\s+([^;]+);/m', $code, $ns)
        || !preg_match('/^(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+(\w+)/m', $code, $cls)
    ) {
        continue;
    }
    $checked++;

    $expected = $srcDir . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr(trim($ns[1]) . '\\' . $cls[1], 4)) // strip App\
        . '.php';

    // strcmp, not is_file: is_file() would pass on NTFS regardless of case.
    if (strcmp($expected, $path) !== 0) {
        fwrite(STDERR, "MISMATCH\n  declared: {$ns[1]}\\{$cls[1]}\n  file:     {$path}\n  expected: {$expected}\n");
        $failures++;
    }
}

echo $failures === 0
    ? "autoload case: OK ({$checked} classes checked)\n"
    : "autoload case: {$failures} mismatch(es)\n";
exit($failures === 0 ? 0 : 1);
