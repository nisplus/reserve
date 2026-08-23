@echo off
rem Run a project CLI script under the right PHP.
rem
rem Resolution order:
rem   1. %PHP_BIN%           - set this if PHP lives somewhere unusual
rem   2. a few known local installs
rem   3. php on PATH
rem
rem Going through this wrapper rather than typing `php` matters on machines that
rem also have an older PHP around: XAMPP ships 7.4, where enums and
rem str_starts_with do not exist and the failure is confusing.
rem
rem Usage: php bin\migrate.php

setlocal enabledelayedexpansion

if defined PHP_BIN goto :resolved

set "PHP_BIN=C:\Program Files (ext)\php\php.exe"
if exist "!PHP_BIN!" goto :resolved

set "PHP_BIN=C:\php\php.exe"
if exist "!PHP_BIN!" goto :resolved

for %%I in (php.exe) do set "PHP_BIN=%%~$PATH:I"
if not defined PHP_BIN goto :nophp
if "!PHP_BIN!"=="" goto :nophp

:resolved
rem Use the php.ini next to the binary when there is one, so the CLI and the
rem web server agree on extensions and timezone.
set "PHP_DIR=%PHP_BIN%"
for %%I in ("!PHP_BIN!") do set "PHP_DIR=%%~dpI"
set "PHP_INI=!PHP_DIR!php.ini"

if exist "!PHP_INI!" (
  "!PHP_BIN!" -c "!PHP_INI!" %*
) else (
  "!PHP_BIN!" %*
)
exit /b %ERRORLEVEL%

:nophp
echo.
echo PHP 8.2 or newer was not found.
echo Set PHP_BIN to the full path of php.exe, for example:
echo   set "PHP_BIN=C:\Program Files (ext)\php\php.exe"
echo See README.md for setup instructions.
exit /b 1
