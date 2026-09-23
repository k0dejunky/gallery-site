# -*- mode: python ; coding: utf-8 -*-
# PyInstaller spec for the Chat Trainer Control GUI.
# Build on the training PC with:
#   C:\Python38\python.exe -m PyInstaller --clean trainer_gui.spec
# Produces dist\ChatTrainerUI.exe (single file, windowed, no console).

a = Analysis(
    ["trainer_gui.py"],
    pathex=[],
    binaries=[],
    datas=[],
    hiddenimports=[],
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=["PIL", "numpy", "torch", "transformers", "datasets", "peft"],
    noarchive=False,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name="ChatTrainerUI",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    upx_exclude=[],
    runtime_tmpdir=None,
    console=False,          # windowed app - no console window
    icon="trainer.ico",
)