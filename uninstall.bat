@echo off
title xampp-land uninstall
rem Puts back the htdocs\index.php that install.bat replaced.
set "PHP=%~dp0..\..\php\php.exe"
if not exist "%PHP%" goto nophp

"%PHP%" -n "%~dp0lib\install.php" uninstall
echo.
echo You can now delete this folder if you no longer want xampp-land.
goto done

:nophp
echo Could not find XAMPP's PHP at:
echo   %PHP%
echo.
echo Restore it by hand: delete htdocs\index.php and rename
echo index.php.xampp-original back to index.php.

:done
echo.
pause
