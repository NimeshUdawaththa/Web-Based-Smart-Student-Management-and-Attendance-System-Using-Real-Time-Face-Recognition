<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @return list<string> */
function camera_manager_roles(): array
{
    return ['ADMIN', 'ACADEMIC_STAFF'];
}

function user_can_manage_camera(?string $role): bool
{
    return in_array((string) $role, camera_manager_roles(), true);
}

function require_camera_manager(): void
{
    require_any_role(camera_manager_roles());
}

function face_camera_index(): int
{
    $raw = trim((string) (env('FACE_CAMERA_INDEX', '0') ?? '0'));
    if (!preg_match('/^-?\d+$/', $raw)) {
        return 0;
    }
    $index = (int) $raw;

    return $index >= 0 ? $index : 0;
}

function camera_runtime_dir(): string
{
    $dir = BASE_PATH . DIRECTORY_SEPARATOR . 'face-recognition' . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function camera_state_path(): string
{
    return camera_runtime_dir() . DIRECTORY_SEPARATOR . 'camera_state.json';
}

function camera_control_path(): string
{
    return camera_runtime_dir() . DIRECTORY_SEPARATOR . 'camera_control.json';
}

function camera_log_path(): string
{
    return camera_runtime_dir() . DIRECTORY_SEPARATOR . 'camera.log';
}

function camera_mutex_path(): string
{
    return camera_runtime_dir() . DIRECTORY_SEPARATOR . 'camera.mutex';
}

function camera_python_exe(): string
{
    $path = BASE_PATH . DIRECTORY_SEPARATOR . 'face-recognition' . DIRECTORY_SEPARATOR
        . 'venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $real = realpath($path);

    return is_string($real) ? $real : '';
}

function camera_face_root(): string
{
    $path = BASE_PATH . DIRECTORY_SEPARATOR . 'face-recognition';
    $real = realpath($path);

    return is_string($real) ? $real : '';
}

function camera_powershell_exe(): string
{
    $path = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    $real = realpath($path);

    return is_string($real) ? $real : '';
}

function camera_tool_script(string $name): string
{
    $allowed = [
        'start_camera.ps1',
        'camera_process_info.ps1',
        'stop_camera_pid.ps1',
    ];
    if (!in_array($name, $allowed, true)) {
        return '';
    }
    $path = camera_face_root() . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . $name;
    $real = realpath($path);

    return is_string($real) ? $real : '';
}

function camera_append_log(string $message): void
{
    $line = app_now_datetime() . ' ' . $message . PHP_EOL;
    file_put_contents(camera_log_path(), $line, FILE_APPEND | LOCK_EX);
}

/** @param array<string, mixed> $payload */
function camera_write_json(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode camera state.');
    }
    file_put_contents($path, $json . PHP_EOL, LOCK_EX);
}

/** @return array<string, mixed> */
function camera_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

/** @return array<string, mixed> */
function camera_read_state(): array
{
    return camera_read_json(camera_state_path());
}

/** @return array<string, mixed> */
function camera_read_control(): array
{
    return camera_read_json(camera_control_path());
}

/** @param array<string, mixed> $patch */
function camera_write_state(array $patch): array
{
    $state = array_merge(camera_read_state(), $patch);
    camera_write_json(camera_state_path(), $state);

    return $state;
}

function camera_write_control(string $mode, bool $stopRequested): array
{
    $mode = strtoupper($mode);
    if (!in_array($mode, ['ENTRY', 'EXIT'], true)) {
        $mode = 'ENTRY';
    }
    $control = [
        'mode' => $mode,
        'stop_requested' => $stopRequested,
        'updated_at' => app_now_datetime(),
    ];
    camera_write_json(camera_control_path(), $control);

    return $control;
}

