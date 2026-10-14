@echo off
set "PROJECT=C:\xampp\htdocs\escalation-tracker"
set "PHP=C:\xampp\php\php.exe"

if not exist "%PROJECT%\logs" mkdir "%PROJECT%\logs"
"%PHP%" "%PROJECT%\cron\fetch_new_mails.php" >> "%PROJECT%\logs\mail_fetch.log" 2>&1