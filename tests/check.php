<?php

declare(strict_types=1);

require dirname(__DIR__) . '/graffiti.php';

use function GitGraffiti\commitsOn;
use function GitGraffiti\configuration;
use function GitGraffiti\dateValue;
use function GitGraffiti\git;
use function GitGraffiti\initialize;
use function GitGraffiti\main;
use function GitGraffiti\readDrawing;
use function GitGraffiti\preview;
use function GitGraffiti\runJob;

$temporary = sys_get_temp_dir() . '/php-git-graffiti checks ' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$checks = 0;
$fixtures = 0;

// A fixed fixture keeps checks independent of the user's editable drawing.
const SAMPLE_DRAWING = <<<'DRAWING'
...................................
#.#.###.#...#....#.......##.###.###
#.#.#...#...#...#.#.....#....#...#.
###.##..#...#...#.#.###.#.#..#...#.
#.#.#...#...#...#.#.....#.#..#...#.
#.#.###.###.###..#.......##.###..#.
...................................
DRAWING;

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function rejects(callable $job, string $message): void
{
    try {
        $job();
    } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $message),
            "Expected '{$message}', got '{$error->getMessage()}'");
        return;
    }
    throw new RuntimeException("Expected rejection: {$message}");
}

function capture(callable $job): string
{
    ob_start();
    try {
        $job();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function fixture(array $overrides = [], string $drawing = SAMPLE_DRAWING): array
{
    global $temporary, $fixtures;
    $path = $temporary . '/fixture-' . ++$fixtures;
    mkdir($path, 0700);
    git($path, ['init', '--bare', 'remote.git']);
    git($path . '/remote.git', ['symbolic-ref', 'HEAD', 'refs/heads/main']);
    file_put_contents($path . '/DRAW-TEXT.txt', $drawing . "\n");
    $values = array_replace([
        'drawing_file' => 'DRAW-TEXT.txt',
        'start_date' => '2026-10-25',
        'timezone' => 'Europe/Kyiv',
        'commits_per_pixel' => 3,
        'remote' => $path . '/remote.git',
        'author_name' => "Test Artist ' \$(this-is-not-a-shell)",
        'author_email' => 'artist@example.org',
        'canvas_dir' => 'var/canvas',
    ], $overrides);
    $configPath = $path . '/config.php';
    file_put_contents($configPath, "<?php\nreturn " . var_export($values, true) . ";\n");
    return [configuration($configPath), $configPath, $path, $values];
}

function localCount(array $config): int
{
    $result = git($config['canvas_dir'], ['rev-parse', '--verify', 'HEAD'], [0, 128]);
    return $result['code'] === 0 ? (int) git($config['canvas_dir'], ['rev-list', '--count', 'HEAD'])['out'] : 0;
}

function remoteCount(array $config): int
{
    $result = git($config['remote'], ['rev-parse', '--verify', 'main'], [0, 128]);
    return $result['code'] === 0 ? (int) git($config['remote'], ['rev-list', '--count', 'main'])['out'] : 0;
}

function runAt(array $config, string $timestamp): string
{
    $now = new DateTimeImmutable($timestamp, $config['zone']);
    return capture(static fn () => runJob($config, $now, false, static fn () => $now));
}

function pixelCommit(array $config, int $index, string $day = '2026-10-26'): void
{
    git($config['canvas_dir'], [
        'commit', '--allow-empty', '-m', "graffiti: {$day} [{$config['id']}] {$index}/{$config['commits_per_pixel']}",
    ]);
}

function removeTemporary(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                removeTemporary($path . '/' . $name);
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

try {
    $drawingPath = $temporary . '/DRAW-TEXT.txt';
    $read = static function (string $contents) use ($drawingPath): array {
        file_put_contents($drawingPath, $contents);
        return readDrawing($drawingPath);
    };
    $rows = $read(SAMPLE_DRAWING . "\n");
    check(count($rows) === 7 && strlen($rows[0]) === 35, 'Sample must be 35 weeks by 7 days.');
    check($read(SAMPLE_DRAWING) === $rows, 'Final newline is optional.');
    check($read(str_replace("\n", "\r\n", SAMPLE_DRAWING) . "\r\n") === $rows, 'Windows line endings.');
    check($read(str_replace('.', ' ', SAMPLE_DRAWING)) === $rows, 'Spaces and dots are equivalent.');
    $freeform = $read("#.#\n#\n.\n #\n\n#\n\n");
    check($freeform === ['101', '100', '000', '010', '000', '100', '000'], 'Short and blank rows are padded on the right.');
    check($read("#\n.\n.\n.\n.\n.\n#")[6] === '1', 'Users can paint Sunday and Saturday.');
    rejects(static fn () => readDrawing($temporary . '/missing.txt'), 'Cannot read drawing file');
    rejects(static fn () => $read(''), 'exactly 7 rows');
    rejects(static fn () => $read(str_repeat(".\n", 6)), 'exactly 7 rows');
    rejects(static fn () => $read(str_repeat(".\n", 8)), 'exactly 7 rows');
    rejects(static fn () => $read("#\n.\nX\n.\n.\n.\n."), 'Drawing line 3');
    rejects(static fn () => $read("#\n\t\n.\n.\n.\n.\n."), 'use only #');
    rejects(static fn () => $read(str_repeat(".\n", 7)), 'at least one #');
    rejects(static fn () => $read(str_repeat('#', 53) . str_repeat("\n.", 6)), 'maximum is 52');
    rejects(static fn () => dateValue('2026-02-30', new DateTimeZone('UTC')), 'Invalid date');
    rejects(static fn () => dateValue('2026-1-2', new DateTimeZone('UTC')), 'Invalid date');
    check(dateValue('2028-02-29', new DateTimeZone('UTC'))->format('Y-m-d') === '2028-02-29', 'Leap date.');
    rejects(static fn () => fixture(['start_date' => '2026-10-26']), 'must be a Sunday');
    rejects(static fn () => fixture(['commits_per_pixel' => 0]), 'between 1 and 100');
    rejects(static fn () => fixture(['commits_per_pixel' => '5']), 'between 1 and 100');

    [$config, $configPath, $path] = fixture();
    check($config['canvas_dir'] === $path . '/var/canvas', 'Relative paths resolve beside config.');
    check($config['drawing_file'] === $path . '/DRAW-TEXT.txt', 'Drawing resolves beside config.');
    check($config['end']->format('Y-m-d') === '2027-06-26', 'Correct end date.');
    foreach (['2026-10-24' => 0, '2026-10-25' => 0, '2026-10-26' => 3,
        '2026-11-01' => 0, '2026-11-02' => 0, '2026-11-04' => 3,
        '2026-11-09' => 3, '2026-11-16' => 0, '2027-06-27' => 0] as $date => $expected) {
        check(commitsOn($config, dateValue($date, $config['zone'])) === $expected, "Calendar mapping {$date}.");
    }
    [$dst] = fixture(['start_date' => '2026-10-11'], ".\n#.#\n#.#\n###\n#.#\n#.#\n.");
    check(commitsOn($dst, dateValue('2026-10-26', $dst['zone'])) === 3, 'DST must not shift the week.');
    check(commitsOn($config, new DateTimeImmutable('2026-10-25T23:30:00Z')) === 3, 'Timezone selects the local day.');

    $image = capture(static fn () => preview($config));
    check(str_contains($image, '71 pixels') && str_contains($image, '35 weeks'), 'Preview totals.');
    $dry = capture(static fn () => runJob($config, dateValue('2026-10-26', $config['zone']), true));
    check(str_contains($dry, '3 planned commits') && !file_exists($path . '/var'), 'Dry run creates no lock or canvas.');
    $csv = capture(static fn () => main(['graffiti.php', 'plan', '--config=' . $configPath]));
    check(str_starts_with($csv, "date,commits\n2026-10-25,0\n"), 'Plan is valid CSV with Sunday first.');
    check(count(explode("\n", trim($csv))) === 246, 'CSV has one header and 245 days.');
    rejects(static fn () => main(['graffiti.php', 'run', '--date=2026-10-26', '--config=' . $configPath]), 'only available with --dry-run');
    rejects(static fn () => main(['graffiti.php', 'preview', '--dry-run']), 'Unknown or misplaced');

    capture(static fn () => initialize($config));
    check(localCount($config) === 0 && remoteCount($config) === 0, 'Init makes no commits or pushes.');
    check(str_contains(capture(static fn () => initialize($config)), 'already initialized'), 'Init is idempotent.');
    check(str_contains(runAt($config, '2026-10-26T12:00:00'), 'created 3'), 'Creates today’s pixels.');
    check(localCount($config) === 3 && remoteCount($config) === 3, 'Three commits pushed.');
    check(str_contains(runAt($config, '2026-10-26T13:00:00'), 'created 0'), 'Second run creates no duplicates.');
    check(localCount($config) === 3 && remoteCount($config) === 3, 'Repeat keeps local and remote counts.');
    check(git($config['canvas_dir'], ['log', '-1', '--format=%ae'])['out'] === 'artist@example.org', 'Configured Git identity.');
    check(git($config['canvas_dir'], ['log', '-1', '--format=%aI'])['out'] === '2026-10-26T12:00:00+02:00', 'Author date has the configured offset.');
    check(git($config['canvas_dir'], ['ls-tree', '--name-only', 'HEAD'])['out'] === '', 'Canvas commits have no files.');

    file_put_contents($config['canvas_dir'] . '/unexpected.txt', 'keep this file');
    rejects(static fn () => runAt($config, '2026-10-26T14:00:00'), 'uncommitted files');
    check(file_get_contents($config['canvas_dir'] . '/unexpected.txt') === 'keep this file', 'Dirty files are preserved.');
    unlink($config['canvas_dir'] . '/unexpected.txt');

    $lock = fopen(dirname($config['canvas_dir']) . '/.canvas.lock', 'c');
    flock($lock, LOCK_EX);
    rejects(static fn () => runAt($config, '2026-10-26T14:00:00'), 'Another job is running');
    flock($lock, LOCK_UN);
    fclose($lock);

    [$changed] = fixture(['remote' => $config['remote']]);
    rejects(static fn () => initialize($changed), 'Remote already has branches');
    check(localCount($config) === 3, 'Init cannot replace an existing remote.');

    [$nonempty] = fixture();
    mkdir($nonempty['canvas_dir'], 0700, true);
    file_put_contents($nonempty['canvas_dir'] . '/keep.txt', 'keep');
    rejects(static fn () => initialize($nonempty), 'canvas_dir must be empty');
    check(file_exists($nonempty['canvas_dir'] . '/keep.txt'), 'Init preserves existing directories.');

    [$partial] = fixture();
    capture(static fn () => initialize($partial));
    pixelCommit($partial, 1);
    pixelCommit($partial, 2);
    check(str_contains(runAt($partial, '2026-10-26T12:00:00'), 'created 1'), 'Partial run creates only the missing commit.');
    check(localCount($partial) === 3 && remoteCount($partial) === 3, 'Partial run completes without duplicates.');

    [$duplicate] = fixture();
    capture(static fn () => initialize($duplicate));
    pixelCommit($duplicate, 1);
    pixelCommit($duplicate, 1);
    rejects(static fn () => runAt($duplicate, '2026-10-26T12:00:00'), 'Unexpected or duplicate');
    check(localCount($duplicate) === 2 && remoteCount($duplicate) === 0, 'Duplicate history is preserved for inspection.');

    [$outOfRange] = fixture();
    capture(static fn () => initialize($outOfRange));
    pixelCommit($outOfRange, 4);
    rejects(static fn () => runAt($outOfRange, '2026-10-26T12:00:00'), 'Unexpected or duplicate');

    [$failed] = fixture();
    capture(static fn () => initialize($failed));
    $hook = $failed['remote'] . '/hooks/pre-receive';
    file_put_contents($hook, "#!/bin/sh\nexit 1\n");
    chmod($hook, 0700);
    rejects(static fn () => runAt($failed, '2026-10-26T12:00:00'), 'Git exited');
    check(localCount($failed) === 3 && remoteCount($failed) === 0, 'Failed push preserves all local commits.');
    unlink($hook);
    check(str_contains(runAt($failed, '2026-10-26T13:00:00'), 'created 0'), 'Failed push retries without new commits.');
    check(remoteCount($failed) === 3, 'Retry delivers the existing commits.');

    [$background] = fixture();
    capture(static fn () => initialize($background));
    pixelCommit($background, 1);
    check(str_contains(runAt($background, '2026-11-01T12:00:00'), 'created 0'), 'Background retry makes no new commits.');
    check(remoteCount($background) === 1, 'Background day pushes previously unsent history.');

    [$midnight] = fixture();
    capture(static fn () => initialize($midnight));
    rejects(static fn () => runJob($midnight,
        new DateTimeImmutable('2026-10-26T23:59:59', $midnight['zone']), false,
        static fn () => new DateTimeImmutable('2026-10-27T00:00:01', $midnight['zone'])), 'Day changed');
    check(localCount($midnight) === 0, 'Midnight check prevents wrongly dated pixels.');

    file_put_contents($config['drawing_file'], str_replace('.', ' ', SAMPLE_DRAWING));
    check(configuration($configPath)['id'] === $config['id'], 'Whitespace-only drawing changes keep the same plan.');
    file_put_contents($config['drawing_file'], preg_replace('/#/', '.', SAMPLE_DRAWING, 1));
    $changedPlan = configuration($configPath);
    rejects(static fn () => runAt($changedPlan, '2026-10-26T13:00:00'), 'settings changed');
    file_put_contents($config['drawing_file'], SAMPLE_DRAWING);

    git($path, ['clone', $config['remote'], 'other-writer']);
    git($path . '/other-writer', ['-c', 'user.name=Other Writer', '-c', 'user.email=other@example.org',
        '-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', 'external change']);
    git($path . '/other-writer', ['push', 'origin', 'main']);
    rejects(static fn () => runAt($config, '2026-10-27T12:00:00'), 'unexpected history');
    check(localCount($config) === 3 && remoteCount($config) === 4, 'Remote changes are preserved without force or reset.');

    echo "PASS: {$checks} checks. Only temporary local repositories were used.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    removeTemporary($temporary);
}
