@echo off
rem Development server.
rem
rem NOTE: on Windows the built-in server handles one request at a time.
rem       PHP_CLI_SERVER_WORKERS is POSIX-only and is ignored here, so race
rem       conditions cannot be reproduced over HTTP. Use bin\concurrency_test.php.
rem
rem Usage: serve.cmd [host:port]     default 127.0.0.1:8000

setlocal

set "HOSTPORT=127.0.0.1:8000"
if not "%~1"=="" set "HOSTPORT=%~1"

cd /D "%~dp0"
echo Serving http://%HOSTPORT%/  (Ctrl+C to stop)
call "%~dp0php.cmd" -S %HOSTPORT% -t public public\index.php
endlocal
