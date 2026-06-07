@echo off
setlocal
cd /d "%~dp0"

if exist "runtime\php\php.exe" (
    if exist ".env" goto :production
    echo.
    echo [ERROR] Production install is missing .env
    echo Run installer\create-env.cmd first, then try again.
    echo.
    goto :end
)

if exist "artisan" if not exist "runtime\php\php.exe" goto :dev

echo.
echo [ERROR] Cannot determine how to start this copy of Reporting App.
echo.
echo Production server: run ReportingApp-Setup.exe as Administrator, then open the URL in a browser.
echo Development PC:    install Composer and npm, then use this shortcut again.
echo.
goto :end

:production
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0installer\start-reporting-app.ps1"
goto :end

:dev
start "Reporting App Dev" cmd /k ""%~dp0run-dev.cmd""
exit /b 0

:end
echo Press any key to close...
pause >nul
exit /b 0
