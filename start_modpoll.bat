@echo off
setlocal
if not exist "C:\iwmac\wwwroot\modpoll" mkdir "C:\iwmac\wwwroot\modpoll"
cd /d C:\iwmac\wwwroot\modpoll
if errorlevel 1 (
  echo Failed to change directory to C:\iwmac\wwwroot\modpoll
  pause
  exit /b 1
)
if not exist "index.php" (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -UseBasicParsing -Uri 'https://raw.githubusercontent.com/spenz91/modpoll.github.io/main/index.php' -OutFile 'index.php' } catch { Write-Error $_; exit 1 }"
  if errorlevel 1 (
    echo Failed to download index.php from GitHub
    pause
    exit /b 1
  )
)
start "PHP Server @ localhost:8000" cmd /k "php -S localhost:8000"
timeout /t 2 /nobreak >nul
start "" "http://localhost:8000"
