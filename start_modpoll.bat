@echo off
setlocal
set "ROOT=C:\iwmac\wwwroot\modpoll"
set "TARGET=%ROOT%\index.php"
set "URL=https://github.com/spenz91/ModpollingTool/releases/download/modpollv2/index.php"

if not exist "%ROOT%" mkdir "%ROOT%"

if not exist "%TARGET%" (
  echo index.php not found. Downloading...
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing -Uri '%URL%' -OutFile '%TARGET%'"
  if errorlevel 1 (
    echo Download failed. Aborting.
    exit /b 1
  )
)

cd /d "%ROOT%"
start "PHP Server @ localhost:8000" cmd /k "php -S localhost:8000"
timeout /t 2 /nobreak >nul
start "" "http://localhost:8000"