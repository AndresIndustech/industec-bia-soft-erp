"""
T1.6 - Saneamiento del repositorio canonico.

Fase de ANALISIS (este script no copia ni borra nada): recorre los PDFs de
GESTION DE OTS INDUSTEC en el espejo D:\\RESPALDOS\\_ORIGEN_DRIVE (ya
verificado por hash en T1.3), parsea cada nombre, resuelve local/zona/cadena/
aviso contra el maestro cargado en T1.5, aplica las reglas de Nivel 1/2/3 del
plan, y escribe el manifiesto completo a un CSV para revision antes de
ejecutar ninguna copia fisica (ese es un script aparte: t1_6_ejecutar.py).

Reglas (plan seccion 6, tarea T1.6):
  Nivel 1 - automatico, sin ambiguedad (normalizacion de mayusculas/ceros/
            sufijo EC, formato de correlativo y aviso, "Dia N" -> "DN").
  Nivel 2 - automatico con verificacion cruzada (typo de aviso SAP corregido
            solo si el candidato existe en avisos_sap y el original no;
            zona cruzada reubicada segun el maestro).
  Nivel 3 - cuarentena: no se toca, se lista para decision de la administracion.
"""
import re
import csv
import sys
from pathlib import Path
from collections import defaultdict
import mysql.connector

ENV_PATH = Path(r"D:\INDUSTECH IA\agentes\config\.env")
env = {}
for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    env[k.strip()] = v.strip()

RAIZ_OTS = Path(r"D:\RESPALDOS\_ORIGEN_DRIVE\GESTION DE OTS INDUSTEC")
OUT_MANIFIESTO = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD\MANIFIESTO_SANEAMIENTO.csv")
OUT_RESUMEN = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD\MANIFIESTO_SANEAMIENTO_RESUMEN.md")

ZONAS_VALIDAS = {"UIO", "LARB", "CNLJ"}

# Codigos ya documentados en el plan como Nivel 3 conocido (fuera del maestro,
# nunca se intenta autoresolver aunque parezcan "casi" un codigo valido).
CODIGOS_NIVEL3_CONOCIDOS = {
    "RESTAURANTEELVITA", "GUSCOTOCOLLAO", "GUSSANBARTOLO", "KFC167",
    "G003PRENSA", "G006AMERICA", "K1111",
}

# --- Patrones de nombre --------------------------------------------------
# Preventivo: OT-{correlativo}-{LOCAL}-{AVISO}-Dia {n}-{ZONA}[ (n)]
RE_PREVENTIVO = re.compile(
    r"^OT-(?P<correlativo>\d+)-(?P<local>[^-]+)-(?P<aviso>\d+)-Dia\s*(?P<dia>\d+)-(?P<zona>[A-Za-z]+)(?:\s*\(\d+\))?$",
    re.IGNORECASE,
)
# Preventivo SIN aviso (el campo de orden SAP no se registro en el nombre):
# OT-{correlativo}-{LOCAL}-Dia {n}-{ZONA}[ (n)]
RE_PREVENTIVO_SIN_AVISO = re.compile(
    r"^OT-(?P<correlativo>\d+)-(?P<local>[^-]+)-Dia\s*(?P<dia>\d+)-(?P<zona>[A-Za-z]+)(?:\s*\(\d+\))?$",
    re.IGNORECASE,
)
# Correctivo: OT-{correlativo}-{LOCAL}-{AVISO}-{ZONA}[ (n)]
RE_CORRECTIVO = re.compile(
    r"^OT-(?P<correlativo>\d+)-(?P<local>[^-]+)-(?P<aviso>\d+)-(?P<zona>[A-Za-z]+)(?:\s*\(\d+\))?$",
    re.IGNORECASE,
)
# Legacy/otros: OT-{local_largo}-{correlativo}
RE_LEGACY = re.compile(r"^OT-(?P<local>.+)-(?P<correlativo>\d+)$")

RE_CODIGO_CANDIDATO = re.compile(r"^[A-Za-z]{1,2}\d{2,4}(EC)?$")  # sufijo EC OPCIONAL (no "E" + "C?")
RE_SUFIJO_H = re.compile(r"^[A-Za-z]{1,2}\d{2,4}H\d+$", re.IGNORECASE)


