<?php
declare(strict_types=1);

/** A child's exit must not end the remaining maintenance schedule. */
final class MaintenanceRunner
{
    public static function run(array $scripts, callable $execute, callable $report): int
    {
        $failed = false;
        foreach ($scripts as $script) {
            try { $code = $execute($script); }
            catch (Throwable $e) { $code = 1; }
            $report($script, $code);
            $failed = $failed || $code !== 0;
        }
        return $failed ? 1 : 0;
    }

    public static function process(string $script, int $timeoutSeconds = 300): int
    {
        if (!is_file($script) || $timeoutSeconds < 1) return 1;
        $process = proc_open([PHP_BINARY, $script], [STDIN, STDOUT, STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return 1;
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $closed = proc_close($process);
                return $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                usleep(200000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
                proc_close($process);
                return 124;
            }
            usleep(100000);
        } while (true);
    }
}
