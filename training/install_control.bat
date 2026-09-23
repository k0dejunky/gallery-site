@echo off
REM One-time install: registers the ChatTrainer at-logon scheduled task and
REM starts the control server immediately. Run this once as the k0debox user
REM (double-click or run in a Command Prompt). SMB cannot execute processes,
REM so this must be run while logged into the training PC (or via RDP).
setlocal

echo Registering ChatTrainer scheduled task (at logon)...
schtasks /Create /TN "ChatTrainer" /TR "C:\ai\run_chat_trainer.bat" /SC ONLOGON /RL HIGHEST /F
if %ERRORLEVEL% NEQ 0 (
    echo WARNING: could not register the scheduled task.
    echo Run this .bat from an elevated (Administrator) Command Prompt.
) else (
    echo Scheduled task registered.
)

echo.
echo Starting the control server now (http://192.168.1.250:8790)...
start "" C:\ai\run_chat_trainer.bat
echo.
echo Done. Open http://192.168.1.250:8790 in a browser on this PC or any LAN machine.
pause