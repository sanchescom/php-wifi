@echo off
REM PHP library for working with wifi network
REM
REM @author    Aleksandr Efimov <sanches.com@mail.ru>
REM @copyright 2019 Aleksandr Efimov

if "%PHP_PEAR_PHP_BIN%" neq "" (
    set PHPBIN=%PHP_PEAR_PHP_BIN%
) else set PHPBIN=php

"%PHPBIN%" "%~dp0\wifi" %*