<?php
/**
 * ============================================================
 *  Fingerprint Push — Configuration
 *  PHP 7.4 | ZKTeco → HRM Server
 * ============================================================
 */

return [

    // ================================================================
    //  ZKTeco Fingerprint Devices
    //  បន្ថែម / លុប device នៅទីនេះ
    // ================================================================
    'devices' => [
        [
            'machine_number' => 1,
            'ip'             => '192.168.3.230',
            'port'           => 4370,
            'name'           => 'Device-1 (Main Office)',
        ],
        [
            'machine_number' => 2,
            'ip'             => '192.168.3.231',
            'port'           => 4370,
            'name'           => 'Device-2 (Office)',
        ],
        [
            'machine_number' => 3,
            'ip'             => '192.168.3.232',
            'port'           => 4370,
            'name'           => 'Device-3 (Office)',
        ],
        [
            'machine_number' => 6,
            'ip'             => '192.168.3.237',
            'port'           => 4370,
            'name'           => 'Device-6 (Office)',
        ],
        [
            'machine_number' => 8,
            'ip'             => '103.7.25.130',
            'port'           => 4370,
            'name'           => 'Device-8 (Remote)',
        ],
    ],

    // ================================================================
    //  Remote HRM API Server
    // ================================================================
    'api' => [
        'url'     => 'http://111.90.177.186:8080/api/HR_CHECK-IN-OUT_FROM_FINGERPRINT_API2_Harta.PHP',
        'timeout' => 30,   // seconds per request
    ],

    // ================================================================
    //  Schedule (used when running in loop mode)
    // ================================================================
    'schedule' => [
        'interval_seconds' => 300,   // pull every 5 minutes
    ],

    // ================================================================
    //  Logging
    // ================================================================
    'log_path'    => __DIR__ . '/../logs/fingerprint.log',
    'log_verbose' => true,   // true = show full record detail in log
];
