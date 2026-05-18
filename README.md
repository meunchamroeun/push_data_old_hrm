# Fingerprint Push - ZKTeco to HRM API

PHP script to:
- Pull attendance logs from ZKTeco devices
- Push records to HRM API

This project supports 2 drivers:
- `socket` (direct TCP protocol)
- `com` (Windows `zkemkeeper` COM, recommended for this environment)

## Project Structure

```text
fingerprint_push/
|-- config/
|   `-- config.php
|-- src/
|   |-- Logger.php
|   |-- ZKDevice.php
|   |-- ZKDeviceCom.php
|   `-- ApiPusher.php
|-- logs/
|   `-- fingerprint.log
|-- tools/
|   `-- php-x86/runtime/php.exe
`-- run.php
```

## Important Notes

- API endpoint currently allows `GET` and `HEAD` only, so config uses `GET`.
- API has rate-limit (`HTTP 429`), so delay and retry are enabled.
- For COM driver, PHP bitness must match `zkemkeeper` bitness.

## Requirements

## 1) Windows + ZKTeco SDK (zkemkeeper)
- `zkemkeeper` must be installed/registered in Windows.

## 2) PHP x86 runtime for COM (already prepared in this project)
- Runtime path:
  - `tools/php-x86/runtime/php.exe`
- Enabled extensions:
  - `curl`
  - `sockets`
  - `com_dotnet`

## 3) Network
- Device port `4370` reachable from server.

## Configuration

Edit:
- `config/config.php`

Main sections:
- `devices`: device list (machine number, ip, port, name)
- `api.url`: HRM endpoint
- `api.method`: `GET` (required by current server)
- `api.delay_ms`: request delay to reduce `429`
- `api.retry_429`: retry count when rate-limited

## Run Commands

Use x86 runtime for COM:

```cmd
tools\php-x86\runtime\php.exe run.php --today --driver=com
```

Today (all devices):
```cmd
tools\php-x86\runtime\php.exe run.php --today --driver=com
```

This month:
```cmd
tools\php-x86\runtime\php.exe run.php --this-month --driver=com
```

This year:
```cmd
tools\php-x86\runtime\php.exe run.php --this-year --driver=com
```

Custom range:
```cmd
tools\php-x86\runtime\php.exe run.php --from=2026-05-10 --to=2026-05-18 --driver=com
```

From date until now:
```cmd
tools\php-x86\runtime\php.exe run.php --from=2026-05-10 --driver=com
```

Single device:
```cmd
tools\php-x86\runtime\php.exe run.php --today --device=1 --driver=com
```

Loop mode:
```cmd
tools\php-x86\runtime\php.exe run.php --loop --today --driver=com
```

## Driver Option

`--driver=` supports:
- `com` (recommended)
- `socket`
- `auto` (try socket, then COM)

Example:
```cmd
tools\php-x86\runtime\php.exe run.php --today --driver=auto
```

## Logging and Debug

Main log file:
- `logs/fingerprint.log`

Readable failure messages (improved):
- `RATE_LIMIT_429: Too many requests (server rate limit).`
- `METHOD_405: Endpoint method not allowed.`
- `NETWORK_ERROR: ...`

At summary, failures are grouped by reason for easier debugging.

## Task Scheduler (Windows)

Program:
```text
C:\Users\Administrator\Desktop\fingerprint_push\fingerprint_push\tools\php-x86\runtime\php.exe
```

Arguments:
```text
C:\Users\Administrator\Desktop\fingerprint_push\fingerprint_push\run.php --today --driver=com
```

Start in:
```text
C:\Users\Administrator\Desktop\fingerprint_push\fingerprint_push
```

## Troubleshooting

## COM class not registered
If you see:
- `Cannot create zkemkeeper COM: Class not registered`

Check:
- `zkemkeeper` installed correctly
- PHP is x86 when zkemkeeper is x86

## HTTP 405
- API does not allow POST
- Keep `api.method = 'GET'`

## HTTP 429
- Increase `api.delay_ms` (example: `1500` or `2000`)
- Increase `api.retry_429`
- Run smaller date ranges per job

