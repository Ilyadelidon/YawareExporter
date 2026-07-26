@echo off
rem Запуск сервісу звітності подвійним кліком
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-dev.ps1"
pause
