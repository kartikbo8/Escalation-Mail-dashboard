@echo off
set "PROJECT=C:\xampp\htdocs\escalation-tracker"
set "PHP=C:\xampp\php\php.exe"

if not exist "%PROJECT%\logs" mkdir "%PROJECT%\logs"
"%PHP%" "%PROJECT%\cron\send_weekly_reports.php" >> "%PROJECT%\logs\weekly_reports.log" 2>&1