def normalizar_codigo(codigo):
    """Candidato principal: 3 digitos (el mas frecuente en el maestro).
    No es la unica forma valida -- ver candidatos_relleno_ceros() para los
    casos (confirmados en el maestro real) donde el codigo usa 2 o 4 digitos,
    como BR17EC (2 digitos) frente a CN042EC (3 digitos)."""
    codigo = codigo.strip().upper()
    if not codigo.endswith("EC"):
        codigo = codigo + "EC"
    m = re.match(r"^([A-Z]{1,2})(\d+)(EC)$", codigo)
    if not m:
        return codigo
    letras, digitos, suf = m.groups()
    # str(int(...)) quita ceros SOBRANTES (K0174->174) antes de zfill, que
    # rellena los FALTANTES (17->017). Cubre ambas direcciones del defecto.
    return f"{letras}{str(int(digitos)).zfill(3)}{suf}"


def candidatos_relleno_ceros(codigo):
    """El maestro real NO usa una cantidad fija de digitos por codigo
    (BR17EC=2 digitos, CN042EC=3): en vez de asumir 3, se generan las
    longitudes plausibles (2,3,4) y se deja que la busqueda contra el
    maestro decida cual es real. Solo se acepta cuando resuelve a un
    UNICO candidato (ver resolver_local) para no introducir ambiguedad."""
    codigo = codigo.strip().upper()
    if not codigo.endswith("EC"):
        codigo = codigo + "EC"
    m = re.match(r"^([A-Z]{1,2})(\d+)(EC)$", codigo)
    if not m:
        return []
    letras, digitos, suf = m.groups()
    n = str(int(digitos))
    return list({f"{letras}{n.zfill(k)}{suf}" for k in (2, 3, 4)})


# Prefijos de marca que a veces se anteponen al codigo real del local
# (ej. "JV054" en vez de "V054" -- la V SI es parte del codigo real de Juan
# Valdez, solo la "J" de "Juan" sobra; "CJ028" en vez de "J028" de Cajun).
# Nivel 2: solo se acepta si, quitando el prefijo, el codigo resultante
# EXISTE en el maestro -- nunca se inventa un codigo.
PREFIJOS_MARCA = ["J", "C"]


def intentar_variantes_local(crudo_upper):
    """Genera candidatos CRUDOS derivados de crudo_upper (sin normalizar
    digitos todavia) para un local que no calzo ni exacto ni por
    normalizacion simple. Cada uno se resuelve despues probando las
    longitudes de relleno plausibles (candidatos_relleno_ceros)."""
    candidatos = []
    # 1) KFC099 -> K099 (prefijo de marca completo escrito en vez de la letra)
    m = re.match(r"^KFC(\d{2,4})$", crudo_upper)
    if m:
        candidatos.append((f"K{m.group(1)}", "PREFIJO_KFC_A_K"))
    # 2) Prefijo de marca antepuesto (JV054->V054 [la V es del codigo real],
    #    CJ028->J028 de Cajun)
    for pref in PREFIJOS_MARCA:
        if crudo_upper.startswith(pref) and len(crudo_upper) > len(pref):
            resto = crudo_upper[len(pref):]
            if re.match(r"^[A-Z]?\d{2,4}(EC)?$", resto):
                candidatos.append((resto, f"PREFIJO_MARCA_{pref}_REMOVIDO"))
    # 3) Codigo valido con texto de ubicacion pegado sin separador (R015mallderio->R015)
    m = re.match(r"^([A-Za-z]{1,2}\d{2,4})[A-Za-z]{2,}.*$", crudo_upper)
    if m:
        candidatos.append((m.group(1), "UBICACION_PEGADA_REMOVIDA"))
    # 4) Typo de tecleo O<->0 dentro de la parte numerica (KO66 -> K066):
    #    error muy comun y de bajo riesgo de falso positivo.
    if "O" in crudo_upper:
        candidatos.append((crudo_upper.replace("O", "0"), "TYPO_O_POR_CERO"))
    return candidatos


def distancia_edicion_1(a, b):
    """True si a y b difieren en exactamente una edicion (insercion, borrado
    o sustitucion de un caracter). Suficiente para cadenas cortas (avisos)."""
    if a == b:
        return False
    la, lb = len(a), len(b)
    if abs(la - lb) > 1:
        return False
    if la == lb:
        return sum(1 for x, y in zip(a, b) if x != y) == 1
    # una es mas larga por 1: verificar que borrar 1 caracter de la larga da la corta
    largo, corto = (a, b) if la > lb else (b, a)
    for i in range(len(largo)):
        if largo[:i] + largo[i + 1:] == corto:
            return True
    return False


