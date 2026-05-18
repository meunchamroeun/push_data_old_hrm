<?php
/**
 * ApiPusher - HTTP push attendance records to HRM server
 * PHP 7.4 | Detailed logging per record
 */
class ApiPusher
{
    private $apiUrl;
    private $timeout;
    private $logger;
    private $method;
    private $delayMs;
    private $retry429;
    private $confirmMode;
    private $pushedCachePath;
    private $pushedSet = [];

    public function __construct(
        string $apiUrl,
        int $timeout,
        Logger $logger,
        string $method = 'POST',
        int $delayMs = 30,
        int $retry429 = 2,
        string $confirmMode = 'strict'
    )
    {
        $this->apiUrl  = $apiUrl;
        $this->timeout = $timeout;
        $this->logger  = $logger;
        $this->method  = strtoupper($method);
        $this->delayMs = $delayMs;
        $this->retry429 = $retry429;
        $mode = strtolower(trim($confirmMode));
        $this->confirmMode = in_array($mode, ['legacy', 'strict'], true) ? $mode : 'strict';

        $this->pushedCachePath = dirname(__DIR__) . '/logs/pushed_cache_keys.txt';
        $this->loadPushedSet();
    }

    public function pushOne($rec)
    {
        $postData = http_build_query([
            'machine_no' => $rec['machine_no'] ?? '',
            'user_id'    => $rec['user_id'] ?? '',
            'datetime'   => $rec['datetime'] ?? '',
            'type'       => $rec['type'] ?? 0,
        ]);

        $ch = curl_init();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($this->method === 'GET') {
            $opts[CURLOPT_URL] = $this->apiUrl . (strpos($this->apiUrl, '?') === false ? '?' : '&') . $postData;
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_URL] = $this->apiUrl;
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $postData;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $opts);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [false, 'NETWORK_ERROR: ' . $this->cleanText($curlError), false];
        }
        if ($httpCode !== 200) {
            $msg = $this->extractHttpError($httpCode, (string)$response);
            return [false, $msg, false];
        }

        $resp = trim((string)$response);
        $dbConfirmed = $this->isDbInsertConfirmed($resp);
        if ($this->confirmMode === 'legacy') {
            return [true, $resp, $dbConfirmed];
        }
        if (!$dbConfirmed) {
            return [false, 'DB_NOT_CONFIRMED: ' . $this->cleanText($resp), false];
        }

        return [true, $resp, true];
    }

    public function pushBatch(array $records, $deviceName)
    {
        $records = array_values(array_filter($records, function ($rec) {
            $userId = trim((string)($rec['user_id'] ?? ''));
            $dt     = trim((string)($rec['datetime'] ?? ''));
            return $userId !== '' && $dt !== '';
        }));

        $total   = count($records);
        $skipped = 0;
        $success = 0;
        $successDbConfirmed = 0;
        $successApiOnly = 0;
        $failed  = 0;
        $failReasons = [];

        $this->logger->info('PUSH', "Starting push: $total records from $deviceName -> {$this->apiUrl}");
        $this->logger->separator('Records Detail');

        foreach ($records as $rec) {
            $recordKey = $this->recordKey($rec);
            if ($this->isAlreadyPushed($recordKey)) {
                $skipped++;
                $this->logger->record($rec, 'OK', 'ALREADY_PUSHED (SKIP)');
                continue;
            }

            $attempt = 0;
            $ok = false;
            $apiResp = '';
            $dbConfirmed = false;
            do {
                [$ok, $apiResp, $dbConfirmed] = $this->pushOne($rec);
                if ($ok) break;
                if (strpos((string)$apiResp, 'RATE_LIMIT_429:') !== 0) break;
                if ($attempt >= $this->retry429) break;
                usleep(1000000 * ($attempt + 1));
                $attempt++;
            } while (true);

            if ($ok) {
                $success++;
                $this->markAsPushed($recordKey);
                if ($dbConfirmed) {
                    $successDbConfirmed++;
                    $this->logger->record($rec, 'OK', 'DB_CONFIRMED | ' . ($apiResp === '' ? '-' : $this->cleanText($apiResp)));
                } else {
                    $successApiOnly++;
                    $this->logger->record($rec, 'OK', 'API_OK_ONLY | ' . ($apiResp === '' ? '(empty response)' : $this->cleanText($apiResp)));
                }
            } else {
                $failed++;
                $key = $this->classifyReason($apiResp);
                $failReasons[$key] = isset($failReasons[$key]) ? $failReasons[$key] + 1 : 1;
                $this->logger->record($rec, 'FAIL', $apiResp);
            }

            usleep(max(0, $this->delayMs) * 1000);
        }

        $this->logger->separator('Summary');
        $this->logger->success('PUSH', "Done $deviceName -> Total: $total | Success: $success | Skipped: $skipped | Failed: $failed");
        $this->logger->info('PUSH', "Success detail: DB_CONFIRMED={$successDbConfirmed} | API_OK_ONLY={$successApiOnly}");
        if (!empty($failReasons)) {
            foreach ($failReasons as $reason => $count) {
                $this->logger->warn('PUSH', "Fail reason: {$reason} | Count: {$count}");
            }
        }

        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'total' => $total];
    }

    private function recordKey(array $rec): string
    {
        return trim((string)($rec['machine_no'] ?? '')) . '|' .
            trim((string)($rec['user_id'] ?? '')) . '|' .
            trim((string)($rec['datetime'] ?? ''));
    }

    private function loadPushedSet(): void
    {
        if (!is_file($this->pushedCachePath)) {
            return;
        }

        $lines = @file($this->pushedCachePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $k = trim((string)$line);
            if ($k !== '') {
                $this->pushedSet[$k] = true;
            }
        }
    }

    private function isAlreadyPushed(string $key): bool
    {
        return isset($this->pushedSet[$key]);
    }

    private function markAsPushed(string $key): void
    {
        if ($key === '' || isset($this->pushedSet[$key])) {
            return;
        }

        $this->pushedSet[$key] = true;
        @file_put_contents($this->pushedCachePath, $key . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function extractHttpError(int $httpCode, string $body): string
    {
        $plain = $this->cleanText($body);
        if ($httpCode === 429) {
            return 'RATE_LIMIT_429: Too many requests (server rate limit).';
        }
        if ($httpCode === 405) {
            return 'METHOD_405: Endpoint method not allowed.';
        }
        if ($httpCode >= 500) {
            return "SERVER_{$httpCode}: Server error.";
        }
        if ($plain !== '') {
            return "HTTP_{$httpCode}: {$plain}";
        }
        return "HTTP_{$httpCode}: Request failed.";
    }

    private function cleanText(string $text): string
    {
        $t = trim(strip_tags($text));
        $t = preg_replace('/\s+/', ' ', $t);
        if (strlen($t) > 140) {
            $t = substr($t, 0, 140) . '...';
        }
        return $t;
    }

    private function classifyReason(string $msg): string
    {
        if (strpos($msg, 'RATE_LIMIT_429:') === 0) return 'RATE_LIMIT_429';
        if (strpos($msg, 'METHOD_405:') === 0) return 'METHOD_405';
        if (strpos($msg, 'NETWORK_ERROR:') === 0) return 'NETWORK_ERROR';
        if (strpos($msg, 'DB_NOT_CONFIRMED:') === 0) return 'DB_NOT_CONFIRMED';
        if (strpos($msg, 'SERVER_') === 0) return 'SERVER_ERROR';
        if (strpos($msg, 'HTTP_') === 0) return 'HTTP_ERROR';
        return 'OTHER';
    }

    private function isDbInsertConfirmed(string $response): bool
    {
        $resp = trim($response);
        if ($resp === '') return false;

        $json = json_decode($resp, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            if (isset($json['success']) && $json['success'] === true) return true;
            if (isset($json['status']) && strtolower((string)$json['status']) === 'success') return true;
            if (isset($json['inserted']) && (int)$json['inserted'] > 0) return true;
            if (isset($json['code']) && (int)$json['code'] === 200) return true;
            return false;
        }

        $low = strtolower($resp);
        $hasFailWord =
            strpos($low, 'error') !== false ||
            strpos($low, 'fail') !== false ||
            strpos($low, 'invalid') !== false ||
            strpos($low, 'not insert') !== false ||
            strpos($low, 'cannot') !== false;
        if ($hasFailWord) return false;

        $hasSuccessWord =
            strpos($low, 'success') !== false ||
            strpos($low, 'inserted') !== false ||
            strpos($low, 'saved') !== false ||
            strpos($low, 'ok') !== false;

        return $hasSuccessWord;
    }
}
