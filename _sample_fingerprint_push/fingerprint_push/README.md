# Fingerprint Push — ZKTeco → HRM Server
### PHP 7.4 | Windows Server + Apache

---

## 📁 Project Files

```
fingerprint_push/
├── config/
│   └── config.php          ← កំណត់ IPs, API URL
├── src/
│   ├── Logger.php          ← Detailed log (console + file)
│   ├── ZKDevice.php        ← ភ្ជាប់ ZKTeco TCP + ទាញ logs
│   └── ApiPusher.php       ← Push HTTP POST → HRM API
├── logs/
│   └── fingerprint.log     ← Auto-created
└── run.php                 ← ✅ ចាប់ផ្តើម run នៅទីនេះ
```

---

## ⚙️ Requirements

```cmd
php -m | findstr /i "sockets curl pdo_mysql"
```
ត្រូវឃើញ: `sockets`, `curl`, `pdo_mysql`

---

## 🚀 Run Commands (Copy & Paste)

### Push Today
```cmd
php run.php --today
```

### Push This Month
```cmd
php run.php --this-month
```

### Push This Year
```cmd
php run.php --this-year
```

### Push Custom Date Range
```cmd
php run.php --from=2025-01-01 --to=2025-01-31
```

### Push Specific Device Only
```cmd
php run.php --today --device=1
php run.php --this-month --device=8
```

### Push ALL Data (no filter)
```cmd
php run.php --all
```

### Loop Mode (auto every 5 min)
```cmd
php run.php --loop --today
```

---

## 🪟 Windows Task Scheduler Setup

1. Open: **Task Scheduler** → **Create Basic Task**
2. Fill in:
   - **Name:** `Fingerprint Push Daily`
   - **Trigger:** Daily → Repeat every **5 minutes**
   - **Action:** Start a program
   - **Program:** `C:\php\php.exe`
   - **Arguments:** `C:\fingerprint_push\run.php --today`
   - **Start in:** `C:\fingerprint_push`

---

## 📋 Sample Log Output

```
──────────────────────────────────────────── CYCLE START | Range: Today (2025-05-18)
[INFO ] [2025-05-18 08:27:01] [MAIN] API Target: http://111.90.177.186:8080/api/...
──────────────────────────────── Device-1 (192.168.3.230:4370)
[INFO ] [2025-05-18 08:27:01] [DEVICE] Connecting to Device-1 (192.168.3.230:4370)...
[OK   ] [2025-05-18 08:27:02] [Device-1] Connected ✓  (session=1234)
[INFO ] [2025-05-18 08:27:03] [Device-1] Total records pulled: 48
──────────────────────────────────────────── Records Detail
  [OK  ] User: 001          | DateTime: 2025-05-18 08:05:22 | Punch: Check-In       | Machine: #1 | API: {"success":true}
  [OK  ] User: 002          | DateTime: 2025-05-18 08:07:10 | Punch: Check-In       | Machine: #1 | API: {"success":true}
  [OK  ] User: 001          | DateTime: 2025-05-18 17:30:00 | Punch: Check-Out      | Machine: #1 | API: {"success":true}
──────────────────────────────────────────── Summary
[OK   ] [PUSH] Done Device-1 → Total: 48 | ✓ Success: 48 | ✗ Failed: 0
──────────────────────────────────────────── GRAND TOTAL
[OK   ] [MAIN] All devices done | Total: 215 | ✓ Success: 215 | ✗ Failed: 0
```

---

## 🔌 Device List

| Machine # | IP | Location |
|---|---|---|
| 1 | 192.168.3.230 | Office |
| 2 | 192.168.3.231 | Office |
| 3 | 192.168.3.232 | Office |
| 6 | 192.168.3.237 | Office |
| 8 | 103.7.25.130  | Remote |

---

## 🔄 Punch Types

| Value | Meaning |
|---|---|
| 0 | Check-In |
| 1 | Check-Out |
| 2 | Break-Out |
| 3 | Break-In |
| 4 | Overtime-In |
| 5 | Overtime-Out |
