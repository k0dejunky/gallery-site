@echo off
REM Chat trainer control server launcher for the always-on training PC (Win7).
REM Starts trainer_control.py which supervises chat_trainer.py. Registered with
REM the Windows Task Scheduler so it starts at logon; the :loop keeps the
REM control server alive (the supervisor owns the trainer's lifecycle).
setlocal
set CONTROL_HOST=0.0.0.0
set CONTROL_PORT=8790
set TRAINER_PYTHON=C:\Python38\python.exe
set TRAINER_SCRIPT=C:\ai\chat_trainer.py
set CHAT_TRAINER_CONFIG=C:\work\chat_trainer_config.json
set PAUSE_FILE=C:\work\.chat_trainer_paused
set FORCE_TRAIN_FILE=C:\work\.chat_trainer_train_now
set STATUS_FILE=C:\work\.chat_trainer_status.json
set LOG_FILE=C:\work\chat_trainer.log
set RUNNER_BAT=C:\ai\run_chat_trainer.bat

:loop
C:\Python38\python.exe C:\ai\trainer_control.py
echo [control] control server exited (rc=%ERRORLEVEL%); restarting in 60s...
ping -n 60 127.0.0.1 > nul
goto loop