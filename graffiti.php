<?php

declare(strict_types=1);

namespace GitGraffiti;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

const ROOT = __DIR__;
const VERSION = '0.1.0';

/** Read a calendar-shaped drawing: seven rows, one column per week. */
function readDrawing(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Cannot read drawing file: {$path}.");
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Cannot read drawing file: {$path}.");
    }
    $rows = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
    // Ignore one final line ending, while preserving deliberately empty rows.
    if (end($rows) === '') {
        array_pop($rows);
    }
    if (count($rows) !== 7) {
        throw new RuntimeException('Drawing must have exactly 7 rows, Sunday through Saturday.');
    }
    foreach ($rows as $index => $row) {
        if (strspn($row, '#. ') !== strlen($row)) {
            $line = $index + 1;
            throw new RuntimeException("Drawing line {$line}: use only #, dots or spaces.");
        }
    }
    $width = max(array_map('strlen', $rows));
    if ($width > 52) {
        throw new RuntimeException("Drawing needs {$width} weeks; maximum is 52 columns.");
    }
    if (!str_contains(implode('', $rows), '#')) {
        throw new RuntimeException('Drawing must contain at least one # pixel.');
    }
    return array_map(static fn (string $row): string => strtr(
        str_pad($row, $width, '.'), ['#' => '1', '.' => '0', ' ' => '0']
    ), $rows);
}

function dateValue(string $value, DateTimeZone $zone): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("Invalid date: {$value}. Use YYYY-MM-DD.");
    }
    return $date;
}

function configuration(string $path): array
{
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration must return an array.');
    }
    foreach (['drawing_file', 'start_date', 'timezone', 'remote', 'author_name', 'author_email', 'canvas_dir'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            throw new RuntimeException("Missing or invalid setting: {$key}.");
        }
        $config[$key] = trim($config[$key]);
    }
    if (!is_int($config['commits_per_pixel'] ?? null)
        || $config['commits_per_pixel'] < 1 || $config['commits_per_pixel'] > 100) {
        throw new RuntimeException('commits_per_pixel must be an integer between 1 and 100.');
    }
    $config['zone'] = new DateTimeZone($config['timezone']);
    $config['start'] = dateValue($config['start_date'], $config['zone']);
    if ($config['start']->format('w') !== '0') {
        throw new RuntimeException('start_date must be a Sunday (the first row in GitHub’s calendar).');
    }
    if ($config['drawing_file'][0] !== '/') {
        $config['drawing_file'] = dirname($path) . '/' . $config['drawing_file'];
    }
    $config['rows'] = readDrawing($config['drawing_file']);
    $config['weeks'] = strlen($config['rows'][0]);
    $config['end'] = $config['start']->modify('+' . ($config['weeks'] * 7 - 1) . ' days');
    if ($config['canvas_dir'][0] !== '/') {
        $config['canvas_dir'] = dirname($path) . '/' . $config['canvas_dir'];
    }
    $config['id'] = substr(hash('sha256', json_encode([
        'format' => 1,
        'rows' => $config['rows'],
        'start' => $config['start_date'],
        'timezone' => $config['timezone'],
        'commits' => $config['commits_per_pixel'],
        'email' => $config['author_email'],
    ], JSON_THROW_ON_ERROR)), 0, 16);
    return $config;
}

function commitsOn(array $config, DateTimeImmutable $date): int
{
    $date = dateValue($date->setTimezone($config['zone'])->format('Y-m-d'), $config['zone']);
    if ($date < $config['start'] || $date > $config['end']) {
        return 0;
    }
    // Calendar days rather than elapsed seconds: DST days are not always 24 hours.
    $days = (int) $config['start']->diff($date)->format('%a');
    return $config['rows'][$days % 7][intdiv($days, 7)] === '1'
        ? $config['commits_per_pixel'] : 0;
}

