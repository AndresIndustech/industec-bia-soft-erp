"""
T1.7 / T2.29 - Pruebas del extractor de PDF (`t1_7_extractor_pdf.py`) frente al PDF
que emite la APP (desde el 2026-09-29), que no es identico al del formulario viejo:

  - marca, modelo y serie van en UNA linea («Marca: X · Modelo: Y · Serie: Z»);
  - la hora trae fecha («2026-09-30 08:00», no «08:00»): `tiempo_atencion_min`
    quedaba en NULL aunque el PDF dijera «Tiempo de Atención: 1h 0m»;
  - hay lineas nuevas (Tipo de Trabajo, Equipo SAP, Ubicación técnica, Área,
    Observación) y secciones nuevas: «TRABAJO NO CONCLUIDO» (el pedido de
    repuesto del tecnico, 2026-10-01) y «TRABAJO CON OTRO PROVEEDOR»;
  - la evidencia se titula «EVIDENCIA FOTOGRÁFICA POR EQUIPO».
Sin un corte propio, cada una se tragaba en la seccion anterior y ensuciaba los
campos. Los textos son sinteticos (sin datos de clientes); trabaja sobre el
TEXTO del PDF, asi no hace falta ningun PDF.

Uso:
    .venv/Scripts/python.exe scripts/t1_7_extractor_pruebas.py      # sale con 1 si algo falla
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import t1_7_extractor_pdf as X  # noqa: E402

total = fallos = 0


def afirmar(que, real, esperado):
    global total, fallos
    total += 1
    ok = real == esperado
    if not ok:
        fallos += 1
    print(f"  {'ok   ' if ok else 'FALLA'} {que}" + ("" if ok else f"  -> dio {real!r}, esperaba {esperado!r}"))


VIEJO = """ORDEN DE TRABAJO OT-1964-G018-10357626-UIO
Documento generado automáticamente
DATOS GENERALES
ID-ORDEN-INDUSTEC: OT-1964-G018-10357626-UIO
ID-ORDEN-GRUPOKFC: 10357626
Fecha de Atención: 2026-09-30
Cliente: Pollo Prueba
Local: G018
Técnico Asignado: Técnico Uno
Administrador del local: Ana Prueba
Correo del Local: local@ejemplo.test
Correo de Jefe de Operaciones Local: jefe@ejemplo.test
DETALLE DEL EQUIPO
Equipo: Freidora de presas
Marca: SUPERFRYER
Modelo: OFG-321
Serie: S/N
Código Activo Fijo: S/N
Estado del Equipo: Operativo
DETALLE DE LA INTERVENCIÓN
Hora Inicio: 15:30
Hora Fin: 16:30
Tiempo de Atención: 1h 0m
Actividades:
- Gerente informa que el equipo no enciende la bomba
- Equipo queda operativo
REPUESTOS
Ninguna
OBSERVACIONES
Ninguna
ESTADO DE LA OT
Cerrada
EVIDENCIA FOTOGRÁFICA
Sin fotos
SATISFACCIÓN DEL CLIENTE
Su requerimiento fue atendido a tiempo: SI
Calificación: 10/10
FIRMA DEL ADMINISTRADOR
Administrador: Ana Prueba
"""

APP = """ORDEN DE TRABAJO OT-1964-G021EC-10358019-UIO
Documento generado automáticamente el 2026-10-01 09:49
DATOS GENERALES
ID-ORDEN-INDUSTEC: OT-1964-G021EC-10358019-UIO
ID-ORDEN-GRUPOKFC: 10358019
Tipo de Trabajo: Correctivo
Fecha de Atención: 2026-10-01
Cliente: GUS
Local: G021EC · COLONIAL QUITO
Técnico Asignado: Técnico Uno
Administrador del local: Ivan Prueba
Correo del Local: local@ejemplo.test
Correo de Jefe de Operaciones Local: sin configurar
DETALLE DEL EQUIPO
Equipo: 000642_SY_MAQYEQ_HOLDING GABINETE
Equipo SAP: 30046955
Marca: HENNY PENNY · Modelo: HC-903 · Serie: DA1202048
Código Activo Fijo: sin dato en el maestro
Ubicación técnica: RINT-E020-2001-GUS-EG021-CALIE
Área: Cocina caliente
Estado del Equipo: Operativo
Observación: Se limpió el ventilador
DETALLE DE LA INTERVENCIÓN
Hora Inicio: 2026-10-01 08:00
Hora Fin: 2026-10-01 09:44
Tiempo de Atención: 1h 44m
Actividades:
Personal informa que el equipo no llega a temperatura
REPUESTOS
No se usaron repuestos.
TRABAJO NO CONCLUIDO
Equipo: Holding gabinet, HENNY PENNY HC-903
Falla encontrada: Ventilador / motor del ventilador
Diagnóstico:
Ventilador defectuoso
Equipo deshabilitado: NO
Repuestos que hacen falta:
- 1 × Henny Penny 25753 Montaje de motor para ventilador, 120 V
TRABAJO CON OTRO PROVEEDOR
Intervención realizada por Proveedor X. INDUSTEC registra y acompaña
OBSERVACIONES
Sin observaciones.
ESTADO DE LA OT
Abierta
EVIDENCIA FOTOGRÁFICA POR EQUIPO
Equipo 1 · 000642_SY_MAQYEQ_CERRADA DE PRUEBA
Antes:
Antes 1
SATISFACCIÓN DEL CLIENTE
Su requerimiento fue atendido a tiempo: SI
Calificación: 10/10
FIRMA DEL ADMINISTRADOR
Administrador: Ivan Prueba
"""

print("=== 1. El PDF del formulario viejo se lee igual que antes ===")
r = X.extraer_texto(VIEJO)
afirmar("aviso", r["aviso"], "10357626")
afirmar("horas y tiempo", (r["hora_inicio"], r["hora_fin"], r["tiempo_atencion_min"]), ("15:30", "16:30", 60))
afirmar("equipo con sus tres datos en lineas separadas",
        {k: r["equipos"][0].get(k) for k in ("equipo", "marca", "modelo", "serie", "codigo_activo_fijo", "estado_equipo")},
        {"equipo": "Freidora de presas", "marca": "SUPERFRYER", "modelo": "OFG-321", "serie": "S/N",
         "codigo_activo_fijo": "S/N", "estado_equipo": "Operativo"})
afirmar("repuestos, observaciones y estado", (r["repuestos"], r["observaciones"], r["estado_ot"]), ("Ninguna", "Ninguna", "CERRADA"))
afirmar("sin sección «no concluido»", r["no_concluido"], None)

print("\n=== 2. El PDF de la app: cada campo en su sitio ===")
r = X.extraer_texto(APP)
afirmar("el aviso ya no arrastra «Tipo de Trabajo»", (r["aviso"], r["tipo_trabajo"]), ("10358019", "Correctivo"))
e = r["equipos"][0]
afirmar("marca, modelo y serie, cada uno suyo (antes la marca traía los tres)",
        (e.get("marca"), e.get("modelo"), e.get("serie")), ("HENNY PENNY", "HC-903", "DA1202048"))
afirmar("el equipo no arrastra «Equipo SAP»", (e["equipo"], e.get("equipo_sap")), ("000642_SY_MAQYEQ_HOLDING GABINETE", "30046955"))
afirmar("el código de activo no arrastra la ubicación ni el área",
        (e["codigo_activo_fijo"], e.get("ubicacion_tecnica"), e.get("area")),
        ("sin dato en el maestro", "RINT-E020-2001-GUS-EG021-CALIE", "Cocina caliente"))
afirmar("el estado del equipo no arrastra la observación (antes: «Operativo\\nObservación: ...»)",
        (e["estado_equipo"], e.get("observacion_equipo")), ("Operativo", "Se limpió el ventilador"))
afirmar("tiempo de atención con la hora que trae fecha (antes NULL)",
        (r["hora_inicio"], r["hora_fin"], r["tiempo_atencion_min"]), ("2026-10-01 08:00", "2026-10-01 09:44", 104))

print("\n=== 3. Las secciones nuevas no ensucian a las de siempre ===")
afirmar("`repuestos` son solo los USADOS: no se traga el pedido ni al proveedor", r["repuestos"], "No se usaron repuestos.")
afirmar("`observaciones` limpia", r["observaciones"], "Sin observaciones.")
afirmar("el estado es el de la OT, no el nombre de un equipo de las fotos (tiene «CERRADA»)", r["estado_ot"], "ABIERTA")
nc = r["no_concluido"]
afirmar("el pedido de repuesto, en su campo: equipo, falla, deshabilitado",
        (nc["equipo"], nc["falla"], nc["deshabilitado"]), ("Holding gabinet, HENNY PENNY HC-903", "Ventilador / motor del ventilador", "NO"))
afirmar("el diagnóstico", nc["diagnostico"], "Ventilador defectuoso")
afirmar("y el repuesto que hace falta", nc["repuestos_que_hacen_falta"], "- 1 × Henny Penny 25753 Montaje de motor para ventilador, 120 V")
afirmar("la satisfacción y la firma siguen donde estaban",
        (r["atiempo"], r["satisfaccion"], r["admin_firma"], r["firma_presente"]), ("Si", 10, "Ivan Prueba", 1))

print("\n=== 4. Un PDF de la app SIN la sección nueva (OT concluida, como la OT-1952) ===")
concluida = (APP.replace("TRABAJO NO CONCLUIDO\nEquipo: Holding gabinet, HENNY PENNY HC-903\nFalla encontrada: Ventilador / motor del ventilador\n"
                         "Diagnóstico:\nVentilador defectuoso\nEquipo deshabilitado: NO\nRepuestos que hacen falta:\n"
                         "- 1 × Henny Penny 25753 Montaje de motor para ventilador, 120 V\n", "")
                  .replace("Abierta", "Cerrada"))
r = X.extraer_texto(concluida)
afirmar("sin la sección: no_concluido es None y repuestos sigue limpio", (r["no_concluido"], r["repuestos"]), (None, "No se usaron repuestos."))
afirmar("estado Cerrada", r["estado_ot"], "CERRADA")

print("\n=== 5. El motivo de una OT sin aviso de SAP ===")
sin_aviso = APP.replace("ID-ORDEN-GRUPOKFC: 10358019\n", "ID-ORDEN-GRUPOKFC: Sin aviso de SAP\nMotivo de no tener aviso SAP: El gerente pidió la visita por teléfono\n")
r = X.extraer_texto(sin_aviso)
afirmar("aviso y motivo, cada uno suyo", (r["aviso"], r["motivo_sin_aviso"]), ("Sin aviso de SAP", "El gerente pidió la visita por teléfono"))

print("\n=== 6. calcular_tiempo_atencion_min ===")
for (a, b), esperado, que in [
        (("15:30", "16:30"), 60, "formato del formulario viejo"),
        (("2026-09-30 08:00", "2026-09-30 09:00"), 60, "formato de la app"),
        (("2026-09-30T08:00", "2026-09-30T09:44"), 104, "con T"),
        (("2026-09-30 23:30", "2026-10-01 00:30"), 60, "cruza la medianoche"),
        (("16:30", "15:30"), None, "fin antes que inicio"),
        (("2026-09-30 09:00", "2026-09-30 08:00"), None, "fin antes que inicio, con fecha"),
        (("", "09:00"), None, "sin inicio"),
        (("no es hora", "09:00"), None, "basura"),
        ((None, None), None, "None"),
        (("2026-02-30 08:00", "2026-02-30 09:00"), 60, "día inexistente: mismo día, no se rompe"),
]:
    afirmar(f"{que}: {a!r} → {b!r}", X.calcular_tiempo_atencion_min(a, b), esperado)

print(f"\n{total} comprobaciones · {fallos} fallos")
sys.exit(1 if fallos else 0)