def cargar_maestro(cnx):
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT local_codigo, zona, cadena FROM locales")
    locales = {r["local_codigo"]: r for r in cur.fetchall()}
    cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
    alias = {r["alias_texto"].upper(): r["local_codigo"] for r in cur.fetchall()}
    alias_normalizado = {normalizar_codigo(k): v for k, v in alias.items()}
    cur.execute("SELECT aviso FROM avisos_sap")
    avisos = {str(r["aviso"]) for r in cur.fetchall()}
    cur.close()
    return locales, alias, alias_normalizado, avisos


def resolver_local(local_crudo, locales, alias, alias_normalizado, alias_nuevos_generados):
    """Devuelve (codigo_canonico_o_None, regla, nivel)."""
    crudo_upper = local_crudo.strip().upper()

    if crudo_upper in locales:
        return crudo_upper, "EXACTO", 1

    if crudo_upper in alias:
        return alias[crudo_upper], "ALIAS_CONOCIDO", 1

    if RE_SUFIJO_H.match(crudo_upper):
        return None, "SUFIJO_H_COMPUESTO", 3

    if crudo_upper.replace("EC", "") in CODIGOS_NIVEL3_CONOCIDOS or crudo_upper in CODIGOS_NIVEL3_CONOCIDOS:
        return None, "FUERA_DE_MAESTRO_CONOCIDO", 3

    def probar_relleno(crudo_local, catalogo_locales, catalogo_alias_norm):
        """Prueba las longitudes de relleno plausibles (2/3/4 digitos) y
        devuelve el destino SOLO si exactamente un candidato resuelve --
        evita adivinar cuando mas de una longitud calzaria (ambiguo)."""
        encontrados = []
        for cand in candidatos_relleno_ceros(crudo_local):
            if cand in catalogo_locales:
                encontrados.append(cand)
            elif cand in catalogo_alias_norm:
                encontrados.append(catalogo_alias_norm[cand])
        encontrados = list(set(encontrados))
        return encontrados[0] if len(encontrados) == 1 else None

    if RE_CODIGO_CANDIDATO.match(crudo_upper):
        destino = probar_relleno(crudo_upper, locales, alias_normalizado)
        if destino:
            regla = "MAYUSCULAS" if destino == crudo_upper else "NORMALIZACION_CEROS_O_SUFIJO"
            alias_nuevos_generados.append((crudo_upper, destino, regla))
            return destino, regla, 1

    # Nivel 2: variantes derivadas (prefijo de marca, ubicacion pegada, KFC->K,
    # typo O/0). Cada candidato SOLO se acepta si, tras normalizarlo con
    # relleno UNICO, existe en el maestro o en el catalogo de alias -- nunca
    # se inventa un codigo nuevo.
    for candidato_crudo, regla_variante in intentar_variantes_local(crudo_upper):
        destino = probar_relleno(candidato_crudo, locales, alias_normalizado)
        if destino:
            alias_nuevos_generados.append((crudo_upper, destino, regla_variante))
            return destino, regla_variante, 2

    return None, "FUERA_DE_MAESTRO", 3


def resolver_aviso(aviso_crudo, avisos, correcciones_aviso):
    if aviso_crudo == "0" or not aviso_crudo:
        return None, "AVISO_CERO_O_VACIO", 3
    if len(aviso_crudo) == 8 and aviso_crudo in avisos:
        return aviso_crudo, "EXACTO", 1
    if len(aviso_crudo) == 8:
        # formato correcto pero no esta en el catalogo de avisos conocidos:
        # se acepta igual (el catalogo de avisos_sap puede no ser exhaustivo),
        # solo se marca para informacion.
        return aviso_crudo, "FORMATO_OK_NO_EN_CATALOGO", 1
    # longitud distinta de 8: buscar candidatos a distancia de edicion 1
    # que SI existan en el catalogo (Nivel 2).
    candidatos = [a for a in avisos if len(a) == 8 and distancia_edicion_1(aviso_crudo, a)]
    if len(candidatos) == 1:
        correcciones_aviso.append((aviso_crudo, candidatos[0]))
        return candidatos[0], "TYPO_CORREGIDO_POR_DISTANCIA_1", 2
    return aviso_crudo, "AVISO_MAL_FORMADO_SIN_CORRECCION_UNICA", 3


