@echo off
title Start BUP Event & Protocol System
echo ====================================================
echo Starting Apache and MySQL for BUP Event Protocol...
echo ====================================================

start /B "" "D:\xamp\apache\bin\httpd.exe"
start /B "" "D:\xamp\mysql\bin\mysqld.exe" --defaults-file="D:\xamp\mysql\bin\my.ini" --standalone

echo Services started successfully!
echo.
echo Opening system in default browser...
start http://localhost/bup-event-protocol/login.php

exit
