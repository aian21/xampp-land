@echo off
title xampp-land setup
rem Makes http://localhost/ (on whatever port Apache uses) open xampp-land. Backs up htdocs\index.php first.
set "PHP=%~dp0..\..\php\php.exe"
if not exist "%PHP%" goto nophp

"%PHP%" -n "%~dp0lib\install.php" install
goto done

:nophp
echo Could not find XAMPP's PHP at:
echo   %PHP%
echo.
echo Put this folder directly inside xampp\htdocs and run install.bat again.

:done
echo.
pause
