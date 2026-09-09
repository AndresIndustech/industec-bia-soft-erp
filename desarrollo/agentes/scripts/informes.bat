@echo off
REM Actualiza que casos ya se atendieron y por quien, y lo manda al sitio.
REM Lo lanza la Tarea programada "INDUSTEC - Informes de OT" cada 3 horas.
REM
REM Es una medida TEMPORAL: mientras las ordenes se emitan en el sistema viejo,
REM la unica forma de saber que se atendio es leer los informes que llegan al
REM buzon. Cuando la emision pase al sistema nuevo, esta tarea se elimina.
cd /d "D:\INDUSTECH IA\desarrollo\agentes"
if not exist "logs" mkdir "logs"
".venv\Scripts\python.exe" "scripts\t2_11_informes_ot.py" --empujar >> "logs\informes-%DATE:~-4%%DATE:~3,2%%DATE:~0,2%.log" 2>&1
