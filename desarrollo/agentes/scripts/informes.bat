@echo off
REM Lanzador de la Tarea programada "INDUSTEC - Informes de OT".
REM
REM Lee los informes de OT del buzon y actualiza que casos ya se atendieron y
REM por quien. Es TEMPORAL: mientras las ordenes se emitan en el sistema viejo,
REM la unica forma de saberlo es leer el correo. Cuando la emision pase al
REM sistema nuevo, esta tarea se elimina.
REM
REM El nombre del registro lo pone el propio script con --log; ver el comentario
REM de vigilante.bat para el porque.
cd /d "D:\INDUSTECH IA\desarrollo\agentes"
".venv\Scripts\python.exe" "scripts\t2_11_informes_ot.py" --empujar --log
