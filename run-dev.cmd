@echo off
setlocal

cd /d "%~dp0"

echo ============================================
echo   Starting Reporting App Dev Environment
echo ============================================
echo.

if exist "%~dp0runtime\php\php.exe" (
    echo This folder is a PRODUCTION install ^(IIS^).
    echo Composer and npm are not used on the server.
    echo.
    echo Use start-reporting-app.bat instead, or open your site URL in a browser.
    echo Example: http://10.10.10.250:8090/login
    echo.
    goto :end
)

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

echo App URL: http://127.0.0.1:8010  (dedicated port so it does not clash with other projects on :8000)
echo.
start "" "http://127.0.0.1:8010"
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

