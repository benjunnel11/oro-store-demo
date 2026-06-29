@echo off
echo ================================================
echo   Oro Store - Setup Nightly Cleanup Task
echo   Runs at 12:00 AM daily on branch devices
echo ================================================
echo.

:: Delete existing task if any
schtasks /delete /tn "OroStore_NightlyCleanup" /f >nul 2>&1

:: Create scheduled task to run at midnight
schtasks /create /tn "OroStore_NightlyCleanup" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\oro-store\sync\nightly_cleanup.php" /sc daily /st 00:00 /ru SYSTEM /f

if %errorlevel% equ 0 (
    echo.
    echo ================================================
    echo   Task created successfully!
    echo   Runs every day at 12:00 AM
    echo   Task name: OroStore_NightlyCleanup
    echo ================================================
) else (
    echo.
    echo   ERROR: Failed to create task.
    echo   Make sure you ran this as Administrator.
)

echo.
pause
