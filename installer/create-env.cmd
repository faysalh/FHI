@echo off
setlocal
cd /d "%~dp0.."
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0create-env.ps1" %*
if errorlevel 1 (
    echo.
    echo Failed to create .env. See messages above.
    pause
    exit /b 1
)
echo.
pause
exit /b 0