function preview(array $config): void
{
    echo "PHP Git Graffiti · " . basename($config['drawing_file']) . "\n";
    echo "{$config['start_date']} → {$config['end']->format('Y-m-d')} · {$config['weeks']} weeks\n\n";
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $row => $day) {
        echo $day . ' ' . strtr($config['rows'][$row], ['0' => '··', '1' => '██']) . "\n";
    }
    $pixels = array_sum(array_map(static fn (string $row): int => substr_count($row, '1'), $config['rows']));
    echo "\n{$pixels} pixels · " . ($pixels * $config['commits_per_pixel']) . " planned commits\n";
}

/** Execute argv directly; no command or configuration value is passed to a shell. */
function git(string $directory, array $arguments, array $allowedCodes = [0], array $extraEnv = []): array
{
    $stdout = tmpfile();
    $stderr = tmpfile();
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('Cannot create temporary output files.');
    }
    $env = getenv();
    // Cron must not wait for passwords or use a parent Git process's repository.
    foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL',
        'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL', 'GIT_AUTHOR_DATE', 'GIT_COMMITTER_DATE'] as $key) {
        unset($env[$key]);
    }
    $env['GIT_TERMINAL_PROMPT'] = '0';
    $env['GIT_SSH_COMMAND'] = ($env['GIT_SSH_COMMAND'] ?? 'ssh')
        . ' -o BatchMode=yes -o ConnectTimeout=15';
    $process = proc_open([
        'git', '-c', 'credential.interactive=false', '-c', 'http.lowSpeedLimit=1000',
        '-c', 'http.lowSpeedTime=30', '-C', $directory, ...$arguments,
    ], [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => $stderr], $pipes, null, array_replace($env, $extraEnv));
    if (!is_resource($process)) {
        fclose($stdout);
        fclose($stderr);
        throw new RuntimeException('Cannot start Git. Check proc_open and the cron PATH.');
    }
    $deadline = microtime(true) + 60;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            proc_close($process);
            fclose($stdout);
            fclose($stderr);
            throw new RuntimeException('Git command timed out after 60 seconds. Retry on the next cron run.');
        }
        usleep(20000);
    } while (true);
    $closedCode = proc_close($process);
    $code = $status['exitcode'] >= 0 ? $status['exitcode'] : $closedCode;
    rewind($stdout);
    rewind($stderr);
    $out = stream_get_contents($stdout);
    $err = stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);
    if (!in_array($code, $allowedCodes, true)) {
        throw new RuntimeException("Git exited {$code}: " . trim($err . "\n" . $out));
    }
    return ['code' => $code, 'out' => trim($out), 'err' => trim($err)];
}

function checkCredentials(array $config): void
{
    if (str_contains($config['remote'], 'YOUR_') || $config['remote'][0] === '-') {
        throw new RuntimeException('Set remote to your dedicated empty canvas repository.');
    }
    if ($config['author_email'] === 'you@example.com'
        || !filter_var($config['author_email'], FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n<>]/', $config['author_name'])) {
        throw new RuntimeException('Set author_name and a GitHub-linked author_email in config.php.');
    }
}

