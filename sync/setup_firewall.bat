@echo off
echo ================================================
echo   Oro Store - Firewall Setup
echo ================================================
echo.

echo [1/3] Allowing Apache (Port 80)...
netsh advfirewall firewall delete rule name="Oro Store Web Server" >nul 2>&1
netsh advfirewall firewall add rule name="Oro Store Web Server" dir=in action=allow protocol=TCP localport=80 profile=any
echo.

echo [2/3] Allowing Ping (ICMP)...
netsh advfirewall firewall delete rule name="Oro Store Ping" >nul 2>&1
netsh advfirewall firewall add rule name="Oro Store Ping" dir=in action=allow protocol=ICMPv4 profile=any
echo.

echo [3/3] Blocking MySQL (Port 3306) from network...
netsh advfirewall firewall delete rule name="Oro Store MySQL" >nul 2>&1
netsh advfirewall firewall add rule name="Oro Store MySQL" dir=in action=block protocol=TCP localport=3306 profile=any
echo.

echo ================================================
echo   Done! All firewall rules applied.
echo   You can close this window now.
echo ================================================
pause