def clasificar_archivo(path, locales, alias, alias_normalizado, avisos, alias_nuevos, correcciones_aviso):
    nombre = path.stem  # sin extension
    m = RE_PREVENTIVO.match(nombre)
    tipo = None
    if m:
        tipo = "PREVENTIVO"
    else:
        m = RE_CORRECTIVO.match(nombre)
        if m:
            tipo = "CORRECTIVO"
    if not m:
        m = RE_PREVENTIVO_SIN_AVISO.match(nombre)
        if m:
            tipo = "PREVENTIVO"  # aviso quedara vacio -> Nivel 3, pero local/zona se resuelven igual
    if not m:
        m = RE_LEGACY.match(nombre)
        if m:
            tipo = "OTROS"

    if not m:
        return {
            "ruta_original": str(path), "nombre_original": path.name,
            "tipo": "NO_RECONOCIDO", "nivel": 3, "regla": "PATRON_NO_RECONOCIDO",
            "ruta_canonica": "", "nombre_canonico": "", "local_canonico": "",
            "zona_canonica": "", "cadena": "", "aviso_resuelto": "",
        }

    g = m.groupdict()
    local_crudo = g.get("local", "")
    correlativo = g.get("correlativo", "")
    aviso_crudo = re.sub(r"\D", "", g.get("aviso", "") or "")
    dia = g.get("dia")
    zona_cruda = (g.get("zona") or "").upper()

    if tipo == "OTROS":
        return {
            "ruta_original": str(path), "nombre_original": path.name,
            "tipo": "OTROS", "nivel": 1, "regla": "PATRON_LEGACY_SIN_NORMALIZAR",
            "ruta_canonica": "", "nombre_canonico": path.name,
            "local_canonico": local_crudo, "zona_canonica": "", "cadena": "",
            "aviso_resuelto": "",
        }

    local_canonico, regla_local, nivel_local = resolver_local(local_crudo, locales, alias, alias_normalizado, alias_nuevos)
    aviso_resuelto, regla_aviso, nivel_aviso = resolver_aviso(aviso_crudo, avisos, correcciones_aviso)

    nivel = max(nivel_local, nivel_aviso)
    reglas = [regla_local, regla_aviso]

    zona_canonica = zona_cruda if zona_cruda in ZONAS_VALIDAS else ""
    cadena = ""
    if local_canonico and local_canonico in locales:
        zona_maestro = locales[local_canonico]["zona"]
        cadena = locales[local_canonico]["cadena"]
        if zona_canonica and zona_maestro != zona_canonica:
            reglas.append(f"ZONA_CRUZADA_{zona_canonica}_A_{zona_maestro}")
            nivel = max(nivel, 2)
            zona_canonica = zona_maestro
        elif not zona_canonica:
            zona_canonica = zona_maestro

    if not zona_canonica:
        zona_canonica = zona_cruda or "SIN_ZONA"
        nivel = max(nivel, 3)
        reglas.append("ZONA_NO_RESOLUBLE")

    nombre_canonico = ""
    ruta_canonica = ""
    if nivel < 3 and local_canonico and aviso_resuelto:
        try:
            corr_fmt = f"{int(correlativo):04d}"
        except ValueError:
            corr_fmt = correlativo
        aviso_fmt = aviso_resuelto.zfill(8) if aviso_resuelto.isdigit() else aviso_resuelto
        if tipo == "PREVENTIVO":
            nombre_canonico = f"OT-{corr_fmt}-{local_canonico}-{aviso_fmt}-D{dia}-{zona_canonica}.pdf"
        else:
            nombre_canonico = f"OT-{corr_fmt}-{local_canonico}-{aviso_fmt}-{zona_canonica}.pdf"
        anio = "2026" if "\\2026\\" in str(path) else ("2025" if "\\2025\\" in str(path) else "SIN_ANIO")
        ruta_canonica = str(Path("D:/RESPALDOS/ORDENES DE TRABAJO") / anio / tipo / zona_canonica / (cadena or "SIN_CADENA") / nombre_canonico)

    return {
        "ruta_original": str(path), "nombre_original": path.name,
        "tipo": tipo, "nivel": nivel, "regla": "|".join(r for r in reglas if r),
        "ruta_canonica": ruta_canonica, "nombre_canonico": nombre_canonico,
        "local_canonico": local_canonico or "", "zona_canonica": zona_canonica,
        "cadena": cadena, "aviso_resuelto": aviso_resuelto or "",
        "correlativo": correlativo, "local_crudo": local_crudo, "aviso_crudo": aviso_crudo,
    }