function withLock(array $config, callable $job): void
{
    $directory = dirname($config['canvas_dir']);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create {$directory}.");
    }
    $handle = fopen($directory . '/.' . basename($config['canvas_dir']) . '.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('Cannot open the job lock.');
    }
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another job is running. Nothing was changed.');
        }
        $job();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function verifyCanvas(array $config): void
{
    $directory = $config['canvas_dir'];
    $marker = $directory . '/.git/php-git-graffiti.json';
    if (!is_file($marker)) {
        throw new RuntimeException('Canvas is not initialized. Run: php graffiti.php init');
    }
    $state = json_decode(file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
    if (($state['plan'] ?? null) !== $config['id']) {
        throw new RuntimeException('Drawing settings changed after init. Restore them or use a new empty canvas_dir.');
    }
    if (realpath(git($directory, ['rev-parse', '--show-toplevel'])['out']) !== realpath($directory)) {
        throw new RuntimeException('Canvas must be a standalone working copy.');
    }
    if (git($directory, ['symbolic-ref', '--short', 'HEAD'])['out'] !== 'main') {
        throw new RuntimeException('Canvas must stay on its main branch.');
    }
    if (git($directory, ['remote', 'get-url', 'origin'])['out'] !== $config['remote']) {
        throw new RuntimeException('Canvas origin does not match config.php.');
    }
    if (git($directory, ['status', '--porcelain'])['out'] !== '') {
        throw new RuntimeException('Canvas has uncommitted files. Keep it dedicated to generated commits.');
    }
}

function initialize(array $config): void
{
    checkCredentials($config);
    withLock($config, static function () use ($config): void {
        $directory = $config['canvas_dir'];
        if (is_file($directory . '/.git/php-git-graffiti.json')) {
            verifyCanvas($config);
            echo "Canvas already initialized.\n";
            return;
        }
        if (file_exists($directory) && (!is_dir($directory) || count(scandir($directory)) > 2)) {
            throw new RuntimeException('canvas_dir must be empty. Existing projects are never initialized.');
        }
        $remoteHeads = git(dirname($directory), ['ls-remote', '--heads', '--', $config['remote']])['out'];
        if ($remoteHeads !== '') {
            throw new RuntimeException('Remote already has branches. Choose a new empty GitHub repository.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create canvas_dir.');
        }
        git($directory, ['init']);
        git($directory, ['symbolic-ref', 'HEAD', 'refs/heads/main']);
        git($directory, ['config', 'user.name', $config['author_name']]);
        git($directory, ['config', 'user.email', $config['author_email']]);
        git($directory, ['config', 'commit.gpgsign', 'false']);
        git($directory, ['remote', 'add', 'origin', $config['remote']]);
        if (file_put_contents($directory . '/.git/php-git-graffiti.json', json_encode([
            'plan' => $config['id'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new RuntimeException('Cannot write canvas metadata.');
        }
        echo "Canvas initialized in {$directory}. No commits or pushes were made.\n";
    });
}

function runJob(array $config, DateTimeImmutable $now, bool $dryRun, ?callable $clock = null): void
{
    $now = $now->setTimezone($config['zone']);
    $day = $now->format('Y-m-d');
    $target = commitsOn($config, $now);
    if ($dryRun) {
        echo "[dry-run] {$day}: {$target} planned commits. No Git commands or file changes.\n";
        return;
    }
    checkCredentials($config);
    $clock ??= static fn (): DateTimeImmutable => new DateTimeImmutable('now', $config['zone']);
    withLock($config, static function () use ($config, $clock, $day, $target): void {
        verifyCanvas($config);
        $directory = $config['canvas_dir'];
        $head = git($directory, ['rev-parse', '--verify', 'HEAD'], [0, 128]);
        $remote = git($directory, ['ls-remote', '--heads', 'origin', 'refs/heads/main'])['out'];
        $remoteHash = $remote === '' ? '' : explode("\t", $remote)[0];
        if ($remoteHash !== '' && ($head['code'] !== 0
            || git($directory, ['merge-base', '--is-ancestor', $remoteHash, 'HEAD'], [0, 1, 128])['code'] !== 0)) {
            throw new RuntimeException('Remote main has unexpected history. No commits made; check the dedicated canvas.');
        }
        $history = $head['code'] === 0 ? git($directory, ['log', '--format=%s'])['out'] : '';
        $prefix = "graffiti: {$day} [{$config['id']}] ";
        $done = [];
        foreach (explode("\n", $history) as $subject) {
            if (str_starts_with($subject, $prefix)) {
                $suffix = substr($subject, strlen($prefix));
                if (!preg_match('/^(\d+)\/(\d+)$/', $suffix, $matches)
                    || (int) $matches[2] !== $target || (int) $matches[1] < 1
                    || (int) $matches[1] > $target || isset($done[(int) $matches[1]])) {
                    throw new RuntimeException('Unexpected or duplicate canvas commit. No commits made.');
                }
                $done[(int) $matches[1]] = true;
            }
        }
        $created = 0;
        for ($index = 1; $index <= $target; $index++) {
            if (isset($done[$index])) {
                continue;
            }
            // Stop if a long run has crossed the configured local midnight.
            $actualNow = $clock()->setTimezone($config['zone']);
            if ($actualNow->format('Y-m-d') !== $day) {
                throw new RuntimeException('Day changed during execution. Retry for the current day.');
            }
            git($directory, [
                '-c', 'user.name=' . $config['author_name'], '-c', 'user.email=' . $config['author_email'],
                'commit', '--allow-empty', '--no-gpg-sign', '-m', $prefix . "{$index}/{$target}",
            ], [0], [
                'GIT_AUTHOR_DATE' => $actualNow->format('Y-m-d\TH:i:sP'),
                'GIT_COMMITTER_DATE' => $actualNow->format('Y-m-d\TH:i:sP'),
            ]);
            $created++;
        }
        $latest = git($directory, ['rev-parse', '--verify', 'HEAD'], [0, 128]);
        if ($latest['code'] === 0 && $latest['out'] !== $remoteHash) {
            // Unsent commits are retried even on background days. Never force-push.
            git($directory, ['push', 'origin', 'HEAD:refs/heads/main']);
            echo "{$day}: created {$created}, previously present " . count($done) . "; pushed main.\n";
        } else {
            echo "{$day}: created {$created}, previously present " . count($done) . "; nothing to push.\n";
        }
    });
}

function main(array $argv): int
{
    $command = $argv[1] ?? 'help';
    if ($command === '--version') {
        echo 'PHP Git Graffiti ' . VERSION . "\n";
        return 0;
    }
    if (in_array($command, ['help', '--help', '-h'], true)) {
        echo "PHP Git Graffiti " . VERSION . "\n\n"
            . "php graffiti.php preview                  Preview drawing and date range\n"
            . "php graffiti.php plan                     Print CSV: date,commits\n"
            . "php graffiti.php init                     Prepare a dedicated empty canvas\n"
            . "php graffiti.php run --dry-run            Inspect today without changing anything\n"
            . "php graffiti.php run --dry-run --date=YYYY-MM-DD\n"
            . "php graffiti.php run                      Commit today's pixels and retry pending push\n\n"
            . "Optional: --config=/absolute/path/config.php\n"
            . "Copy config.example.php to config.php before init or a real run.\n";
        return 0;
    }
    if (!in_array($command, ['preview', 'plan', 'init', 'run'], true)) {
        throw new RuntimeException("Unknown command: {$command}. Run: php graffiti.php help");
    }
    $configPath = ROOT . '/config.php';
    $dryRun = false;
    $date = null;
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--dry-run' && $command === 'run' && !$dryRun) {
            $dryRun = true;
        } elseif (str_starts_with($argument, '--date=') && $date === null && $command === 'run') {
            $date = substr($argument, 7);
        } elseif (str_starts_with($argument, '--config=')) {
            $configPath = substr($argument, 9);
            if (!is_file($configPath)) {
                throw new RuntimeException('Config file does not exist.');
            }
        } else {
            throw new RuntimeException("Unknown or misplaced option: {$argument}.");
        }
    }
    if ($date !== null && !$dryRun) {
        throw new RuntimeException('--date is only available with --dry-run. Real runs use the current day.');
    }
    if (!is_file($configPath)) {
        if ($command === 'init' || ($command === 'run' && !$dryRun)) {
            throw new RuntimeException('Copy config.example.php to config.php and fill in your settings first.');
        }
        $configPath = ROOT . '/config.example.php';
        fwrite(STDERR, "Using config.example.php (no local config.php).\n");
    }
    $resolvedPath = realpath($configPath);
    $config = configuration($resolvedPath);
    if ($command === 'preview') {
        preview($config);
    } elseif ($command === 'plan') {
        echo "date,commits\n";
        for ($day = $config['start']; $day <= $config['end']; $day = $day->modify('+1 day')) {
            echo $day->format('Y-m-d') . ',' . commitsOn($config, $day) . "\n";
        }
    } elseif ($command === 'init') {
        initialize($config);
    } else {
        runJob($config, $date === null ? new DateTimeImmutable('now', $config['zone'])
            : dateValue($date, $config['zone']), $dryRun);
    }
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('Run this program from the command line.');
    }
    try {
        exit(main($argv));
    } catch (Throwable $error) {
        fwrite(STDERR, '[' . date('c') . '] ERROR: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
