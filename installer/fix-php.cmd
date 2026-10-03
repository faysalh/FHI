@echo off
cd /d "%~dp0.."
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0fix-php.ps1" %*
if errorlevel 1 (
  echo.
  echo fix-php failed. See messages above.
  pause
  exit /b 1
)
pause
