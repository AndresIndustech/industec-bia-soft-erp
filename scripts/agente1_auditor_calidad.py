"""
Agente 1 (Gestor de OTs) - Auditor de calidad.

Aplica las 8 reglas deterministas del plan (T1.10) sobre la tabla `ots` ya
poblada, mapeadas a las dimensiones DAMA de la seccion 3.3:
  1. Local fuera del maestro          (validez)
  2. Zona cruzada                     (consistencia)
  3. Aviso inexistente o mal formado  (exactitud)
  4. Orden sin fotos                  (completitud)
  5. Hora de fin <= hora de inicio    (validez)
  6. Correlativo duplicado            (unicidad)
  7. Campos clave vacios              (completitud)
  8. Orden abierta > 3 dias           (oportunidad)

Escribe en la tabla `observaciones_calidad` (upsert por regla+id_industec)
y en el espejo SALIDAS IA\\CALIDAD\\OBSERVACIONES_OTS.xlsx con la columna
VEREDICTO ADMIN, que en corridas futuras se lee para aprender que NO es
novedad (I-4: nunca se sobreescribe lo que la administracion ya marco).
"""
import re
from pathlib import Path
from datetime import date
import mysql.connector
import openpyxl
from openpyxl.styles import Font, PatternFill

ENV_PATH = Path(r"D:\INDUSTECH IA\agentes\config\.env")
env = {}
for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip()

OUT_XLSX = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD\OBSERVACIONES_OTS.xlsx")

SEVERIDAD = {
    "LOCAL_FUERA_MAESTRO": "ALTA", "ZONA_CRUZADA": "MEDIA",
    "AVISO_INEXISTENTE_O_MAL_FORMADO": "ALTA", "SIN_FOTOS": "MEDIA",
    "HORA_FIN_ANTERIOR_A_INICIO": "MEDIA", "CORRELATIVO_DUPLICADO": "CRITICA",
    "CAMPO_CLAVE_VACIO": "MEDIA", "ABIERTA_MAS_DE_3_DIAS": "ALTA",
    "CORRECTIVO_SIN_AVISO_SAP": "ALTA",
}
DIMENSION = {
    "LOCAL_FUERA_MAESTRO": "VALIDEZ", "ZONA_CRUZADA": "CONSISTENCIA",
    "AVISO_INEXISTENTE_O_MAL_FORMADO": "EXACTITUD", "SIN_FOTOS": "COMPLETITUD",
    "HORA_FIN_ANTERIOR_A_INICIO": "VALIDEZ", "CORRELATIVO_DUPLICADO": "UNICIDAD",
    "CAMPO_CLAVE_VACIO": "COMPLETITUD", "ABIERTA_MAS_DE_3_DIAS": "OPORTUNIDAD",
    "CORRECTIVO_SIN_AVISO_SAP": "COMPLETITUD",
}


def conectar():
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"],
    )
    cnx.autocommit = True
    return cnx


def cargar_veredictos_previos(cnx):
    """I-4: si la administracion ya marco un veredicto (DESCARTADA/CORREGIDA)
    para (id_industec, regla), esa fila NO se vuelve a reportar como nueva
    -- se conserva su estado y veredicto tal como estaban."""
    cur = cnx.cursor(dictionary=True)
    # RESUELTA_AUTOMATICA no cuenta como veredicto: la puso el propio auditor al ver
    # que el hallazgo dejaba de reproducirse. Si el defecto vuelve, debe reportarse
    # otra vez. Solo DESCARTADA y CORREGIDA son decisiones de la administracion.
    cur.execute("""SELECT id_industec, regla, estado, veredicto_admin
                   FROM observaciones_calidad
                   WHERE estado IN ('DESCARTADA','CORREGIDA')""")
    previos = {(r["id_industec"], r["regla"]): r for r in cur.fetchall()}
    cur.close()
    return previos