function camera_current_mode(): string
{
    $mode = strtoupper(trim((string) (camera_read_control()['mode'] ?? '')));
    if (in_array($mode, ['ENTRY', 'EXIT'], true)) {
        return $mode;
    }
    $fallback = strtoupper(trim((string) (env('ATTENDANCE_CAMERA_MODE', 'ENTRY') ?? 'ENTRY')));

    return in_array($fallback, ['ENTRY', 'EXIT'], true) ? $fallback : 'ENTRY';
}

/**
 * @return array{ok: bool, stdout: string, stderr: string, exit_code: int}
 */
function camera_run_powershell(string $scriptName, array $arguments = []): array
{
    $powershell = camera_powershell_exe();
    $script = camera_tool_script($scriptName);
    if ($powershell === '' || $script === '') {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'Camera helper script is not available.', 'exit_code' => 1];
    }
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'PHP process control is not available.', 'exit_code' => 1];
    }

    $cmd = escapeshellarg($powershell)
        . ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
        . escapeshellarg($script);
    foreach ($arguments as $argument) {
        if (!is_int($argument) && !is_string($argument)) {
            continue;
        }
        if (is_string($argument) && (!preg_match('/^-ProcessId$/', $argument) && !preg_match('/^\d+$/', $argument))) {
            return ['ok' => false, 'stdout' => '', 'stderr' => 'Invalid helper argument.', 'exit_code' => 1];
        }
        $cmd .= ' ' . (is_int($argument) ? (string) $argument : escapeshellarg($argument));
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, camera_face_root(), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'Could not run camera helper.', 'exit_code' => 1];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + 12.0;
    while (true) {
        $status = proc_get_status($process);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process);
            break;
        }
        usleep(50000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [
        'ok' => $exit === 0,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'exit_code' => $exit,
    ];
}

/** @return array{pid: int, executable: string, command: string}|null */
function camera_process_info(int $pid): ?array
{
    if ($pid <= 0) {
        return null;
    }
    $result = camera_run_powershell('camera_process_info.ps1', ['-ProcessId', $pid]);
    if ($result['stdout'] === '' || $result['stdout'] === '{}') {
        return null;
    }
    $data = json_decode($result['stdout'], true);
    if (!is_array($data) || empty($data['ProcessId'])) {
        return null;
    }

    return [
        'pid' => (int) $data['ProcessId'],
        'executable' => (string) ($data['ExecutablePath'] ?? ''),
        'command' => (string) ($data['CommandLine'] ?? ''),
    ];
}

function camera_normalize_path(string $path): string
{
    $real = realpath($path);
    $value = is_string($real) ? $real : $path;

    return strtolower(str_replace('/', '\\', $value));
}

function camera_pid_is_our_python(int $pid): bool
{
    return camera_pid_is_our_recognition($pid);
}

function camera_pid_is_our_recognition(int $pid): bool
{
    $info = camera_process_info($pid);
    if ($info === null) {
        return false;
    }
    $command = strtolower($info['command'] . ' ' . $info['executable']);
    if (!str_contains($command, 'live_recognition')) {
        return false;
    }
    $python = camera_python_exe();
    $exe = camera_normalize_path($info['executable']);
    $expected = $python !== '' ? camera_normalize_path($python) : '';
    if ($expected !== '' && $exe === $expected) {
        return true;
    }
    // Windows venv python.exe often reports the base CPython path in Win32_Process.
    return str_contains($exe, 'python') && str_contains($command, '-m');
}

