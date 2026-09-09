@echo off
REM Lanzador de la Tarea programada "INDUSTEC - Vigilante del buzon".
REM
REM No calcula la fecha ni redirige: el propio script nombra su registro con
REM --log. Antes el nombre salia de %DATE:~n,m%, que depende del formato
REM regional y aqui producia "vigilante-2026 0mi.log"; despues de un for /f
REM contra PowerShell, que funcionaba a mano pero fallaba en el contexto de la
REM Tarea programada y la dejaba saliendo con codigo 1 sin escribir una linea.
REM Cuantas menos piezas tenga el lanzador, menos formas hay de que se rompa
REM en silencio.
cd /d "D:\INDUSTECH IA\desarrollo\agentes"
".venv\Scripts\python.exe" "scripts\t2_9_buzon_vigilante.py" --dias 90 --log