def main():
    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    previos = cargar_veredictos_previos(cnx)
    print(f"Veredictos previos de la administracion a respetar: {len(previos)}")

    hallazgos = []

    # 1. Local fuera del maestro (validez): la fila quedo con local_codigo NULL
    cur.execute("""SELECT id_industec, zona, tecnico_nombre, ruta_pdf
                   FROM ots WHERE local_codigo IS NULL AND en_cuarentena = 0""")
    for r in cur.fetchall():
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "LOCAL_FUERA_MAESTRO",
                           f"El nombre del archivo no permitio resolver un codigo de local valido contra el maestro. Archivo: {r['ruta_pdf']}"))

    # 2. Zona cruzada (consistencia): tomado del manifiesto de T1.6, que
    # registra la reasignacion real (la tabla ots ya tiene la zona CORREGIDA)
    cur.execute("""SELECT ruta_original, nombre_original, regla_aplicada
                   FROM manifiesto_saneamiento WHERE regla_aplicada LIKE '%ZONA_CRUZADA%'""")
    for r in cur.fetchall():
        id_ind = Path(r["nombre_original"]).stem
        hallazgos.append((id_ind, None, None, "ZONA_CRUZADA",
                           f"El tecnico registro la orden en una zona distinta a la del maestro del local. {r['regla_aplicada']}"))

    # 3. Aviso inexistente o mal formado (exactitud): 8 digitos pero no esta
    # en el catalogo conocido de SAP. IMPORTANTE: el catalogo (KPI'S
    # INDUSTEC.xlsx) solo cubre 2026-01-01 a 2026-08-31 -- verificado antes
    # de activar esta regla. Aplicarla fuera de ese rango generaria ~1662
    # falsos positivos (avisos de 2025 simplemente no cubiertos por el
    # export, no "mal formados"). Se acota al rango real de cobertura para
    # no acusar de un defecto que no esta respaldado por evidencia (I-7).
    cur.execute("SELECT MIN(fecha_notificacion) AS ini, MAX(fecha_notificacion) AS fin FROM avisos_sap")
    cobertura = cur.fetchone()
    print(f"Cobertura real del catalogo avisos_sap: {cobertura['ini']} a {cobertura['fin']}")
    cur.execute("""SELECT o.id_industec, o.zona, o.tecnico_nombre, o.aviso
                   FROM ots o LEFT JOIN avisos_sap a ON a.aviso = o.aviso
                   WHERE o.aviso IS NOT NULL AND a.aviso IS NULL
                   AND o.en_cuarentena = 0
                   AND o.fecha_atencion BETWEEN %s AND %s""",
                (cobertura["ini"], cobertura["fin"]))
    for r in cur.fetchall():
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "AVISO_INEXISTENTE_O_MAL_FORMADO",
                           f"El aviso SAP {r['aviso']} tiene formato de 8 digitos pero no aparece en el catalogo de avisos conocidos (KPI'S INDUSTEC.xlsx), pese a que la fecha de la orden SI cae dentro del rango cubierto por ese catalogo ({cobertura['ini']} a {cobertura['fin']})."))

    # 3b. Correctivo sin aviso SAP: NO es un error del tecnico, es un caso de
    # negocio que la administracion tiene que regularizar.
    #
    # Ocurre cuando un local tiene una emergencia mientras el tecnico ya esta en
    # sitio por otro caso: la atiende y emite la orden sin numero de aviso, porque
    # ese aviso todavia no existe. Despues la administracion regulariza -- pide a
    # KFC que cree el caso justificandolo con el informe ya emitido, o lo crea ella
    # misma en SAP. Esa decision es SIEMPRE suya: el sistema no puede inventar un
    # aviso ni dar la orden por cerrada sin el.
    #
    # Los PREVENTIVOS quedan fuera de esta regla a proposito: no nacen de un aviso
    # y exigirselo fue el error de diseño que mando 185 documentos a cuarentena.
    cur.execute("""SELECT id_industec, zona, tecnico_nombre, local_codigo, fecha_atencion
                   FROM ots
                   WHERE en_cuarentena = 0 AND modulo = 'CORRECTIVO'
                     AND (aviso IS NULL OR aviso = 0)""")
    for r in cur.fetchall():
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "CORRECTIVO_SIN_AVISO_SAP",
                           f"Correctivo emitido sin numero de aviso SAP en {r['local_codigo']} "
                           f"el {r['fecha_atencion']}. Tipico de una emergencia atendida con el "
                           f"tecnico ya en sitio. Requiere que la administracion lo regularice: "
                           f"pedir a KFC la creacion del caso adjuntando este informe, o crearlo "
                           f"ella en SAP. Anotar el aviso resultante en la columna VEREDICTO ADMIN."))

    # 4. Orden sin fotos (completitud)
    cur.execute("""SELECT id_industec, zona, tecnico_nombre
                   FROM ots WHERE fotos_cantidad = 0 AND en_cuarentena = 0""")
    for r in cur.fetchall():
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "SIN_FOTOS", "La orden no tiene ninguna fotografia de evidencia adjunta en el PDF."))

    # 5. Hora de fin <= hora de inicio (validez): el extractor deja
    # tiempo_atencion_min=NULL exactamente en este caso, cuando SI habia
    # ambas horas capturadas (si faltara una de las dos, no es este bug).
    cur.execute("""SELECT id_industec, zona, tecnico_nombre, hora_inicio, hora_fin
                   FROM ots WHERE tiempo_atencion_min IS NULL
                   AND hora_inicio IS NOT NULL AND hora_fin IS NOT NULL""")
    for r in cur.fetchall():
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "HORA_FIN_ANTERIOR_A_INICIO",
                           f"Hora inicio {r['hora_inicio']} y hora fin {r['hora_fin']}: el fin no es posterior al inicio (bug conocido del sistema original en cruces de medianoche)."))

    # 6. Correlativo duplicado (unicidad): el UNIQUE KEY de la tabla ya lo
    # previene a nivel de fila individual, pero se deja la regla activa por
    # si en el futuro (ingesta en vivo, T2) aparece una colision real.
    cur.execute("""SELECT zona, modulo, correlativo, dia_intervencion, COUNT(*) as n,
                          GROUP_CONCAT(id_industec) as ids
                   FROM ots WHERE correlativo < 90000
                   GROUP BY zona, modulo, correlativo, dia_intervencion HAVING n > 1""")
    for r in cur.fetchall():
        hallazgos.append((r["ids"].split(",")[0], r["zona"], None, "CORRELATIVO_DUPLICADO",
                           f"Correlativo {r['correlativo']} repetido {r['n']} veces en {r['zona']}/{r['modulo']}: {r['ids']}"))

    # 7. Campos clave vacios (completitud): tecnico o actividades faltantes
    # en una orden que si se pudo resolver (no esta ya en cuarentena por otra razon)
    cur.execute("""SELECT id_industec, zona, tecnico_nombre, modulo
                   FROM ots WHERE en_cuarentena = 0
                   AND (tecnico_nombre IS NULL OR tecnico_nombre = ''
                        OR (modulo='CORRECTIVO' AND (actividades IS NULL OR actividades='')))""")
    for r in cur.fetchall():
        campo = "tecnico_nombre" if not r["tecnico_nombre"] else "actividades"
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "CAMPO_CLAVE_VACIO", f"El campo '{campo}' esta vacio en una orden {r['modulo'].lower()}."))

    # 8. Orden abierta > 3 dias (oportunidad): el mismo umbral que usa el
    # semaforo del plan de seguimiento de zona (plan T1.11)
    cur.execute("""SELECT id_industec, zona, tecnico_nombre, fecha_atencion,
                          DATEDIFF(CURDATE(), fecha_atencion) as dias
                   FROM ots WHERE estado_ot = 'ABIERTA' AND fecha_atencion IS NOT NULL
                   AND DATEDIFF(CURDATE(), fecha_atencion) >= 3""")
    for r in cur.fetchall():
        hallazgos.append((r["id_industec"], r["zona"], r["tecnico_nombre"],
                           "ABIERTA_MAS_DE_3_DIAS",
                           f"Orden abierta desde {r['fecha_atencion']}, {r['dias']} dias sin cierre registrado."))

    cur.close()

    print(f"Hallazgos brutos (antes de excluir los ya resueltos por la administracion): {len(hallazgos)}")

    # Filtrar los que la administracion ya marco DESCARTADA/CORREGIDA (I-4)
    nuevos = [h for h in hallazgos if (h[0], h[3]) not in previos]
    ya_resueltos = len(hallazgos) - len(nuevos)
    print(f"Excluidos por veredicto previo de la administracion: {ya_resueltos}")
    print(f"Hallazgos nuevos/abiertos a reportar: {len(nuevos)}")

    cur = cnx.cursor()
    for id_industec, zona, tecnico, regla, evidencia in nuevos:
        cur.execute(
            """INSERT INTO observaciones_calidad
                   (id_industec, zona, tecnico_nombre, regla, dimension_dama, severidad, evidencia, estado)
               VALUES (%s,%s,%s,%s,%s,%s,%s,'ABIERTA')
               ON DUPLICATE KEY UPDATE evidencia=VALUES(evidencia)""",
            (id_industec, zona, tecnico, regla, DIMENSION[regla], SEVERIDAD[regla], evidencia),
        )
    cnx.commit()

    # --- Cerrar los hallazgos que ya dejaron de ser ciertos ---------------------
    # Sin este paso el auditor solo acumula: una observacion levantada en una corrida
    # anterior seguia ABIERTA aunque el defecto ya se hubiera corregido, y la
    # administracion terminaba revisando fantasmas. Paso real: tras T1.6b quedaron
    # 347 'LOCAL_FUERA_MAESTRO' de ordenes que ya tenian su local resuelto.
    #
    # Solo se cierran las que siguen ABIERTAS: si la administracion ya emitio un
    # veredicto, ese veredicto manda y no se toca (I-4).
    vigentes = {(h[0], h[3]) for h in hallazgos}
    cur.execute("""SELECT observacion_id, id_industec, regla FROM observaciones_calidad
                   WHERE estado = 'ABIERTA'""")
    a_cerrar = [r[0] for r in cur.fetchall() if (r[1], r[2]) not in vigentes]
    for obs_id in a_cerrar:
        cur.execute("""UPDATE observaciones_calidad
                       SET estado='RESUELTA_AUTOMATICA', veredicto_admin=CONCAT(
                           COALESCE(veredicto_admin,''),
                           'Cerrada automaticamente: el hallazgo ya no se reproduce.')
                       WHERE observacion_id=%s""", (obs_id,))
    cnx.commit()
    print(f"Observaciones cerradas por dejar de reproducirse: {len(a_cerrar)}")

    # --- Excel espejo para la administracion, con VEREDICTO ADMIN editable
    cur.execute("""SELECT observacion_id, id_industec, zona, tecnico_nombre, regla,
                          dimension_dama, severidad, evidencia, estado, veredicto_admin, creado_en
                   FROM observaciones_calidad
                   ORDER BY (estado='ABIERTA') DESC, severidad DESC, creado_en DESC""")
    filas = cur.fetchall()
    cur.close()
    cnx.close()

    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Observaciones"
    encabezados = ["ID", "OT", "ZONA", "TECNICO", "REGLA", "DIMENSION", "SEVERIDAD",
                   "EVIDENCIA", "ESTADO", "VEREDICTO ADMIN"]
    ws.append(encabezados)
    for c in ws[1]:
        c.font = Font(bold=True)
    color_sev = {"CRITICA": "FF4C4C", "ALTA": "FFB84C", "MEDIA": "FFF2CC", "BAJA": "E2EFDA"}
    for f in filas:
        ws.append([f[0], f[1], f[2], f[3], f[4], f[5], f[6], f[7], f[8], f[9] or ""])
        color = color_sev.get(f[6])
        if color:
            ws.cell(ws.max_row, 7).fill = PatternFill(start_color=color, end_color=color, fill_type="solid")
    for col in ws.columns:
        maxlen = max((len(str(c.value)) for c in col if c.value), default=10)
        ws.column_dimensions[col[0].column_letter].width = min(maxlen + 2, 60)
    OUT_XLSX.parent.mkdir(parents=True, exist_ok=True)
    wb.save(OUT_XLSX)

    print(f"\n=== RESUMEN AUDITOR DE CALIDAD ===")
    from collections import Counter
    # Solo lo ABIERTO es lo que la administracion tiene que mirar. Contar la tabla
    # entera mezclaba lo ya resuelto y daba una cifra que solo podia crecer.
    abiertas = [f for f in filas if f[8] == "ABIERTA"]
    for regla, n in Counter(f[4] for f in abiertas).most_common():
        print(f"  {regla}: {n}")
    print(f"Observaciones ABIERTAS (lo que requiere revision): {len(abiertas)}")
    cerradas = Counter(f[8] for f in filas if f[8] != "ABIERTA")
    if cerradas:
        print("Cerradas: " + ", ".join(f"{k}={v}" for k, v in cerradas.items()))
    print(f"Total historico en la tabla: {len(filas)}")
    print(f"Excel: {OUT_XLSX}")


if __name__ == "__main__":
    main()
