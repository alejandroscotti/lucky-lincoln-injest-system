@echo off
setlocal
cd /d "%~dp0"

if not exist .env (
  copy .env.example .env
  echo Created .env from .env.example
)

for /f "usebackq tokens=1,* delims==" %%a in (".env") do (
  if "%%a"=="API_HOST_PORT" set API_HOST_PORT=%%b
)
if not defined API_HOST_PORT set API_HOST_PORT=18430

echo Starting Revenue Reconciliation stack (Laravel + Vue + locations-feed)...
docker compose up --build -d --remove-orphans
if errorlevel 1 exit /b 1

echo Waiting for API...
set /a tries=0
:waitloop
set /a tries+=1
curl -sf http://localhost:%API_HOST_PORT%/api/health?ready=1 >nul 2>&1
if %errorlevel%==0 goto ready
if %tries% geq 60 (
  echo API did not become ready. Check: docker compose logs app
  exit /b 1
)
timeout /t 3 /nobreak >nul
goto waitloop

:ready
set URL=http://localhost:%API_HOST_PORT%
echo Opening %URL%
start "" "%URL%"
echo Stack running at %URL% (Laravel API + Vue UI + locations-feed scheduler)
echo Stop: docker compose down  ^|  Full reset: docker compose down -v
