"""
T1.7 - Ingesta a la base de datos.

Recorre el arbol canonico D:\\RESPALDOS\\ORDENES DE TRABAJO (ya saneado en
T1.6), extrae el contenido de cada PDF y hace upsert en ots/ot_equipos por
id_industec (clave natural = nombre de archivo sin extension, ya normalizado
y verificado). Los campos identificadores (correlativo, local, aviso, zona,
dia, modulo) se toman del NOMBRE CANONICO del archivo -- ya resuelto contra
el maestro en T1.6 -- no del contenido del PDF, que puede traer el codigo
sin sufijo EC ("K111" en vez de "K111EC"). El resto de los campos (tecnico,
equipo, actividades...) SI vienen del contenido extraido.

Un PDF que falla no detiene el lote: se registra en observaciones_calidad
con el motivo y se continua.
"""
import re
import sys
import hashlib
from pathlib import Path
import mysql.connector
sys.path.insert(0, str(Path(__file__).parent))
from t1_7_extractor_pdf import extraer_pdf

ENV_PATH = Path(r"D:\INDUSTECH IA\desarrollo\agentes\config\.env")
env = {}
for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip()

RAIZ = Path(r"D:\RESPALDOS\ORDENES DE TRABAJO")

# El segmento del AVISO es opcional: los preventivos no nacen de un aviso SAP, y
# algunos correctivos antiguos se emitieron sin el. Exigirlo mandaba a error
# documentos perfectamente validos (T1.6b).
RE_NOMBRE = re.compile(
    r"^OT-(\d+)-([A-Z0-9]+)(?:-(\d+))?(?:-D(\d+))?-([A-Z]+)\.pdf$")


def _trunc(v, n):
    """Salvaguarda de longitud: nunca debe fallar un INSERT por esto. Si un
    campo llega mas largo de lo esperado (posible fuga de seccion en un PDF
    de formato atipico), se corta y se marca para revision en vez de abortar
    todo el lote."""
    if v is None:
        return None
    v = str(v)
    return v if len(v) <= n else v[: n - 20] + "...[TRUNCADO]"


