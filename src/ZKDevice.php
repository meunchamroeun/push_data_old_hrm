<?php
/**
 * ZKDevice — ZKTeco TCP Connection (Corrected Protocol)
 * PHP 7.2+ compatible
 */
class ZKDevice
{
    private $ip;
    private $port;
    private $machineNumber;
    private $name;
    private $socket    = null;
    private $connected = false;
    private $logger;

    const CMD_CONNECT      = 1000;
    const CMD_EXIT         = 1001;
    const CMD_ACK_OK       = 2000;
    const CMD_ACK_ERROR    = 2001;
    const CMD_PREPARE_DATA = 20;
    const CMD_DATA         = 16;
    const CMD_FREE_DATA    = 18;
    const CMD_ATTLOG_RRQ   = 13;

    private $sessionId = 0;
    private $replyId   = 65534;
    private $packetMode = 'framed';

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
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $this->logger->error($this->tag(), "socket_create failed: " . socket_strerror(socket_last_error()));
            return false;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $timeout, 'usec' => 0));

        $result = @socket_connect($this->socket, $this->ip, $this->port);
        if ($result === false) {
            $err = socket_strerror(socket_last_error($this->socket));
            $this->logger->error($this->tag(), "TCP connect failed: $err");
            socket_close($this->socket);
            $this->socket = null;
            return false;
        }

        $this->logger->info($this->tag(), "TCP OK — sending ZK handshake...");

        $modes = array('framed', 'raw');
        foreach ($modes as $mode) {
            $this->sessionId = 0;
            $this->replyId   = 65534;
            $this->packetMode = $mode;

            $this->logger->info($this->tag(), "Trying handshake mode: {$mode}");
            $this->send($this->makePacket(self::CMD_CONNECT, ''));

            $resp = $this->recv();
            if ($resp === null) {
                $this->logger->warn($this->tag(), "No response in {$mode} mode");
                continue;
            }

            $this->logger->info($this->tag(), "Response (" . strlen($resp) . " bytes) hex: " . $this->hex($resp));

            $cmd = $this->getCmd($resp);
            if ($cmd !== self::CMD_ACK_OK) {
                $this->logger->warn($this->tag(), "Handshake failed in {$mode} mode: cmd=$cmd");
                continue;
            }

            $this->sessionId = $this->getSid($resp);
            $this->connected = true;
            $this->logger->success($this->tag(), "Connected OK (session={$this->sessionId}, mode={$this->packetMode})");
            return true;
        }

        $this->logger->error($this->tag(), "No response to CMD_CONNECT in all modes");
        $this->closeSocket();
        return false;
    }

    public function disconnect()
    {
        if ($this->socket && $this->connected) {
            @$this->send($this->makePacket(self::CMD_EXIT, ''));
        }
        $this->closeSocket();
        $this->connected = false;
        $this->logger->info($this->tag(), "Disconnected");
    }

    public function isConnected() { return $this->connected; }

    public function getAttendanceLogs($dateFrom = null, $dateTo = null)
    {
        if (!$this->connected) return array();

        $this->send($this->makePacket(self::CMD_ATTLOG_RRQ, ''));
        $raw = $this->readLargeData();

        if ($raw === null || strlen($raw) === 0) {
            $this->logger->warn($this->tag(), "No attendance data from device");
            return array();
        }

        $this->logger->info($this->tag(), "Raw data: " . strlen($raw) . " bytes");

        $records = $this->parseAttLog($raw);
        $total   = count($records);

        if ($dateFrom || $dateTo) {
            $from = $dateFrom ? strtotime($dateFrom . ' 00:00:00') : 0;
            $to   = $dateTo   ? strtotime($dateTo   . ' 23:59:59') : PHP_INT_MAX;

            $records = array_values(array_filter($records, function ($r) use ($from, $to) {
                $t = strtotime($r['datetime']);
                return $t !== false && $t >= $from && $t <= $to;
            }));

            $this->logger->info($this->tag(),
                "Device total: $total | In range [{$dateFrom} → {$dateTo}]: " . count($records));
        } else {
            $this->logger->info($this->tag(), "Total records: $total");
        }

        return $records;
    }

    // ── ZKTeco Packet ─────────────────────────────────────────────
    // Structure: [4-byte length LE][8-byte header][data]
    // Header:    cmd(2) checksum(2) sessionId(2) replyId(2)

    private function makePacket($cmd, $data)
    {
        $this->replyId = ($this->replyId + 1) & 0xFFFF;
        $header  = pack('vvvv', $cmd, 0, $this->sessionId, $this->replyId);
        if ($this->packetMode === 'raw') {
            return $header . $data;
        }

        $payload = $header . $data;
        return pack('V', strlen($payload)) . $payload;
    }

    private function send($data)
    {
        $len  = strlen($data);
        $sent = 0;
        while ($sent < $len) {
            $n = @socket_send($this->socket, substr($data, $sent), $len - $sent, 0);
            if ($n === false || $n <= 0) break;
            $sent += $n;
        }
    }

    private function recv()
    {
        if ($this->packetMode === 'raw') {
            $header = '';
            $got = @socket_recv($this->socket, $header, 8, MSG_WAITALL);
            if (!$got || $got < 8) {
                $this->logger->warn($this->tag(), "recv(raw): header incomplete ($got bytes)");
                return null;
            }

            $peek = '';
            $peekGot = @socket_recv($this->socket, $peek, 8192, MSG_DONTWAIT);
            if ($peekGot === false) {
                return $header;
            }

            if ($peekGot > 0) {
                return $header . $peek;
            }

            return $header;
        }

        // Read 4-byte length prefix (framed mode)
        $buf = '';
        $got = @socket_recv($this->socket, $buf, 4, MSG_WAITALL);
        if (!$got || $got < 4) {
            $this->logger->warn($this->tag(), "recv: length prefix incomplete ($got bytes)");
            return null;
        }

        $u   = unpack('V', $buf);
        $len = $u[1];

        if ($len <= 0 || $len > 1048576) {
            $this->logger->warn($this->tag(), "recv: bad length=$len hex=" . $this->hex($buf));
            return null;
        }

        $body = '';
        $got  = @socket_recv($this->socket, $body, $len, MSG_WAITALL);

        if ($got === false || $got === 0) return null;

        return $body;
    }

    private function getCmd($p)
    {
        if (strlen($p) < 2) return -1;
        $u = unpack('v', substr($p, 0, 2));
        return $u[1];
    }

    private function getSid($p)
    {
        if (strlen($p) < 6) return 0;
        $u = unpack('v', substr($p, 4, 2));
        return $u[1];
    }

    private function readLargeData()
    {
        $buffer    = '';
        $totalSize = 0;
        $maxLoops  = 2048;
        $loops     = 0;

        while ($loops++ < $maxLoops) {
            $resp = $this->recv();
            if ($resp === null) {
                $this->logger->warn($this->tag(), "readLargeData: recv null at loop=$loops");
                break;
            }

            $cmd = $this->getCmd($resp);

            if ($cmd === self::CMD_PREPARE_DATA) {
                if (strlen($resp) >= 12) {
                    $u = unpack('V', substr($resp, 8, 4));
                    $totalSize = $u[1];
                    $this->logger->info($this->tag(), "PREPARE_DATA: $totalSize bytes incoming");
                }

            } elseif ($cmd === self::CMD_DATA) {
                $chunk   = substr($resp, 8);
                $buffer .= $chunk;
                if ($totalSize > 0 && strlen($buffer) >= $totalSize) break;

            } elseif ($cmd === self::CMD_ACK_OK) {
                break;

            } elseif ($cmd === self::CMD_ACK_ERROR) {
                $this->logger->error($this->tag(), "ACK_ERROR during data read");
                return null;

            } else {
                $this->logger->warn($this->tag(), "Unknown cmd=$cmd during read");
                break;
            }
        }

        @$this->send($this->makePacket(self::CMD_FREE_DATA, ''));
        return $buffer;
    }

    private function parseAttLog($data)
    {
        $records = array();
        $recSize = 40;
        $count   = (int)(strlen($data) / $recSize);

        for ($i = 0; $i < $count; $i++) {
            $rec = substr($data, $i * $recSize, $recSize);
            if (strlen($rec) < $recSize) continue;

            $userId = trim(rtrim(substr($rec, 0, 9), "\x00 "));

            $punch  = ord($rec[26]);

            $u      = unpack('V', substr($rec, 27, 4));
            $dt     = $this->decodeZKTime($u[1]);

            if ($userId !== '' && $dt !== null) {
                $records[] = array(
                    'user_id'    => $userId,
                    'datetime'   => $dt,
                    'type'       => $punch,
                    'machine_no' => $this->machineNumber,
                );
            }
        }

        return $records;
    }

    private function decodeZKTime($t)
    {
        if ($t === 0) return null;
        $s  = $t % 60;      $t = (int)($t / 60);
        $m  = $t % 60;      $t = (int)($t / 60);
        $h  = $t % 24;      $t = (int)($t / 24);
        $d  = $t % 31 + 1;  $t = (int)($t / 31);
        $mo = $t % 12 + 1;  $t = (int)($t / 12);
        $y  = $t + 2000;
        if ($y < 2000 || $y > 2099 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $m, $s);
    }

    private function closeSocket()
    {
        if ($this->socket) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function hex($data)
    {
        $out = '';
        $len = min(strlen($data), 32);
        for ($i = 0; $i < $len; $i++) $out .= sprintf('%02X ', ord($data[$i]));
        return rtrim($out) . (strlen($data) > 32 ? '...' : '');
    }

    public function getName() { return $this->name; }
    public function getIp()   { return $this->ip; }
    private function tag()    { return "{$this->name} [{$this->ip}]"; }
}
