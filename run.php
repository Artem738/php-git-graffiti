<?php

declare(strict_types=1);

namespace GitGraffiti;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

const ROOT = __DIR__;
const DAYS = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

function readDrawing(): array
{
    $path = ROOT . '/DRAW-TEXT.txt';
    if (!is_readable($path)) {
        throw new RuntimeException('Не удаётся прочитать DRAW-TEXT.txt.');
    }
    $rows = explode("\n", str_replace(["\r\n", "\r"], "\n", file_get_contents($path)));
    if (end($rows) === '') {
        array_pop($rows);
    }
    if (count($rows) !== 7) {
        throw new RuntimeException('В DRAW-TEXT.txt должно быть ровно 7 строк: воскресенье — суббота.');
    }
    foreach ($rows as $row) {
        if (strspn($row, '#. ') !== strlen($row)) {
            throw new RuntimeException('В рисунке разрешены только #, точки и пробелы.');
        }
    }
    $width = max(array_map('strlen', $rows));
    if ($width > 52 || !str_contains(implode('', $rows), '#')) {
        throw new RuntimeException('Нужен хотя бы один #; ширина рисунка — не более 52 столбцов.');
    }
    return array_map(static fn (string $row): string => str_replace(' ', '.', str_pad($row, $width, '.')), $rows);
}

function nextSunday(DateTimeImmutable $day): DateTimeImmutable
{
    return $day->format('w') === '0' ? $day->setTime(0, 0) : $day->modify('next sunday')->setTime(0, 0);
}

/** Calendar position depends on dates, never on the number of cron invocations. */
function schedule(array $state, DateTimeImmutable $now, int $weeks): array
{
    $today = $now->setTime(0, 0);
    $state += [
        'first_run_at' => $now->format(DATE_ATOM),
        'start_date' => nextSunday($today)->format('Y-m-d'),
        'started_at' => null,
        'last_run_at' => null,
        'last_processed_date' => null,
    ];
    $notice = '';
    $start = new DateTimeImmutable($state['start_date'], $now->getTimezone());
    if ($state['started_at'] === null && $today > $start) {
        $state['start_date'] = nextSunday($today)->format('Y-m-d');
        $notice = "Первое воскресенье {$start->format('Y-m-d')} пропущено; старт перенесён на {$state['start_date']}. ";
        $start = new DateTimeImmutable($state['start_date'], $now->getTimezone());
    }
    if ($state['started_at'] === null && $today == $start) {
        $state['started_at'] = $now->format(DATE_ATOM);
    }
    if ($state['started_at'] !== null && $state['last_run_at'] !== null) {
        $previous = (new DateTimeImmutable($state['last_run_at']))->setTimezone($now->getTimezone())->setTime(0, 0);
        $from = $previous->modify('+1 day');
        if ($from < $today) {
            $notice .= "Не было запусков: {$from->format('Y-m-d')} — {$today->modify('-1 day')->format('Y-m-d')}. ";
        }
    }
    $elapsed = $today < $start ? 0 : (int) $start->diff($today)->format('%a');
    $state['phase'] = $today < $start ? 'waiting' : ($elapsed >= $weeks * 7 ? 'finished' : 'drawing');
    $state['days_passed'] = min($elapsed, $weeks * 7);
    $state['drawing_day'] = $state['phase'] === 'drawing' ? $elapsed + 1 : null;
    $state['total_days'] = $weeks * 7;
    $state['last_run_at'] = $now->format(DATE_ATOM);
    return [$state, $notice];
}

function statusText(array $state): string
{
    $labels = ['waiting' => 'ожидание воскресенья', 'drawing' => 'рисование', 'finished' => 'период рисунка завершён'];
    $lines = [
        'Первый запуск: ' . $state['first_run_at'],
        'Начало рисунка: ' . $state['start_date'] . ', воскресенье',
        'Начало подтверждено запуском: ' . ($state['started_at'] ?? 'ещё нет'),
        'Состояние: ' . $labels[$state['phase']],
        'Полных дней рисунка прошло: ' . $state['days_passed'],
    ];
    if ($state['drawing_day'] !== null) {
        $index = $state['drawing_day'] - 1;
        $lines[] = "Текущий день рисунка: {$state['drawing_day']} из {$state['total_days']}";
        $lines[] = 'Позиция: столбец ' . (intdiv($index, 7) + 1) . ', строка ' . ($index % 7 + 1) . ' — ' . DAYS[$index % 7];
    }
    $lines[] = 'Последний запуск: ' . $state['last_run_at'];
    $lines[] = 'Последний успешно обработанный день: ' . ($state['last_processed_date'] ?? 'ещё нет');
    $lines[] = 'Результат: ' . ($state['last_result'] ?? '');
    return implode("\n", $lines) . "\n";
}

function writeAtomic(string $path, string $contents): void
{
    if (file_put_contents($path . '.tmp', $contents) === false || !rename($path . '.tmp', $path)) {
        throw new RuntimeException("Не удалось сохранить {$path}.");
    }
}

