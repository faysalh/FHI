@echo off
powershell -ExecutionPolicy Bypass -File "%~dp0repair-env.ps1" -InstallPath "%~dp0.."
pause
