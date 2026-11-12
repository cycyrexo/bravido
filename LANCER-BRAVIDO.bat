@echo off
title BRAVIDO - lancement
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0outils\lancer-bravido.ps1"
echo.
echo Vous pouvez fermer cette fenetre (l'application et le serveur restent allumes).
pause
