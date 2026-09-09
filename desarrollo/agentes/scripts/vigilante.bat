@echo off
REM Arranca el vigilante del buzon. Lo lanza la Tarea programada
REM "INDUSTEC - Vigilante del buzon" al iniciar sesion en la estacion.
REM
REM El log se guarda con la fecha del dia: si algo dejo de sincronizar, ahi
REM esta la hora del ultimo intento, aunque haya fallado.
cd /d "D:\INDUSTECH IA\desarrollo\agentes"
if not exist "logs" mkdir "logs"
".venv\Scripts\python.exe" "scripts\t2_9_buzon_vigilante.py" --dias 90 >> "logs\vigilante-%DATE:~-4%%DATE:~3,2%%DATE:~0,2%.log" 2>&1