def sha256_de(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def main():
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"],
    )
    # autocommit: cada OT es su propia transaccion independiente. Sin esto,
    # un rollback() tras el fallo de UNA fila revertiria tambien las demas
    # filas ya insertadas desde el ultimo commit periodico.
    cnx.autocommit = True
    cur = cnx.cursor()

    pdfs = list(RAIZ.rglob("*.pdf"))
    print(f"PDFs en arbol canonico: {len(pdfs)} (esperado 6597)")

    ok, con_error, sin_texto = 0, 0, 0
    errores_detalle = []
    por_zona = {}

    for idx, p in enumerate(pdfs, 1):
        m = RE_NOMBRE.match(p.name)
        if not m:
            errores_detalle.append((str(p), "NOMBRE_NO_PARSEABLE_INESPERADO"))
            con_error += 1
            continue
        correlativo, local_codigo, aviso, dia, zona = m.groups()
        # El modulo lo manda la CARPETA, que es donde el saneamiento ya decidio:
        # el arbol es {anio}/{CORRECTIVO|PREVENTIVO}/{zona}/{cadena}. Deducirlo del
        # sufijo -D{n} fallaba en los preventivos que no llevan numero de dia.
        # el aviso es opcional en el nombre canonico: se guarda NULL cuando no lo hay
        aviso_num = int(aviso) if aviso else None
        partes = p.relative_to(RAIZ).parts
        modulo = partes[1] if len(partes) > 1 and partes[1] in ("CORRECTIVO", "PREVENTIVO", "OTROS")             else ("PREVENTIVO" if dia else "CORRECTIVO")
        id_industec = p.stem
        cadena_carpeta = p.parent.name  # .../{zona}/{cadena}/archivo.pdf

        extraido = extraer_pdf(p)
        hash_pdf = sha256_de(p)

        if extraido.get("error"):
            errores_detalle.append((str(p), extraido["error"]))
            con_error += 1
            # Aun con error de extraccion, se registra la OT con lo que se
            # sabe por el nombre (id_industec, zona, local, aviso) para no
            # perder el registro de que la orden existe.
            cur.execute(
                """INSERT INTO ots (id_industec, modulo, zona, correlativo, dia_intervencion,
                        aviso, local_codigo, ruta_pdf, hash_pdf, fuente, en_cuarentena, motivo_cuarentena)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,'DRIVE_HISTORICO',1,%s)
                   ON DUPLICATE KEY UPDATE ruta_pdf=VALUES(ruta_pdf), hash_pdf=VALUES(hash_pdf),
                        en_cuarentena=1, motivo_cuarentena=VALUES(motivo_cuarentena)""",
                (id_industec, modulo, zona, int(correlativo), int(dia) if dia else None,
                 aviso_num, local_codigo, str(p), hash_pdf, extraido["error"][:200]),
            )
            continue

        atiempo_val = extraido.get("atiempo")
        if atiempo_val not in ("Si", "No"):
            atiempo_val = None

        try:
            cur.execute(
                """INSERT INTO ots (id_industec, modulo, zona, correlativo, dia_intervencion,
                        aviso, local_codigo, cliente, tecnico_nombre, admin_nombre,
                        correo_local, correo_jefe_op, fecha_atencion, hora_inicio, hora_fin,
                        tiempo_atencion_min, estado_ot, actividades, repuestos, observaciones,
                        satisfaccion, atiempo, firma_presente, fotos_cantidad, ruta_pdf,
                        hash_pdf, fuente, en_cuarentena, motivo_cuarentena)
                   VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'DRIVE_HISTORICO',0,NULL)
                   ON DUPLICATE KEY UPDATE
                        cliente=VALUES(cliente), tecnico_nombre=VALUES(tecnico_nombre),
                        admin_nombre=VALUES(admin_nombre), correo_local=VALUES(correo_local),
                        correo_jefe_op=VALUES(correo_jefe_op), fecha_atencion=VALUES(fecha_atencion),
                        hora_inicio=VALUES(hora_inicio), hora_fin=VALUES(hora_fin),
                        tiempo_atencion_min=VALUES(tiempo_atencion_min), estado_ot=VALUES(estado_ot),
                        actividades=VALUES(actividades), repuestos=VALUES(repuestos),
                        observaciones=VALUES(observaciones), satisfaccion=VALUES(satisfaccion),
                        atiempo=VALUES(atiempo), firma_presente=VALUES(firma_presente),
                        fotos_cantidad=VALUES(fotos_cantidad), ruta_pdf=VALUES(ruta_pdf),
                        hash_pdf=VALUES(hash_pdf), en_cuarentena=0, motivo_cuarentena=NULL""",
                (id_industec, modulo, zona, int(correlativo), int(dia) if dia else None,
                 aviso_num, local_codigo, _trunc(extraido.get("cliente"), 80),
                 _trunc(extraido.get("tecnico_nombre"), 120), _trunc(extraido.get("admin_nombre"), 120),
                 _trunc(extraido.get("correo_local"), 160), _trunc(extraido.get("correo_jefe_op"), 160),
                 extraido.get("fecha_atencion"),
                 extraido.get("hora_inicio"), extraido.get("hora_fin"),
                 extraido.get("tiempo_atencion_min"), extraido.get("estado_ot"),
                 extraido.get("actividades"), extraido.get("repuestos"), extraido.get("observaciones"),
                 extraido.get("satisfaccion"), atiempo_val, extraido.get("firma_presente", 0),
                 extraido.get("fotos_cantidad", 0), str(p), hash_pdf),
            )

            # Reemplazo de equipos de esta OT (idempotente: se borra y
            # reinserta, mas simple y seguro que intentar upsert por orden
            # cuando el numero de equipos pudiera cambiar entre corridas).
            cur.execute("DELETE FROM ot_equipos WHERE id_industec=%s", (id_industec,))
            for eq in extraido.get("equipos", []):
                cur.execute(
                    """INSERT INTO ot_equipos (id_industec, orden, equipo, marca, modelo, serie,
                            codigo_activo_fijo, estado_equipo, descripcion, observaciones)
                       VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                    (id_industec, eq.get("orden", 0), _trunc(eq.get("equipo"), 120), _trunc(eq.get("marca"), 80),
                     _trunc(eq.get("modelo"), 80), _trunc(eq.get("serie"), 80), _trunc(eq.get("codigo_activo_fijo"), 40),
                     eq.get("estado_equipo") or None, eq.get("actividades") or None, eq.get("observaciones") or None),
                )
            ok += 1
            por_zona[zona] = por_zona.get(zona, 0) + 1
        except mysql.connector.errors.DataError as e:
            # Reintento acotado: fecha invalida en el PDF original (typo real
            # observado: "20026-07-30", 5 digitos de anio). Se preserva el
            # resto de la orden con fecha_atencion=NULL en vez de perder toda
            # la fila por un solo campo corrupto.
            if "fecha_atencion" in str(e).lower() or "date value" in str(e).lower():
                try:
                    cur.execute(
                        """UPDATE ots SET fecha_atencion=NULL WHERE id_industec=%s""",
                        (id_industec,),
                    )
                    # el INSERT fallo antes de crear la fila; se reintenta el
                    # INSERT completo pero con fecha_atencion=NULL desde el inicio
                    extraido["fecha_atencion"] = None
                    cur.execute(
                        """INSERT INTO ots (id_industec, modulo, zona, correlativo, dia_intervencion,
                                aviso, local_codigo, cliente, tecnico_nombre, admin_nombre,
                                correo_local, correo_jefe_op, fecha_atencion, hora_inicio, hora_fin,
                                tiempo_atencion_min, estado_ot, actividades, repuestos, observaciones,
                                satisfaccion, atiempo, firma_presente, fotos_cantidad, ruta_pdf,
                                hash_pdf, fuente, en_cuarentena, motivo_cuarentena)
                           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NULL,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'DRIVE_HISTORICO',1,%s)
                           ON DUPLICATE KEY UPDATE fecha_atencion=NULL, en_cuarentena=1, motivo_cuarentena=VALUES(motivo_cuarentena)""",
                        (id_industec, modulo, zona, int(correlativo), int(dia) if dia else None,
                         aviso_num, local_codigo, _trunc(extraido.get("cliente"), 80),
                         _trunc(extraido.get("tecnico_nombre"), 120), _trunc(extraido.get("admin_nombre"), 120),
                         _trunc(extraido.get("correo_local"), 160), _trunc(extraido.get("correo_jefe_op"), 160),
                         extraido.get("hora_inicio"), extraido.get("hora_fin"),
                         extraido.get("tiempo_atencion_min"), extraido.get("estado_ot"),
                         extraido.get("actividades"), extraido.get("repuestos"), extraido.get("observaciones"),
                         extraido.get("satisfaccion"), atiempo_val, extraido.get("firma_presente", 0),
                         extraido.get("fotos_cantidad", 0), str(p), hash_pdf,
                         f"FECHA_INVALIDA_EN_PDF_ORIGINAL:{e}"[:200]),
                    )
                    cur.execute("DELETE FROM ot_equipos WHERE id_industec=%s", (id_industec,))
                    for eq in extraido.get("equipos", []):
                        cur.execute(
                            """INSERT INTO ot_equipos (id_industec, orden, equipo, marca, modelo, serie,
                                    codigo_activo_fijo, estado_equipo, descripcion, observaciones)
                               VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                            (id_industec, eq.get("orden", 0), _trunc(eq.get("equipo"), 120), _trunc(eq.get("marca"), 80),
                             _trunc(eq.get("modelo"), 80), _trunc(eq.get("serie"), 80), _trunc(eq.get("codigo_activo_fijo"), 40),
                             eq.get("estado_equipo") or None, eq.get("actividades") or None, eq.get("observaciones") or None),
                        )
                    ok += 1
                    por_zona[zona] = por_zona.get(zona, 0) + 1
                    errores_detalle.append((str(p), f"RECUPERADO_CON_FECHA_NULL:{e}"))
                    continue
                except mysql.connector.Error as e2:
                    errores_detalle.append((str(p), f"ERROR_BD_TRAS_REINTENTO:{e2}"))
                    con_error += 1
                    continue
            errores_detalle.append((str(p), f"ERROR_BD:{e}"))
            con_error += 1
            continue
        except mysql.connector.Error as e:
            errores_detalle.append((str(p), f"ERROR_BD:{e}"))
            con_error += 1
            continue

        if idx % 500 == 0:
            cnx.commit()
            print(f"  ...{idx}/{len(pdfs)} procesados ({ok} ok, {con_error} con error)")

    cnx.commit()

    # --- Fase 2: ingesta parcial de los archivos en _CUARENTENA. Su nombre
    # no se pudo normalizar por completo (local fuera de maestro, sufijo H,
    # aviso mal formado...), pero el CONTENIDO del PDF sigue siendo una orden
    # de trabajo real. Se ingesta con lo que SI se puede determinar; nunca se
    # inventa una zona que el nombre no sugiere (el esquema exige zona, asi
    # que los que no la sugieren quedan fuera de "ots" y solo documentados en
    # el manifiesto de T1.6, que ya los registra).
    RE_ZONA_LAXA = re.compile(r"-(UIO|LARB|CNLJ)(?:\s*\(\d+\))?\.pdf$", re.IGNORECASE)
    RE_AVISO_LAXO = re.compile(r"-(\d{7,9})-")
    cuarentena_pdfs = list(Path(r"D:\RESPALDOS\_CUARENTENA").glob("*.pdf"))
    print(f"\nPDFs en _CUARENTENA a ingestar parcialmente: {len(cuarentena_pdfs)}")

    cq_ok, cq_sin_zona, cq_error = 0, 0, 0
    # Correlativo SINTETICO por combinacion (zona, modulo, dia): el UNIQUE
    # KEY real de la tabla es (zona, modulo, correlativo, dia_intervencion).
    # Usar 0 fijo para todos colisionaria entre si, y ON DUPLICATE KEY UPDATE
    # sobreescribiria silenciosamente ordenes distintas con la misma clave
    # (bug real detectado antes de ejecutar). Se reserva el rango 90000+,
    # inconfundible con un correlativo real del sistema original.
    contador_sintetico = {}
    for p in cuarentena_pdfs:
        m_zona = RE_ZONA_LAXA.search(p.name)
        if not m_zona:
            cq_sin_zona += 1
            continue
        zona = m_zona.group(1).upper()
        m_aviso = RE_AVISO_LAXO.search(p.name)
        aviso = int(m_aviso.group(1)) if m_aviso else None
        id_industec = p.stem

        extraido = extraer_pdf(p)
        hash_pdf = sha256_de(p)
        if extraido.get("error"):
            cq_error += 1
            continue
        modulo = extraido.get("modulo", "CORRECTIVO")
        dia_raw = extraido.get("dia_intervencion")
        # Hallazgo real: algunos nombres traen "Dia Viernes"/"Dia Lunes" en
        # vez de un numero de secuencia 1-5. Mapear dia-de-semana a numero de
        # visita seria una suposicion semantica no respaldada (no se sabe si
        # "Viernes" significa la 5ta visita de esa semana); se deja NULL en
        # vez de forzar un valor o descartar toda la orden por este campo.
        dia_val = int(dia_raw) if dia_raw and str(dia_raw).strip().isdigit() else None
        clave_grupo = (zona, modulo, dia_val)
        contador_sintetico[clave_grupo] = contador_sintetico.get(clave_grupo, 90000) + 1
        correlativo_sintetico = contador_sintetico[clave_grupo]
        atiempo_val = extraido.get("atiempo")
        if atiempo_val not in ("Si", "No"):
            atiempo_val = None

        # Columnas -> valor en un unico dict: genera columnas/placeholders/
        # valores del INSERT en el mismo orden automaticamente, para no
        # depender de contar "%s" a mano (asi se origino el bug anterior:
        # "Not all parameters were used", un placeholder de menos entre 29
        # columnas escritas manualmente).
        campos = {
            "id_industec": id_industec, "modulo": modulo, "zona": zona,
            "correlativo": correlativo_sintetico, "dia_intervencion": dia_val,
            "aviso": aviso, "local_codigo": None,
            "cliente": _trunc(extraido.get("cliente"), 80),
            "tecnico_nombre": _trunc(extraido.get("tecnico_nombre"), 120),
            "admin_nombre": _trunc(extraido.get("admin_nombre"), 120),
            "correo_local": _trunc(extraido.get("correo_local"), 160),
            "correo_jefe_op": _trunc(extraido.get("correo_jefe_op"), 160),
            "fecha_atencion": extraido.get("fecha_atencion"),
            "hora_inicio": extraido.get("hora_inicio"), "hora_fin": extraido.get("hora_fin"),
            "tiempo_atencion_min": extraido.get("tiempo_atencion_min"),
            "estado_ot": extraido.get("estado_ot"), "actividades": extraido.get("actividades"),
            "repuestos": extraido.get("repuestos"), "observaciones": extraido.get("observaciones"),
            "satisfaccion": extraido.get("satisfaccion"), "atiempo": atiempo_val,
            "firma_presente": extraido.get("firma_presente", 0),
            "fotos_cantidad": extraido.get("fotos_cantidad", 0),
            "ruta_pdf": str(p), "hash_pdf": hash_pdf, "fuente": "DRIVE_HISTORICO",
            "en_cuarentena": 1, "motivo_cuarentena": "EN_CUARENTENA_DESDE_T1.6_LOCAL_O_AVISO_SIN_RESOLVER",
        }
        try:
            columnas_sql = ", ".join(campos.keys())
            placeholders_sql = ", ".join(["%s"] * len(campos))
            cur.execute(
                f"""INSERT INTO ots ({columnas_sql}) VALUES ({placeholders_sql})
                    ON DUPLICATE KEY UPDATE ruta_pdf=VALUES(ruta_pdf)""",
                tuple(campos.values()),
            )
            cq_ok += 1
        except mysql.connector.Error as e:
            errores_detalle.append((str(p), f"ERROR_BD_CUARENTENA:{e}"))
            cq_error += 1

    print(f"Cuarentena ingestada con zona reconocible: {cq_ok}  "
          f"sin zona reconocible (no ingestadas, solo en manifiesto T1.6): {cq_sin_zona}  "
          f"con error: {cq_error}")

    print(f"\n=== RESUMEN T1.7 ===")
    print(f"OK: {ok}  Con error de extraccion: {con_error}")
    print("Por zona:", por_zona)
    for e in errores_detalle[:20]:
        print("  ERROR:", e)
    if len(errores_detalle) > 20:
        print(f"  ... y {len(errores_detalle) - 20} mas")

    cur.execute("SELECT COUNT(*) FROM ots")
    total_bd = cur.fetchone()[0]
    cur.execute("SELECT zona, COUNT(*) FROM ots GROUP BY zona")
    zonas_bd = cur.fetchall()
    cur.execute("SELECT COUNT(*) FROM ots WHERE en_cuarentena=1")
    cuarentena_bd = cur.fetchone()[0]
    print(f"\nTotal en tabla ots: {total_bd}  (en_cuarentena: {cuarentena_bd})")
    print("Por zona en BD:", zonas_bd)

    cur.close()
    cnx.close()


if __name__ == "__main__":
    main()
