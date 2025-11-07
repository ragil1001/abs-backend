@echo off
title Laravel Scheduler
color 0A

echo ========================================
echo  Laravel Scheduler Runner
echo ========================================
echo.
echo Scheduler berjalan setiap 1 menit
echo Tekan Ctrl+C untuk berhenti
echo.

REM GANTI DENGAN PATH PROJECT ANDA
cd /d D:\Magang\abs\New Folder\abs-backend

:loop
echo [%date% %time%] Running scheduler...
php artisan presensi:cek-otomatis
php artisan files:cleanup-old
php artisan notifications:cleanup-old
php artisan presensi:reminder-notification
timeout /t 60 /nobreak > nul
goto loop