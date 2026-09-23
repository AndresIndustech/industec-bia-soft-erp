@echo off
REM T2.27 — Los reportes para Grupo KFC, de un solo lanzador.
REM
REM   reportes_kfc.bat martes        STATUS_PENDIENTES del martes (hilo «ORDENES SEMANALES PENDIENTES _ INDUSTEC»)
REM   reportes_kfc.bat miercoles     respuesta al REPORTE SEMANA NN de KFC (plazo: miercoles 13h00)
REM   reportes_kfc.bat tablero       tablero de gerencia: el indicador de KFC y que cerrar en SAP antes del jueves
REM   reportes_kfc.bat kits          propuesta de pedido de kits de preventivo a la bodega de KFC
REM   reportes_kfc.bat presentacion  resumen de gestion por zona de los dos ultimos meses (PowerPoint)
REM
REM Todo lo que se genera va a SALIDAS IA\REPORTES\KFC\<fecha>\ con «(generado agente)» en el
REM nombre: nada se envia solo. El correo se LEE en modo solo lectura (no marca nada como leido).
REM Sin argumento, genera los del dia segun el dia de la semana.
cd /d "D:\INDUSTECH IA\desarrollo\agentes"
set PY=".venv\Scripts\python.exe"
set PYTHONUTF8=1
set QUE=%1
if "%QUE%"=="" (
  for /f %%d in ('powershell -NoProfile -Command "(Get-Date).DayOfWeek.value__"') do set DIA=%%d
)
if "%QUE%"=="" if "%DIA%"=="2" set QUE=martes
if "%QUE%"=="" if "%DIA%"=="3" set QUE=miercoles
if "%QUE%"=="" set QUE=tablero

if /i "%QUE%"=="martes"       %PY% scripts\t2_27_status_semanal.py & goto fin
if /i "%QUE%"=="miercoles"    %PY% scripts\t2_27_respuesta_kfc.py & %PY% scripts\t2_27_tablero_gerencia.py & goto fin
if /i "%QUE%"=="tablero"      %PY% scripts\t2_27_tablero_gerencia.py & goto fin
if /i "%QUE%"=="kits"         %PY% scripts\t2_27_kits_preventivo.py & goto fin
if /i "%QUE%"=="presentacion" %PY% scripts\t2_27_presentacion_gestion.py & goto fin
echo Opcion no reconocida: %QUE%. Usa martes, miercoles, tablero, kits o presentacion.
exit /b 2

:fin
echo.
echo Los archivos quedaron en D:\INDUSTECH IA\SALIDAS IA\REPORTES\KFC\
exit /b %ERRORLEVEL%
