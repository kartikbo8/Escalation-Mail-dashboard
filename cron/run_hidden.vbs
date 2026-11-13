' Runs a cron .bat with no console window, so the run cannot be stopped by
' someone closing the window (Task Scheduler "Interactive only" tasks).
' Waits for the .bat to finish so the scheduler's "do not start a new
' instance" rule still prevents overlapping runs.
'
' Usage: wscript.exe run_hidden.vbs "C:\path\to\script.bat"
If WScript.Arguments.Count < 1 Then WScript.Quit 2
Set shell = CreateObject("WScript.Shell")
WScript.Quit shell.Run("cmd.exe /c """ & WScript.Arguments(0) & """", 0, True)
