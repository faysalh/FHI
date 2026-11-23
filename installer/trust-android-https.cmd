@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0trust-android-https.ps1" %*
