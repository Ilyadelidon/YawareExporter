@echo off
cd /d "%~dp0"

set PLAYWRIGHT_BROWSERS_PATH=%~dp0browsers
set PYTHONIOENCODING=utf-8

"%~dp0node\node.exe" "%~dp0app\scripts\export-yaware-xls.mjs"

pause