function camera_with_mutex(callable $callback): mixed
{
    $handle = fopen(camera_mutex_path(), 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not lock camera control.');
    }
    $locked = false;
    try {
        $deadline = microtime(true) + 15.0;
        while (!($locked = flock($handle, LOCK_EX | LOCK_NB))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Camera control is busy. Try again.');
            }
            usleep(50000);
        }

        return $callback();
    } finally {
        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

/** @return array<string, mixed> */
function camera_reconcile_state(): array
{
    $state = camera_read_state();
    $pid = isset($state['pid']) ? (int) $state['pid'] : 0;
    $status = strtoupper((string) ($state['status'] ?? 'OFFLINE'));
    if (!in_array($status, ['OFFLINE', 'STARTING', 'ONLINE', 'STOPPING', 'ERROR'], true)) {
        $status = 'OFFLINE';
    }

    $ownedRecognition = $pid > 0 && camera_pid_is_our_recognition($pid);
    $ownedPython = $pid > 0 && camera_pid_is_our_python($pid);

    if ($ownedRecognition) {
        $state['status'] = in_array($status, ['STARTING', 'ONLINE', 'STOPPING'], true) ? $status : 'ONLINE';
        $state['pid'] = $pid;
        return $state;
    }

    if ($status === 'STARTING' && $ownedPython) {
        $state['pid'] = $pid;
        return $state;
    }

    if (in_array($status, ['STARTING', 'ONLINE', 'STOPPING'], true)) {
        $error = trim((string) ($state['last_error'] ?? ''));
        $state['status'] = $error !== '' ? 'ERROR' : 'OFFLINE';
        $state['pid'] = null;
        if (($state['last_stopped'] ?? '') === '') {
            $state['last_stopped'] = app_now_datetime();
        }
        camera_write_json(camera_state_path(), $state);
    }

    return $state;
}

function camera_status_badge_class(string $status): string
{
    return match (strtoupper($status)) {
        'ONLINE' => 'text-bg-success',
        'STARTING', 'STOPPING' => 'text-bg-warning',
        'ERROR' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

/** @return array<string, mixed> */
function camera_public_status(): array
{
    $state = camera_reconcile_state();
    $status = strtoupper((string) ($state['status'] ?? 'OFFLINE'));
    $pid = isset($state['pid']) ? (int) $state['pid'] : 0;
    $showPid = $pid > 0;

    return [
        'status' => $status !== '' ? $status : 'OFFLINE',
        'camera' => 'USB Webcam',
        'camera_index' => face_camera_index(),
        'mode' => camera_current_mode(),
        'pid' => $showPid ? $pid : null,
        'last_started' => $state['last_started'] ?? null,
        'last_stopped' => $state['last_stopped'] ?? null,
        'last_error' => $state['last_error'] ?? null,
        'heartbeat_at' => $state['heartbeat_at'] ?? null,
        'can_start' => function_exists('proc_open') && camera_python_exe() !== '' && camera_powershell_exe() !== '',
        'process_control' => function_exists('proc_open'),
    ];
}

/** @return array{ok: bool, message: string, already?: bool} */
function camera_start(): array
{
    return camera_with_mutex(static function (): array {
        $status = camera_public_status();
        if (in_array($status['status'], ['ONLINE', 'STARTING'], true) && $status['pid'] !== null) {
            return ['ok' => true, 'message' => 'Camera already running', 'already' => true];
        }
        if (!$status['can_start']) {
            $message = 'Cannot start camera: PHP process control or Python venv is unavailable.';
            camera_write_state([
                'status' => 'ERROR',
                'pid' => null,
                'last_error' => $message,
            ]);
            return ['ok' => false, 'message' => $message];
        }

        $mode = camera_current_mode();
        camera_write_control($mode, false);
        camera_write_state([
            'status' => 'STARTING',
            'pid' => null,
            'mode' => $mode,
            'last_error' => null,
            'heartbeat_at' => app_now_datetime(),
        ]);
        camera_append_log('Start requested');

        $result = camera_run_powershell('start_camera.ps1');
        $pid = (int) $result['stdout'];
        if (!$result['ok'] || $pid <= 0) {
            $message = $result['stderr'] !== '' ? $result['stderr'] : 'Could not start recognition process.';
            camera_write_state([
                'status' => 'ERROR',
                'pid' => null,
                'last_error' => $message,
                'last_stopped' => app_now_datetime(),
            ]);
            camera_append_log('Start failed: ' . $message);
            return ['ok' => false, 'message' => $message];
        }

        $existing = camera_read_state();
        if (($existing['status'] ?? '') !== 'ERROR') {
            camera_write_state([
                'status' => 'STARTING',
                'pid' => $pid,
                'mode' => $mode,
                'heartbeat_at' => app_now_datetime(),
            ]);
        } else {
            camera_write_state(['pid' => $pid]);
        }

        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            usleep(400000);
            $current = camera_public_status();
            if ($current['status'] === 'ONLINE') {
                return ['ok' => true, 'message' => 'Camera started'];
            }
            if ($current['status'] === 'ERROR') {
                $error = (string) ($current['last_error'] ?: 'Could not start camera');
                return ['ok' => false, 'message' => $error];
            }
        }

        $current = camera_public_status();
        if ($current['status'] === 'STARTING' && $current['pid'] !== null) {
            return ['ok' => true, 'message' => 'Camera is starting'];
        }

        $message = (string) ($current['last_error'] ?: 'Could not start camera. It may already be in use, or the configured camera index could not be opened.');
        if ($current['status'] !== 'ERROR') {
            camera_write_state([
                'status' => 'ERROR',
                'pid' => null,
                'last_error' => $message,
                'last_stopped' => app_now_datetime(),
            ]);
        }

        return ['ok' => false, 'message' => $message];
    });
}

/** @return array{ok: bool, message: string} */
function camera_stop(): array
{
    return camera_with_mutex(static function (): array {
        $status = camera_public_status();
        $pid = isset($status['pid']) ? (int) $status['pid'] : 0;
        $mode = camera_current_mode();
        camera_write_control($mode, true);
        camera_append_log('Stop requested');

        if ($pid <= 0 || !camera_pid_is_our_recognition($pid)) {
            camera_write_state([
                'status' => 'OFFLINE',
                'pid' => null,
                'last_stopped' => app_now_datetime(),
                'last_error' => null,
            ]);
            camera_write_control($mode, false);
            return ['ok' => true, 'message' => 'Camera is offline'];
        }

        camera_write_state([
            'status' => 'STOPPING',
            'pid' => $pid,
        ]);

        $deadline = microtime(true) + 8.0;
        while (microtime(true) < $deadline) {
            usleep(400000);
            if (!camera_pid_is_our_recognition($pid)) {
                camera_write_state([
                    'status' => 'OFFLINE',
                    'pid' => null,
                    'last_stopped' => app_now_datetime(),
                    'last_error' => null,
                ]);
                camera_write_control($mode, false);
                return ['ok' => true, 'message' => 'Camera stopped'];
            }
        }

        if (camera_pid_is_our_recognition($pid)) {
            $killed = camera_run_powershell('stop_camera_pid.ps1', ['-ProcessId', $pid]);
            if (!$killed['ok'] && camera_pid_is_our_recognition($pid)) {
                $message = 'Could not stop recognition process PID ' . $pid;
                camera_append_log($message);
                return ['ok' => false, 'message' => $message];
            }
        }

        camera_write_state([
            'status' => 'OFFLINE',
            'pid' => null,
            'last_stopped' => app_now_datetime(),
            'last_error' => null,
        ]);
        camera_write_control($mode, false);
        camera_append_log('Camera stopped');

        return ['ok' => true, 'message' => 'Camera stopped'];
    });
}

/** @return array{ok: bool, message: string} */
function camera_set_mode(string $mode): array
{
    $mode = strtoupper(trim($mode));
    if (!in_array($mode, ['ENTRY', 'EXIT'], true)) {
        return ['ok' => false, 'message' => 'Mode must be ENTRY or EXIT'];
    }

    return camera_with_mutex(static function () use ($mode): array {
        $stop = (bool) (camera_read_control()['stop_requested'] ?? false);
        camera_write_control($mode, $stop);
        camera_write_state(['mode' => $mode]);
        camera_append_log('Mode set to ' . $mode);

        return ['ok' => true, 'message' => 'Camera mode set to ' . $mode];
    });
}
