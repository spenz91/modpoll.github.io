@echo off
setlocal
set "TARGET_DIR=C:\iwmac\wwwroot\modpoll"
set "TARGET_FILE=%TARGET_DIR%\index.php"
set "RAW_URL=https://raw.githubusercontent.com/spenz91/modpoll.github.io/main/index.php"

if not exist "%TARGET_DIR%" (
  mkdir "%TARGET_DIR%"
)

if not exist "%TARGET_FILE%" (
  echo index.php not found in "%TARGET_DIR%".
  set /p ANSW=Do you want to download the index.php for modpolling? [y/n]: 
  if /I "%ANSW%"=="Y" (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -UseBasicParsing -Uri '%RAW_URL%' -OutFile '%TARGET_FILE%'; Write-Host 'Downloaded index.php'; } catch { Write-Host ('Download failed: ' + $_.Exception.Message); exit 1 }"
    if errorlevel 1 (
      echo Download failed. Exiting.
      goto :end
    )
  ) else (
    echo Not downloading. Exiting.
    goto :end
  )
)

cd /d "%TARGET_DIR%"
start "" http://127.0.0.1:8080
php -S 127.0.0.1:8080

:end
endlocal


