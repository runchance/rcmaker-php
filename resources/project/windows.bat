@echo off
setlocal EnableExtensions DisableDelayedExpansion
chcp 65001 >nul

pushd "%~dp0"
if errorlevel 1 exit /b 1

set "PHP_BIN=php"
if exist "%~dp0php.exe" set "PHP_BIN=%~dp0php.exe"

"%PHP_BIN%" "%~dp0index.php" start
set "RCMAKER_EXIT_CODE=%ERRORLEVEL%"
if not "%RCMAKER_EXIT_CODE%"=="0" (
    echo.
    echo RCMAKER 启动失败，请检查 PHP 环境和项目配置。
)

popd
pause
exit /b %RCMAKER_EXIT_CODE%
