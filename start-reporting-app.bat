@echo off
setlocal

cd /d "%~dp0"

start "Reporting App Dev" cmd /k ""%~dp0run-dev.cmd""

exit /b 0

