@echo off
REM Consola de estado del robot (T2.22). Abre su propia ventana y se queda
REM refrescando hasta que se la cierre con Ctrl+C.
REM
REM chcp 65001 antes de nada: sin eso la consola de Windows escribe los acentos
REM y los simbolos como basura, y esta pantalla la lee una persona.
REM
REM SOLO LECTURA: no escribe, no borra, no toca la base ni el buzon. Se puede
REM abrir y cerrar cuantas veces haga falta sin consecuencias.
chcp 65001 >nul
title B.IA Soft ERP - Consola del robot
cd /d "D:\INDUSTECH IA\desarrollo\agentes"
set PYTHONUTF8=1
".venv\Scripts\python.exe" "scripts\t2_22_consola_robot.py" %*
