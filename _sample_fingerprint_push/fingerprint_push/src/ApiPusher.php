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

    public function __construct(string $apiUrl, int $timeout, Logger $logger)
    {
        $this->apiUrl  = $apiUrl;
        $this->timeout = $timeout;
        $this->logger  = $logger;
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
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [false, "CURL Error: $curlError"];
        }
        if ($httpCode !== 200) {
            return [false, "HTTP $httpCode: $response"];
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

        $this->logger->info("PUSH", "Starting push: $total records from $deviceName → {$this->apiUrl}");
        $this->logger->separator("Records Detail");

        foreach ($records as $index => $rec) {
            $num = $index + 1;

            [$ok, $apiResp] = $this->pushOne($rec);

            if ($ok) {
                $success++;
                $this->logger->record($rec, 'OK', $apiResp);
            } else {
                $failed++;
                $this->logger->record($rec, 'FAIL', $apiResp);
                $this->logger->error("PUSH", "Failed #{$num}: user={$rec['user_id']} dt={$rec['datetime']} → $apiResp");
            }

            // Small delay to avoid server flood
            usleep(30000); // 30ms
        }

        $this->logger->separator("Summary");
        $this->logger->success("PUSH", "Done $deviceName → Total: $total | ✓ Success: $success | ✗ Failed: $failed");

        return ['success' => $success, 'failed' => $failed, 'total' => $total];
    }
}
