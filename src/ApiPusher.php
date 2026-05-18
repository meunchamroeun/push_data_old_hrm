<?php
/**
 * ApiPusher — HTTP POST attendance records to HRM server
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

    public function __construct(string $apiUrl, int $timeout, Logger $logger, string $method = 'POST', int $delayMs = 30, int $retry429 = 2)
    {
        $this->apiUrl  = $apiUrl;
        $this->timeout = $timeout;
        $this->logger  = $logger;
        $this->method  = strtoupper($method);
        $this->delayMs = $delayMs;
        $this->retry429 = $retry429;
    }

    /**
     * Push one record — returns [bool $success, string $apiResponse]
     */
    public function pushOne($rec)
    {
        $postData = http_build_query([
            'machine_no' => $rec['machine_no'] ?? '',
            'user_id'    => $rec['user_id']    ?? '',
            'datetime'   => $rec['datetime']   ?? '',
            'type'       => $rec['type']        ?? 0,
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
            return [false, "NETWORK_ERROR: " . $this->cleanText($curlError)];
        }
        if ($httpCode !== 200) {
            $msg = $this->extractHttpError($httpCode, (string)$response);
            return [false, $msg];
        }

        return [true, trim($response)];
    }

    /**
     * Push all records with live progress + summary
     *
     * @return array ['success'=>int, 'failed'=>int, 'total'=>int]
     */
    public function pushBatch(array $records, $deviceName)
    {
        $total   = count($records);
        $success = 0;
        $failed  = 0;
        $failReasons = [];

        $this->logger->info("PUSH", "Starting push: $total records from $deviceName → {$this->apiUrl}");
        $this->logger->separator("Records Detail");

        foreach ($records as $index => $rec) {
            $num = $index + 1;

            $attempt = 0;
            $ok = false;
            $apiResp = '';
            do {
                [$ok, $apiResp] = $this->pushOne($rec);
                if ($ok) break;
                if (strpos((string)$apiResp, 'HTTP 429:') !== 0) break;
                if ($attempt >= $this->retry429) break;
                usleep(1000000 * ($attempt + 1)); // 1s, 2s, ...
                $attempt++;
            } while (true);

            if ($ok) {
                $success++;
                $this->logger->record($rec, 'OK', $apiResp);
            } else {
                $failed++;
                $key = $this->classifyReason($apiResp);
                $failReasons[$key] = isset($failReasons[$key]) ? $failReasons[$key] + 1 : 1;
                $this->logger->record($rec, 'FAIL', $apiResp);
                $this->logger->error("PUSH", "Failed #{$num}: user={$rec['user_id']} dt={$rec['datetime']} → $apiResp");
            }

            // Small delay to avoid server flood
            usleep(max(0, $this->delayMs) * 1000);
        }

        $this->logger->separator("Summary");
        $this->logger->success("PUSH", "Done $deviceName → Total: $total | ✓ Success: $success | ✗ Failed: $failed");
        if (!empty($failReasons)) {
            foreach ($failReasons as $reason => $count) {
                $this->logger->warn("PUSH", "Fail reason: {$reason} | Count: {$count}");
            }
        }

        return ['success' => $success, 'failed' => $failed, 'total' => $total];
    }

    private function extractHttpError(int $httpCode, string $body): string
    {
        $plain = $this->cleanText($body);
        if ($httpCode === 429) {
            return "RATE_LIMIT_429: Too many requests (server rate limit).";
        }
        if ($httpCode === 405) {
            return "METHOD_405: Endpoint method not allowed.";
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
        if (strpos($msg, 'SERVER_') === 0) return 'SERVER_ERROR';
        if (strpos($msg, 'HTTP_') === 0) return 'HTTP_ERROR';
        return 'OTHER';
    }
}
