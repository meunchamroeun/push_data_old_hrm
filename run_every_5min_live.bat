@echo off
setlocal enableextensions enabledelayedexpansion

cd /d "%~dp0"
title Fingerprint Push Live Runner (Every 5 Minutes)

set "PHP_EXE=%~dp0tools\php-x86\runtime\php.exe"
set "RUNNER=%~dp0run.php"
set "LOG_FILE=%~dp0logs\live_runner.log"

if not exist "%PHP_EXE%" (
  echo [ERROR] PHP runtime not found:
  echo         %PHP_EXE%
  echo Please check tools\php-x86\runtime\php.exe
  pause
  goto :eof
)

if not exist "%~dp0logs" mkdir "%~dp0logs"

echo ===============================================================
echo Fingerprint Push Live Runner
echo - Runs every 5 minutes
echo - Driver: COM
echo - This window stays open
echo - Press Ctrl+C to stop
echo ===============================================================
echo.

:loop
set "ts=%date% %time%"
echo [RUN] !ts! Starting cycle...
echo [RUN] !ts! Starting cycle...>> "%LOG_FILE%"

"%PHP_EXE%" "%RUNNER%" --today --driver=com 2>&1

set "ts=%date% %time%"
echo [WAIT] !ts! Sleeping 300 seconds...
echo [WAIT] !ts! Sleeping 300 seconds...>> "%LOG_FILE%"
echo.
timeout /t 300 /nobreak >nul
goto loop

