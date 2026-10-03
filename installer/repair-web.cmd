@echo off
setlocal
cd /d "%~dp0.."
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0repair-web.ps1" %*
if errorlevel 1 (
    echo.
    echo Repair failed. See messages above, or run installer\diagnose.cmd
    pause
    exit /b 1
)
echo.
pause
exit /b 0
