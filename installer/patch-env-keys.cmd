@echo off
powershell -ExecutionPolicy Bypass -File "%~dp0patch-env-keys.ps1" -InstallPath "%~dp0.."
pause
