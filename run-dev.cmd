@echo off
setlocal

cd /d "%~dp0"

echo ============================================
echo   Starting Reporting App Dev Environment
echo ============================================
echo.

where composer
if errorlevel 1 (
    echo [ERROR] Composer is not installed or not in PATH.
    goto :end
)

where npm
if errorlevel 1 (
    echo [ERROR] npm is not installed or not in PATH.
    goto :end
)

start "" "http://127.0.0.1:8000"
echo.
echo Running: composer dev
echo (Press Ctrl+C in this window to stop all dev services)
echo.
composer dev

:end
echo.
echo Press any key to close...
pause >nul
exit /b 0

