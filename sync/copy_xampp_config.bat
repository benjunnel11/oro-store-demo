@echo off
echo ================================================
echo   Copy XAMPP configs to a USB drive
echo   Run this on Device A to export configs
echo ================================================
echo.

set DEST=%1
if "%DEST%"=="" set DEST=C:\xampp_configs_backup

mkdir "%DEST%" 2>nul
copy "C:\xampp\php\php.ini" "%DEST%\php.ini"
copy "C:\xampp\mysql\bin\my.ini" "%DEST%\my.ini"
copy "C:\xampp\apache\conf\httpd.conf" "%DEST%\httpd.conf"
copy "C:\xampp\phpMyAdmin\config.inc.php" "%DEST%\phpmyadmin_config.inc.php"
copy "C:\xampp\phpMyAdmin\.htaccess" "%DEST%\phpmyadmin_htaccess"
copy "C:\xampp\htdocs\.htaccess" "%DEST%\htdocs_htaccess"
copy "C:\xampp\htdocs\dashboard\.htaccess" "%DEST%\dashboard_htaccess"

echo.
echo Saved to: %DEST%
echo.
echo To restore on another device, run:
echo   copy_xampp_config.bat restore
echo.
if "%1"=="restore" (
    echo Restoring configs...
    copy "%~dp0php.ini" "C:\xampp\php\php.ini"
    copy "%~dp0my.ini" "C:\xampp\mysql\bin\my.ini"
    copy "%~dp0httpd.conf" "C:\xampp\apache\conf\httpd.conf"
    copy "%~dp0phpmyadmin_config.inc.php" "C:\xampp\phpMyAdmin\config.inc.php"
    copy "%~dp0phpmyadmin_htaccess" "C:\xampp\phpMyAdmin\.htaccess"
    copy "%~dp0htdocs_htaccess" "C:\xampp\htdocs\.htaccess"
    copy "%~dp0dashboard_htaccess" "C:\xampp\htdocs\dashboard\.htaccess"
    echo Done! Restart Apache and MySQL in XAMPP.
)
pause