def main():
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"],
    )
    locales, alias, alias_normalizado, avisos = cargar_maestro(cnx)
    cnx.close()
    print(f"Maestro cargado: {len(locales)} locales, {len(alias)} alias conocidos, {len(avisos)} avisos SAP")

    if not RAIZ_OTS.exists():
        print(f"ERROR: no existe {RAIZ_OTS}")
        sys.exit(1)

    pdfs = list(RAIZ_OTS.rglob("*.pdf"))
    print(f"PDFs encontrados en {RAIZ_OTS}: {len(pdfs)}")

    alias_nuevos = []
    correcciones_aviso = []
    filas = []
    duplicados_por_paren = defaultdict(list)  # nombre_base_sin_paren -> [rutas]

    for p in pdfs:
        fila = clasificar_archivo(p, locales, alias, alias_normalizado, avisos, alias_nuevos, correcciones_aviso)
        filas.append(fila)
        base_sin_paren = re.sub(r"\s*\(\d+\)$", "", p.stem)
        duplicados_por_paren[(str(p.parent), base_sin_paren)].append(p)

    # Marcar duplicados por sufijo (n): conservar el de mayor tamano
    n_duplicados_marcados = 0
    filas_por_ruta = {f["ruta_original"]: f for f in filas}
    for (_carpeta, _base), rutas in duplicados_por_paren.items():
        if len(rutas) < 2:
            continue
        rutas_ordenadas = sorted(rutas, key=lambda r: r.stat().st_size, reverse=True)
        for descartado in rutas_ordenadas[1:]:
            f = filas_por_ruta[str(descartado)]
            f["nivel"] = max(f["nivel"], 1)
            f["regla"] = (f["regla"] + "|DUPLICADO_DESCARTADO").strip("|")
            f["ruta_canonica"] = ""
            f["estado_duplicado"] = "DESCARTADO_DUPLICADO"
            n_duplicados_marcados += 1

    # Escribir manifiesto CSV
    OUT_MANIFIESTO.parent.mkdir(parents=True, exist_ok=True)
    campos = ["ruta_original", "nombre_original", "tipo", "nivel", "regla",
              "ruta_canonica", "nombre_canonico", "local_canonico",
              "zona_canonica", "cadena", "aviso_resuelto", "correlativo",
              "local_crudo", "aviso_crudo", "estado_duplicado"]
    with open(OUT_MANIFIESTO, "w", newline="", encoding="utf-8-sig") as fh:
        w = csv.DictWriter(fh, fieldnames=campos, extrasaction="ignore")
        w.writeheader()
        for f in filas:
            w.writerow(f)

    # Resumen
    por_nivel = defaultdict(int)
    por_tipo = defaultdict(int)
    for f in filas:
        por_nivel[f["nivel"]] += 1
        por_tipo[f["tipo"]] += 1

    total = len(filas)
    duplicados = sum(1 for f in filas if f.get("estado_duplicado") == "DESCARTADO_DUPLICADO")
    cuarentena = sum(1 for f in filas if f["nivel"] == 3)
    aplicados = total - duplicados - cuarentena

    resumen = [
        "# T1.6 - Resumen del analisis de saneamiento (fase de analisis, sin copiar nada)",
        "",
        f"Total de PDFs analizados: {total} (esperado 7333)",
        f"Cuadre: aplicados({aplicados}) + duplicados({duplicados}) + cuarentena({cuarentena}) = {aplicados+duplicados+cuarentena}",
        "",
        "## Por nivel de confianza",
    ]
    for niv in sorted(por_nivel):
        resumen.append(f"- Nivel {niv}: {por_nivel[niv]}")
    resumen.append("\n## Por tipo")
    for t, c in sorted(por_tipo.items()):
        resumen.append(f"- {t}: {c}")
    resumen.append(f"\n## Duplicados detectados por sufijo (n): {duplicados} (esperado ~204)")
    resumen.append(f"\n## Alias nuevos generados por normalizacion (Nivel 1): {len(set(alias_nuevos))}")
    for a in sorted(set(alias_nuevos))[:30]:
        resumen.append(f"  {a[0]} -> {a[1]} ({a[2]})")
    if len(set(alias_nuevos)) > 30:
        resumen.append(f"  ... y {len(set(alias_nuevos)) - 30} mas (ver manifiesto completo)")
    resumen.append(f"\n## Correcciones de aviso SAP por distancia de edicion (Nivel 2): {len(correcciones_aviso)}")
    for c in correcciones_aviso[:30]:
        resumen.append(f"  {c[0]} -> {c[1]}")

    OUT_RESUMEN.write_text("\n".join(resumen), encoding="utf-8")
    print("\n".join(resumen))
    print(f"\nManifiesto completo: {OUT_MANIFIESTO}")


if __name__ == "__main__":
    main()
