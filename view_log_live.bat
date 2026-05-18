@echo off
cd /d "%~dp0"
title Fingerprint Push Log Viewer

if not exist "%~dp0logs\fingerprint.log" (
  echo [INFO] Log file not found yet:
  echo        %~dp0logs\fingerprint.log
  echo Run the runner first.
  pause
  goto :eof
)

echo ===============================================================
echo Live Log Viewer
echo - Showing new lines from logs\fingerprint.log
echo - Press Ctrl+C to stop
echo ===============================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Content -Path '%~dp0logs\fingerprint.log' -Wait -Tail 50"

