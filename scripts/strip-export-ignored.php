<?php

/**
 * Removes the files each vendor package marks "export-ignore" in its .gitattributes (tests, docs, fixtures).
 * Composer skips them when it downloads release zips, but not when it has to clone packages with git, which
 * happens where GitHub zip downloads are blocked. Usage: php scripts/strip-export-ignored.php <vendor-dir>
 */
$vendor = rtrim($argv[1] ?? '', '/');

if ($vendor === '' || ! is_dir($vendor)) {
    fwrite(STDERR, "Usage: php strip-export-ignored.php <vendor-dir>\n");
    exit(1);
}

$remove = function (string $path) use (&$remove): void {
    if (is_dir($path) && ! is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $remove("$path/$entry");
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

$removed = 0;

foreach (glob("$vendor/*/*/.gitattributes") as $attributes) {
    $package = dirname($attributes);

    foreach (file($attributes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = preg_split('/\s+/', trim($line));

        if (count($parts) < 2 || $parts[0][0] === '#' || ! in_array('export-ignore', $parts, true)) {
            continue;
        }

        $pattern = trim($parts[0], '/');

        // Never remove code the autoloader needs.
        if ($pattern === '' || in_array($pattern, ['src', 'composer.json'], true)) {
            continue;
        }

        foreach (glob("$package/$pattern", GLOB_NOSORT) ?: [] as $match) {
            // Patterns like ".*" also match "." and "..", which are the package and vendor folders.
            if (in_array(basename($match), ['.', '..'], true)) {
                continue;
            }

            $remove($match);
            $removed++;
        }
    }
}

echo "Removed $removed export-ignored paths from $vendor\n";
