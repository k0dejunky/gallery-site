@echo off
REM Build the Chat Trainer Control GUI EXE on the training PC.
REM Run once from C:\ai after copying trainer_gui.py, trainer_gui.spec and
REM trainer.ico there. Creates dist\ChatTrainerUI.exe and a desktop shortcut.
setlocal
cd /d C:\ai

echo [build] Running PyInstaller...
C:\Python38\python.exe -m PyInstaller --clean --noconfirm trainer_gui.spec > C:\work\build_ui.log 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [build] PyInstaller failed - see C:\work\build_ui.log
    exit /b 1
)

set EXE=C:\ai\dist\ChatTrainerUI.exe
if not exist "%EXE%" (
    echo [build] EXE not found at %EXE%
    exit /b 1
)
echo [build] Built: %EXE%

REM Create a desktop shortcut with the icon.
powershell -NoProfile -Command ^
  "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\Chat Trainer.lnk');" ^
  "$s.TargetPath='C:\ai\dist\ChatTrainerUI.exe';" ^
  "$s.WorkingDirectory='C:\ai\dist';" ^
  "$s.IconLocation='C:\ai\dist\ChatTrainerUI.exe,0';" ^
  "$s.Description='Chat trainer control';" ^
  "$s.Save()"
if %ERRORLEVEL% NEQ 0 (
    echo [build] WARNING: could not create desktop shortcut.
) else (
    echo [build] Desktop shortcut created: Chat Trainer.lnk
)

echo [build] Done.
pause