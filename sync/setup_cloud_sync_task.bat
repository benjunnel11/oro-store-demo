@echo off
echo === Setting up OroStore Auto-Sync ===
echo Syncs device-to-device + cloud stock every 30 seconds.
echo.

:: Create VBS wrapper to run silently
echo Set ws = CreateObject("Wscript.Shell") > "%~dp0run_auto_sync.vbs"
echo ws.Run "C:\xampp\php\php.exe C:\xampp\htdocs\oro-store\sync\auto_sync.php", 0 >> "%~dp0run_auto_sync.vbs"

:: Remove old tasks if they exist
schtasks /delete /tn "OroStore_CloudSync" /f >nul 2>&1
schtasks /delete /tn "OroStore_AutoSync" /f >nul 2>&1

:: Create new task — runs every 1 minute as current user (needs ZeroTier network access)
schtasks /create /tn "OroStore_AutoSync" /tr "wscript.exe \"%~dp0run_auto_sync.vbs\"" /sc minute /mo 1 /f

echo.
echo Done! Auto-sync runs every 30 seconds in the background.
echo   - Device-to-device sync (users, transactions, etc.)
echo   - Cloud stock sync (TiDB Cloud)
echo   - No browser needed
echo.
echo To stop: schtasks /delete /tn "OroStore_AutoSync" /f
pause
