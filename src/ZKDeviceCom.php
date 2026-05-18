<?php
/**
 * ZKDeviceCom - Read attendance via zkemkeeper COM (Windows)
 * Requires PHP com_dotnet extension and zkemkeeper registration.
 */
class ZKDeviceCom
{
    private $ip;
    private $port;
    private $machineNumber;
    private $name;
    private $logger;
    private $zk = null;
    private $connected = false;

    public function __construct(array $cfg, $logger)
    {
        $this->ip            = $cfg['ip'];
        $this->port          = (int)$cfg['port'];
        $this->machineNumber = (int)$cfg['machine_number'];
        $this->name          = isset($cfg['name']) ? $cfg['name'] : "Machine #{$cfg['machine_number']}";
        $this->logger        = $logger;
    }

    public function connect($timeout = 10)
    {
        if (!class_exists('COM')) {
            $this->logger->error($this->tag(), "COM extension not enabled in this PHP");
            return false;
        }

        try {
            $this->zk = new COM("zkemkeeper.ZKEM");
        } catch (Throwable $e) {
            $arch = PHP_INT_SIZE === 8 ? 'x64' : 'x86';
            $this->logger->error($this->tag(), "Cannot create zkemkeeper COM: " . $e->getMessage());
            $this->logger->warn($this->tag(), "Current PHP is {$arch}. Ensure zkemkeeper COM with same bitness is registered.");
            return false;
        }

        try {
            $ok = $this->zk->Connect_Net($this->ip, $this->port);
            if (!$ok) {
                $this->logger->error($this->tag(), "Connect_Net failed");
                return false;
            }

            $this->connected = true;
            $this->logger->success($this->tag(), "Connected via COM");
            return true;
        } catch (Throwable $e) {
            $this->logger->error($this->tag(), "COM connect exception: " . $e->getMessage());
            return false;
        }
    }

    public function disconnect()
    {
        if ($this->zk && $this->connected) {
            try {
                $this->zk->Disconnect();
            } catch (Throwable $e) {
                // keep shutdown quiet
            }
        }
        $this->connected = false;
        $this->zk = null;
        $this->logger->info($this->tag(), "Disconnected");
    }

    public function isConnected() { return $this->connected; }

    public function getAttendanceLogs($dateFrom = null, $dateTo = null)
    {
        if (!$this->connected || !$this->zk) return array();

        $records = array();

        try {
            $ok = $this->zk->ReadGeneralLogData($this->machineNumber);
            if (!$ok) {
                $this->logger->warn($this->tag(), "ReadGeneralLogData returned false");
                return array();
            }

            $usedStrMode = false;
            while (true) {
                $enroll = '';
                $verify = 0;
                $type   = 0;
                $dtStr  = '';
                $work   = 0;

                try {
                    $has = $this->zk->SSR_GetGeneralLogDataStr(
                        $this->machineNumber,
                        $enroll,
                        $verify,
                        $type,
                        $dtStr,
                        $work
                    );
                    $usedStrMode = true;
                } catch (Throwable $e) {
                    // Fallback for SDK variants without SSR_GetGeneralLogDataStr support
                    $year = 0; $month = 0; $day = 0; $hour = 0; $minute = 0; $second = 0;
                    $has = $this->zk->SSR_GetGeneralLogData(
                        $this->machineNumber,
                        $enroll,
                        $verify,
                        $type,
                        $year,
                        $month,
                        $day,
                        $hour,
                        $minute,
                        $second,
                        $work
                    );
                    if ($has) {
                        $dtStr = sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                            (int)$year, (int)$month, (int)$day,
                            (int)$hour, (int)$minute, (int)$second
                        );
                    }
                }

                if (!$has) {
                    break;
                }

                $records[] = array(
                    'user_id'    => trim((string)$enroll),
                    'datetime'   => trim((string)$dtStr),
                    'type'       => (int)$type,
                    'machine_no' => $this->machineNumber,
                );
            }

            if ($usedStrMode) {
                $this->logger->info($this->tag(), "Using COM method: SSR_GetGeneralLogDataStr");
            }
        } catch (Throwable $e) {
            $this->logger->error($this->tag(), "Read logs failed: " . $e->getMessage());
            return array();
        }

        $total = count($records);
        if ($dateFrom || $dateTo) {
            $from = $dateFrom ? strtotime($dateFrom . ' 00:00:00') : 0;
            $to   = $dateTo ? strtotime($dateTo . ' 23:59:59') : PHP_INT_MAX;
            $records = array_values(array_filter($records, function ($r) use ($from, $to) {
                $t = strtotime($r['datetime']);
                return $t !== false && $t >= $from && $t <= $to;
            }));
            $this->logger->info($this->tag(), "Device total: $total | In range [{$dateFrom} -> {$dateTo}]: " . count($records));
            return $records;
        }

        $this->logger->info($this->tag(), "Total records: $total");
        return $records;
    }

    public function getName() { return $this->name; }
    public function getIp()   { return $this->ip; }
    private function tag()    { return "{$this->name} [{$this->ip}]"; }
}
