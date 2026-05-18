<?php
/**
 * Logger — Detailed console + file logging
 * PHP 7.4
 */
class Logger
{
    private $logPath;
    private $verbose;

    // Console colors (Windows CMD supports these via ANSI if enabled)
    const GREEN  = "\033[32m";
    const RED    = "\033[31m";
    const YELLOW = "\033[33m";
    const CYAN   = "\033[36m";
    const WHITE  = "\033[37m";
    const RESET  = "\033[0m";
    const BOLD   = "\033[1m";

    public function __construct(string $logPath, bool $verbose = true)
    {
        $this->logPath = $logPath;
        $this->verbose = $verbose;

        if (!is_dir(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
    }

    public function info($tag, string $msg)
    {
        $this->write('INFO ', $tag, $msg, self::CYAN);
    }

    public function success($tag, string $msg)
    {
        $this->write('OK   ', $tag, $msg, self::GREEN);
    }

    public function error($tag, string $msg)
    {
        $this->write('ERROR', $tag, $msg, self::RED);
    }

    public function warn($tag, string $msg)
    {
        $this->write('WARN ', $tag, $msg, self::YELLOW);
    }

    public function separator($title = '')
    {
        $line = str_repeat('─', 70);
        if ($title) {
            $pad  = max(0, intdiv(70 - strlen($title) - 2, 2));
            $line = str_repeat('─', $pad) . ' ' . $title . ' ' . str_repeat('─', $pad);
        }
        $out = self::BOLD . self::WHITE . $line . self::RESET . "\n";
        echo $out;
        file_put_contents($this->logPath, strip_tags(preg_replace('/\033\[[0-9;]*m/', '', $line)) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Log a single attendance record with full detail
     */
    public function record(array $rec, $status, $apiResponse = '')
    {
        if (!$this->verbose) return;

        $punchLabel = $this->punchLabel((int)($rec['type'] ?? 0));
        $color      = $status === 'OK' ? self::GREEN : self::RED;

        $detail = sprintf(
            "  %s[%s]%s User: %-12s | DateTime: %s | Punch: %-14s | Machine: #%s | API: %s",
            $color, $status, self::RESET,
            $rec['user_id']    ?? '-',
            $rec['datetime']   ?? '-',
            $punchLabel,
            $rec['machine_no'] ?? '-',
            $apiResponse ?: '-'
        );

        echo $detail . "\n";

        $plain = sprintf(
            "  [%s] User: %-12s | DateTime: %s | Punch: %-14s | Machine: #%s | API: %s",
            $status,
            $rec['user_id']    ?? '-',
            $rec['datetime']   ?? '-',
            $punchLabel,
            $rec['machine_no'] ?? '-',
            $apiResponse ?: '-'
        );
        file_put_contents($this->logPath, $plain . "\n", FILE_APPEND | LOCK_EX);
    }

    private function punchLabel($type)
    {
        $map = [
            0 => 'Check-In',
            1 => 'Check-Out',
            2 => 'Break-Out',
            3 => 'Break-In',
            4 => 'Overtime-In',
            5 => 'Overtime-Out',
        ];
        return $map[$type] ?? "Type-$type";
    }

    private function write($level, $tag, $msg, $color)
    {
        $ts   = date('Y-m-d H:i:s');
        $line = sprintf("%s[%s]%s %s[%s]%s [%s] %s",
            self::BOLD . $color, $level, self::RESET,
            self::YELLOW, $ts, self::RESET,
            $tag,
            $msg
        );
        echo $line . "\n";

        $plain = sprintf("[%s] [%s] [%s] %s", $level, $ts, $tag, $msg);
        file_put_contents($this->logPath, $plain . "\n", FILE_APPEND | LOCK_EX);
    }
}
