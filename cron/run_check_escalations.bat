@echo off
set "PROJECT=C:\xampp\htdocs\escalation-tracker"
set "PHP=C:\xampp\php\php.exe"

if not exist "%PROJECT%\logs" mkdir "%PROJECT%\logs"
echo [%date% %time%] >> "%PROJECT%\logs\escalation_check.log"
"%PHP%" "%PROJECT%\cron\check_escalations.php" >> "%PROJECT%\logs\escalation_check.log" 2>&1