function report(array $state, string $result, bool $error = false): void
{
    $state['last_result'] = $result;
    writeAtomic(ROOT . '/var/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    writeAtomic(ROOT . '/STATUS.txt', statusText($state));
    $line = '[' . $state['last_run_at'] . '] ' . ($error ? 'ERROR: ' : '') . $result . "\n";
    if (file_put_contents(ROOT . '/var/run.log', $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать var/run.log.');
    }
    fwrite($error ? STDERR : STDOUT, $line);
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

/** The canvas is dedicated to this program. Existing repositories are not reused. */
function paint(array $config, array $state, int $target, DateTimeImmutable $now): string
{
    $directory = ROOT . '/var/canvas';
    $marker = $directory . '/.git/php-git-graffiti.json';
    $plan = substr(hash('sha256', $state['settings_hash'] . $state['start_date']), 0, 16);
    if (!is_dir($directory . '/.git')) {
        if ($target === 0) {
            return 'Сегодня нет пикселя; новых коммитов нет.';
        }
        if (file_exists($directory) && (!is_dir($directory) || count(scandir($directory)) > 2)) {
            throw new RuntimeException('var/canvas должен быть пустым. Существующие файлы сохранены.');
        }
        if (git(ROOT, ['ls-remote', '--heads', '--', $config['remote']])['out'] !== '') {
            throw new RuntimeException('Для рисунка нужен отдельный пустой репозиторий GitHub.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Не удалось создать var/canvas.');
        }
        git($directory, ['init']);
        git($directory, ['symbolic-ref', 'HEAD', 'refs/heads/main']);
        git($directory, ['remote', 'add', 'origin', $config['remote']]);
        writeAtomic($marker, json_encode(['plan' => $plan], JSON_THROW_ON_ERROR));
    }
    if (!is_file($marker) || (json_decode(file_get_contents($marker), true)['plan'] ?? null) !== $plan) {
        throw new RuntimeException('var/canvas принадлежит другому рисунку. Его история сохранена.');
    }
    if (git($directory, ['symbolic-ref', '--short', 'HEAD'])['out'] !== 'main'
        || git($directory, ['remote', 'get-url', 'origin'])['out'] !== $config['remote']
        || git($directory, ['status', '--porcelain'])['out'] !== '') {
        throw new RuntimeException('В var/canvas изменена ветка, origin или файлы. Проверьте вручную.');
    }
    $head = git($directory, ['rev-parse', '--verify', 'HEAD'], [0, 128]);
    $remote = git($directory, ['ls-remote', '--heads', 'origin', 'refs/heads/main'])['out'];
    $remoteHash = $remote === '' ? '' : explode("\t", $remote)[0];
    if ($remoteHash !== '' && ($head['code'] !== 0
        || git($directory, ['merge-base', '--is-ancestor', $remoteHash, 'HEAD'], [0, 1, 128])['code'] !== 0)) {
        throw new RuntimeException('На GitHub есть чужие изменения. Автоматическая перезапись запрещена.');
    }
    $history = $head['code'] === 0 ? git($directory, ['log', '--format=%s'])['out'] : '';
    $day = $now->format('Y-m-d');
    $prefix = "graffiti: {$day} [{$plan}] ";
    $created = 0;
    for ($index = 1; $index <= $target; $index++) {
        $message = $prefix . "{$index}/{$target}";
        if (in_array($message, explode("\n", $history), true)) {
            continue;
        }
        $current = new DateTimeImmutable('now', $now->getTimezone());
        if ($current->format('Y-m-d') !== $day) {
            throw new RuntimeException('Во время выполнения сменился день. Следующий cron продолжит работу.');
        }
        git($directory, ['-c', 'user.name=' . $config['author_name'], '-c', 'user.email=' . $config['author_email'],
            'commit', '--allow-empty', '--no-gpg-sign', '-m', $message], [0], [
                'GIT_AUTHOR_DATE' => $current->format(DATE_ATOM), 'GIT_COMMITTER_DATE' => $current->format(DATE_ATOM),
            ]);
        $created++;
    }
    $latest = git($directory, ['rev-parse', '--verify', 'HEAD'], [0, 128]);
    if ($latest['code'] === 0 && $latest['out'] !== $remoteHash) {
        git($directory, ['push', 'origin', 'HEAD:refs/heads/main']);
        return "Создано коммитов: {$created}; push выполнен.";
    }
    return "Создано коммитов: {$created}; отправлять нечего.";
}

function main(array $argv): int
{
    $option = $argv[1] ?? '';
    if (in_array($option, ['--help', '-h'], true)) {
        echo "php run.php             Запуск для cron\nphp run.php --dry-run   Рисунок и состояние без изменений\n";
        return 0;
    }
    if (count($argv) > 2 || !in_array($option, ['', '--dry-run'], true)) {
        throw new RuntimeException('Доступны только php run.php и php run.php --dry-run.');
    }
    $dry = $option === '--dry-run';
    $configPath = ROOT . '/config.php';
    if (!is_file($configPath) && !$dry) {
        throw new RuntimeException('Скопируйте config.example.php в config.php и укажите настройки GitHub.');
    }
    $config = require (is_file($configPath) ? $configPath : ROOT . '/config.example.php');
    foreach (['timezone', 'remote', 'author_name', 'author_email'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
            throw new RuntimeException("Укажите {$key} в config.php.");
        }
    }
    $zone = new DateTimeZone($config['timezone']);
    $now = new DateTimeImmutable('now', $zone);
    $rows = readDrawing();
    if (!is_int($config['commits_per_pixel'] ?? null) || $config['commits_per_pixel'] < 1 || $config['commits_per_pixel'] > 100) {
        throw new RuntimeException('commits_per_pixel должен быть целым числом от 1 до 100.');
    }
    if (!$dry && (str_contains($config['remote'], 'YOUR_') || $config['remote'] === '' || $config['remote'][0] === '-'
        || $config['author_email'] === 'you@example.com' || !filter_var($config['author_email'], FILTER_VALIDATE_EMAIL)
        || trim($config['author_name']) === '' || preg_match('/[\r\n<>]/', $config['author_name']))) {
        throw new RuntimeException('Укажите remote, author_name и GitHub-linked author_email в config.php.');
    }
    $lock = null;
    if (!$dry) {
        if (!is_dir(ROOT . '/var') && !mkdir(ROOT . '/var', 0700)) {
            throw new RuntimeException('Не удалось создать var/.');
        }
        $lock = fopen(ROOT . '/var/run.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Не удалось открыть блокировку.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            echo "Другой запуск ещё выполняется.\n";
            return 0;
        }
    }
    $state = null;
    try {
        $state = is_file(ROOT . '/var/state.json')
            ? json_decode(file_get_contents(ROOT . '/var/state.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($state)) {
            throw new RuntimeException('var/state.json повреждён. Не сбрасывайте сохранённую дату старта.');
        }
        if (is_file(ROOT . '/var/state.json') && (!isset($state['first_run_at'], $state['start_date'], $state['settings_hash'])
            || (new DateTimeImmutable($state['start_date'], $zone))->format('w') !== '0')) {
            throw new RuntimeException('В var/state.json отсутствует сохранённое воскресенье или настройки. Восстановите файл.');
        }
        $hash = hash('sha256', json_encode([$rows, $config], JSON_THROW_ON_ERROR));
        if (isset($state['settings_hash']) && $state['settings_hash'] !== $hash) {
            throw new RuntimeException('Рисунок или настройки изменены после первого запуска. Восстановите их для продолжения.');
        }
        $state['settings_hash'] = $hash;
        [$state, $notice] = schedule($state, $now, strlen($rows[0]));
        $target = $state['phase'] === 'drawing' && $rows[($state['drawing_day'] - 1) % 7][intdiv($state['drawing_day'] - 1, 7)] === '#'
            ? $config['commits_per_pixel'] : 0;
        if ($dry) {
            $state['last_result'] = "План на сегодня: {$target} коммитов. Это предпросмотр; файлы и Git не изменены.";
            echo statusText($state) . "\nDRAW-TEXT.txt:\n" . implode("\n", $rows) . "\n";
            return 0;
        }
        // Save the chosen Sunday BEFORE any network operation or commit.
        report($state, $notice . 'Запуск; план на сегодня: ' . $target . ' коммитов.');
        if ($state['phase'] === 'waiting') {
            report($state, $notice . 'Ждём воскресенья ' . $state['start_date'] . '.');
            return 0;
        }
        $result = paint($config, $state, $target, $now);
        if ($state['phase'] === 'drawing') {
            $state['last_processed_date'] = $now->format('Y-m-d');
        }
        report($state, $notice . ($state['phase'] === 'finished' ? 'Период рисунка завершён. ' : '') . $result);
        return 0;
    } catch (Throwable $error) {
        if (!$dry && isset($state['phase'])) {
            $state['last_run_at'] = $now->format(DATE_ATOM);
            report($state, preg_replace('/\s+/', ' ', $error->getMessage()), true);
            return 1;
        }
        throw $error;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('Run this program from the command line.');
    }
    try {
        exit(main($argv));
    } catch (Throwable $error) {
        $message = preg_replace('/\s+/', ' ', $error->getMessage());
        $line = '[' . date(DATE_ATOM) . '] ERROR: ' . $message . "\n";
        fwrite(STDERR, $line);
        // Configuration/startup failures also belong in the local log.
        if (is_dir(ROOT . '/var') || (is_writable(ROOT) && mkdir(ROOT . '/var', 0700))) {
            file_put_contents(ROOT . '/var/run.log', $line, FILE_APPEND | LOCK_EX);
            $status = is_file(ROOT . '/STATUS.txt') ? file_get_contents(ROOT . '/STATUS.txt') : '';
            $status = preg_replace('/^Ошибка запуска:.*\n?/mu', '', $status);
            writeAtomic(ROOT . '/STATUS.txt', $status . 'Ошибка запуска: ' . trim($line) . "\n");
        }
        exit(1);
    }
}
