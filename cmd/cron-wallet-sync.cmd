@echo off
REM ============================================================================
REM  cron-wallet-sync.cmd — synchronizace bankovnich pohybu z Wallet (BudgetBakers)
REM  Frekvence: kazdych 30 minut (Wallet sam synchronizuje banky nekolikrat denne)
REM  Inkrementalni import + automaticke parovani plateb pro suppliery s tokenem.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice WalletSync" ^
REM      /tr "%~f0" /sc minute /mo 30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-wallet-sync.php" %* >> "%LOG_DIR%\wallet-sync-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
