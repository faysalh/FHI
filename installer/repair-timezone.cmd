@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0repair-timezone.ps1" %*
