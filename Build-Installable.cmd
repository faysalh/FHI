@echo off
setlocal
cd /d "%~dp0"
echo Reporting App — build single-file installer (.exe)
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\build-installer-exe.ps1" %*
if errorlevel 1 (
    echo.
    echo Build failed.
    pause
    exit /b 1
)
echo.
pause
exit /b 0
