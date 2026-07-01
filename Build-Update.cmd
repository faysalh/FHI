@echo off
setlocal
cd /d "%~dp0"
echo Reporting App — build UPDATE installer (preserves server SQLite)
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\build-update.ps1" %*
if errorlevel 1 (
    echo.
    echo Build failed.
    pause
    exit /b 1
)
echo.
pause
exit /b 0
