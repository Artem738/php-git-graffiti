<?php

declare(strict_types=1);

// Cron entry point: php /absolute/path/to/run.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this program from the command line.');
}

require __DIR__ . '/graffiti.php';

try {
    exit(GitGraffiti\main([$argv[0], 'run', ...array_slice($argv, 1)]));
} catch (Throwable $error) {
    fwrite(STDERR, '[' . date('c') . '] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
