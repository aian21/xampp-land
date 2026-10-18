@echo off
title xampp-land setup
rem Makes http://localhost/ open xampp-land. Backs up htdocs\index.php first.
set "PHP=%~dp0..\..\php\php.exe"
if not exist "%PHP%" goto nophp

"%PHP%" -n "%~dp0lib\install.php" install
if errorlevel 1 goto done

echo.
echo Done. Make sure Apache is started in the XAMPP Control Panel.
echo Opening http://localhost/ ...
start "" "http://localhost/"
goto done

:nophp
echo Could not find XAMPP's PHP at:
echo   %PHP%
echo.
echo Put this folder directly inside xampp\htdocs and run install.bat again.

:done
echo.
pause
