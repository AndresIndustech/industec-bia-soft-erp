@echo off
REM Lanzador de la Tarea programada "INDUSTEC - Saneamiento nocturno" (T2.15.4).
REM
REM No calcula la fecha ni redirige: el propio script nombra su registro con
REM --log (logs\saneamiento-<fecha>.log). Ver el comentario de vigilante.bat
REM para el porque: cuantas menos piezas tenga el lanzador, menos formas hay
REM de que se rompa en silencio dentro de una Tarea programada.
REM
REM La ruta sale de la ubicacion de este .bat (scripts\ -> agentes\), asi que
REM sirve en la estacion y en cualquier otra copia del proyecto.
cd /d "%~dp0.."
".venv\Scripts\python.exe" "scripts\saneamiento_nocturno.py" --log
