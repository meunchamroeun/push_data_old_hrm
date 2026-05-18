#!/usr/bin/env php
<?php
/**
 * ================================================================
 *  Fingerprint Push — Main Runner
 *  ZKTeco Devices → HRM Server API
 *  PHP 7.4 | Windows Server + Apache
 * ================================================================
 *
 *  HOW TO RUN — SAMPLE COMMANDS:
 *  ─────────────────────────────────────────────────────────────
 *
 *  ✅ TODAY only:
 *     php run.php --today
 *
 *  ✅ THIS MONTH (e.g. May 2025):
 *     php run.php --this-month
 *
 *  ✅ THIS YEAR (e.g. 2025):
 *     php run.php --this-year
 *
 *  ✅ SPECIFIC DATE RANGE:
 *     php run.php --from=2025-01-01 --to=2025-01-31
 *
 *  ✅ SPECIFIC DATE (one day):
 *     php run.php --from=2025-05-10 --to=2025-05-10
 *
 *  ✅ ALL DATA (no filter):
 *     php run.php --all
 *
 *  ✅ ONE DEVICE ONLY (machine number):
 *     php run.php --today --device=1
 *     php run.php --from=2025-01-01 --to=2025-01-31 --device=8
 *
 *  ✅ LOOP MODE (runs every 5 min, for Task Scheduler):
 *     php run.php --loop
 *
 *  ─────────────────────────────────────────────────────────────
 *  EXAMPLES:
 *
 *  Push today from Device-1 only:
 *     php run.php --today --device=1
 *
 *  Push this month from all devices:
 *     php run.php --this-month
 *
 *  Push custom date range:
 *     php run.php --from=2025-03-01 --to=2025-03-31
 *
 *  Push all data from all devices:
 *     php run.php --all
 * ================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/ZKDevice.php';
require_once __DIR__ . '/src/ZKDeviceCom.php';
require_once __DIR__ . '/src/ApiPusher.php';

$config = require __DIR__ . '/config/config.php';
$logger = new Logger($config['log_path'], $config['log_verbose']);

// ================================================================
//  Parse CLI Arguments
// ================================================================
$opts = getopt('', [
    'today',        // --today
    'this-month',   // --this-month
    'this-year',    // --this-year
    'all',          // --all  (no date filter)
    'from:',        // --from=2025-01-01
    'to:',          // --to=2025-01-31
    'device:',      // --device=1  (machine number)
    'loop',         // --loop  (run continuously)
    'driver:',      // --driver=auto|socket|com
]);

// ── Resolve date range ────────────────────────────────────────
$dateFrom = null;
$dateTo   = null;

if (isset($opts['today'])) {
    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d');
    $rangeLabel = 'Today (' . $dateFrom . ')';

} elseif (isset($opts['this-month'])) {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-t');
    $rangeLabel = 'This Month (' . $dateFrom . ' → ' . $dateTo . ')';

} elseif (isset($opts['this-year'])) {
    $dateFrom = date('Y-01-01');
    $dateTo   = date('Y-12-31');
    $rangeLabel = 'This Year (' . $dateFrom . ' → ' . $dateTo . ')';

} elseif (isset($opts['from']) || isset($opts['to'])) {
    $dateFrom = $opts['from'] ?? null;
    $dateTo   = $opts['to']   ?? null;
    // Validate format
    foreach (['from' => $dateFrom, 'to' => $dateTo] as $key => $val) {
        if ($val && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            echo "❌ Invalid --$key format. Use YYYY-MM-DD\n";
            exit(1);
        }
    }
    $rangeLabel = 'Custom Range (' . ($dateFrom ?? 'start') . ' → ' . ($dateTo ?? 'now') . ')';

} elseif (isset($opts['all'])) {
    $dateFrom   = null;
    $dateTo     = null;
    $rangeLabel = 'ALL DATA (no filter)';

} else {
    // Default: show help
    echo <<<HELP

  ╔══════════════════════════════════════════════════════════════╗
  ║        Fingerprint Push — ZKTeco → HRM Server               ║
  ╠══════════════════════════════════════════════════════════════╣
  ║  Usage:                                                      ║
  ║    php run.php --today                                       ║
  ║    php run.php --this-month                                  ║
  ║    php run.php --this-year                                   ║
  ║    php run.php --from=2025-01-01 --to=2025-01-31            ║
  ║    php run.php --all                                         ║
  ║    php run.php --today --device=1                            ║
  ║    php run.php --loop   (auto repeat every 5 min)            ║
  ╚══════════════════════════════════════════════════════════════╝

HELP;
    exit(0);
}

$onlyDevice = isset($opts['device']) ? (int)$opts['device'] : null;
$loopMode   = isset($opts['loop']);
$driverMode = isset($opts['driver']) ? strtolower((string)$opts['driver']) : 'auto';
if (!in_array($driverMode, ['auto', 'socket', 'com'], true)) {
    echo "❌ Invalid --driver value. Use: auto | socket | com\n";
    exit(1);
}

// ================================================================
//  Core: one full cycle across all devices
// ================================================================
function runCycle(
    $config,
    $logger,
    $dateFrom,
    $dateTo,
    $rangeLabel,
    $onlyDevice,
    $driverMode
) {
    $pusher = new ApiPusher(
        $config['api']['url'],
        $config['api']['timeout'],
        $logger,
        $config['api']['method'] ?? 'POST',
        (int)($config['api']['delay_ms'] ?? 30),
        (int)($config['api']['retry_429'] ?? 2),
        (string)($config['api']['confirm_mode'] ?? 'strict')
    );

    $logger->separator("CYCLE START  |  Range: $rangeLabel");
    $logger->info("MAIN", "API Target: " . $config['api']['url']);

    $grandTotal   = 0;
    $grandSuccess = 0;
    $grandFailed  = 0;

    foreach ($config['devices'] as $cfg) {
        if ($onlyDevice !== null && (int)$cfg['machine_number'] !== $onlyDevice) {
            continue;
        }

        $logger->separator("{$cfg['name']} ({$cfg['ip']}:{$cfg['port']})");

        if ($driverMode === 'socket') {
            $device = new ZKDevice($cfg, $logger);
        } elseif ($driverMode === 'com') {
            $device = new ZKDeviceCom($cfg, $logger);
        } else {
            // auto: try socket first then COM
            $device = new ZKDevice($cfg, $logger);
        }

        $logger->info("DEVICE", "Connecting to {$cfg['name']} ({$cfg['ip']}:{$cfg['port']})...");

        if (!$device->connect()) {
            if ($driverMode === 'auto') {
                $logger->warn("DEVICE", "Socket driver failed, trying COM driver...");
                $device = new ZKDeviceCom($cfg, $logger);
                if (!$device->connect()) {
                    $logger->error("DEVICE", "SKIP — Cannot connect to {$cfg['name']} by socket/com");
                    continue;
                }
            } else {
                $logger->error("DEVICE", "SKIP — Cannot connect to {$cfg['name']}");
                continue;
            }
        }

        $records = $device->getAttendanceLogs($dateFrom, $dateTo);
        $device->disconnect();

        if (empty($records)) {
            $logger->warn("DEVICE", "No records found for range: $rangeLabel");
            continue;
        }

        $result = $pusher->pushBatch($records, $cfg['name']);

        $grandTotal   += $result['total'];
        $grandSuccess += $result['success'];
        $grandFailed  += $result['failed'];
    }

    $logger->separator("GRAND TOTAL");
    $logger->success("MAIN", "All devices done | Total: $grandTotal | ✓ Success: $grandSuccess | ✗ Failed: $grandFailed");
    $logger->separator();
}

// ================================================================
//  Run
// ================================================================
$logger->separator("Fingerprint Push Service");
$logger->info("MAIN", "PHP " . PHP_VERSION . " | Mode: " . ($loopMode ? 'LOOP' : 'ONCE') . " | Range: $rangeLabel");
$logger->info("MAIN", "Driver: " . strtoupper($driverMode));
if ($onlyDevice) {
    $logger->info("MAIN", "Filter: Device #$onlyDevice only");
}

if ($loopMode) {
    $interval = $config['schedule']['interval_seconds'];
    $logger->info("MAIN", "Loop interval: {$interval}s | Press Ctrl+C to stop");

    while (true) {
        // In loop mode, recalculate "today/this-month" each cycle
        if (isset($opts['today'])) {
            $dateFrom   = date('Y-m-d');
            $dateTo     = date('Y-m-d');
            $rangeLabel = 'Today (' . $dateFrom . ')';
        } elseif (isset($opts['this-month'])) {
            $dateFrom   = date('Y-m-01');
            $dateTo     = date('Y-m-t');
            $rangeLabel = 'This Month (' . $dateFrom . ' → ' . $dateTo . ')';
        }

        runCycle($config, $logger, $dateFrom, $dateTo, $rangeLabel, $onlyDevice, $driverMode);
        $logger->info("MAIN", "Sleeping {$interval}s...");
        sleep($interval);
    }
} else {
    runCycle($config, $logger, $dateFrom, $dateTo, $rangeLabel, $onlyDevice, $driverMode);
}
