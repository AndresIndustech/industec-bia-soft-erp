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

REM T2.21.4: el codigo de salida se propaga al Programador de tareas. Sin esta
REM linea, el .bat termina con el codigo del ultimo comando "por costumbre" y no
REM por contrato; el 2026-09-18, con 13 empujes perdidos, LastTaskResult marco 0
REM en los 13 y nadie se entero de que el sitio mostraba datos viejos.
exit /b %ERRORLEVEL%
