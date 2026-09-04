"""T1.6b (paso 2/3) - Resolucion de los 364 casos de Nivel 3 (cuarentena).

FASE DE ANALISIS: no mueve archivos ni escribe en la base. Produce el informe de
que se puede resolver, con que evidencia y con que nivel de confianza.

CRITERIO (fijado aqui, no queda a criterio del agente).
Se reunen hasta TRES señales independientes del local al que pertenece cada orden:

  P. INTERIOR DEL PDF   'Local:' y 'Cliente:' impresos dentro del documento. Es lo que
                        el tecnico declaro en sitio; no depende del nombre del archivo.
  S. SAP                avisos_sap.centro_coste del aviso. Es el sistema de KFC: dice a
                        que local se factura ese aviso.
  A. PLAN DE LA ADMIN   El LOCAL que la administracion asigno a mano en sus planes.
                        Es la decision humana que el cliente pidio respetar.

Regla de decision por mayoria, explicita:
  - Si dos o mas señales coinciden -> ese es el local (confianza ALTA).
  - Si solo hay una señal -> se acepta, pero con confianza MEDIA y queda marcada.
  - Si las señales disponibles se contradicen sin mayoria -> NO se decide:
    va a revision humana (I-10). Nunca se elige "la primera que llego".

Casos particulares resueltos aqui de forma explicita:
  - LOCAL COMPUESTO 'Kxxx-Hyyy': son DOS marcas en el mismo sitio fisico (p. ej.
    K073-H015 = KFC Miraflores + Heladeria Miraflores). No es un error de escritura.
    Manda el criterio de la administracion; el centro de coste de SAP se conserva
    aparte para no perder el dato contable.
  - AVISO CON CEROS A LA IZQUIERDA ('000010279736'): normalizacion segura a
    '10279736'. Es Nivel 1, no ambiguedad.
  - PREVENTIVO SIN AVISO: no es un defecto. El mantenimiento preventivo no nace de
    un aviso SAP; el patron canonico exigia un aviso que estos documentos nunca
    tienen. Se resuelve por local y se renombra sin el segmento de aviso.

Salida: SALIDAS IA\\CALIDAD\\RESOLUCION_CUARENTENA.csv  +  resumen en consola.
"""
import collections
import csv
import re
import sys
from pathlib import Path

import mysql.connector

sys.path.insert(0, str(Path(__file__).parent))
from t1_7_extractor_pdf import extraer_pdf

BASE = Path(r"D:\INDUSTECH IA\desarrollo\agentes")
CALIDAD = Path(r"D:\INDUSTECH IA\SALIDAS IA\CALIDAD")
MANIFIESTO = CALIDAD / "MANIFIESTO_SANEAMIENTO.csv"
INDICE_PLANES = CALIDAD / "INDICE_PLANES_ADMIN.csv"
CUARENTENA = Path(r"D:\RESPALDOS\_CUARENTENA")
SALIDA = CALIDAD / "RESOLUCION_CUARENTENA.csv"

RE_COMPUESTO = re.compile(r"^([A-Z]{1,2}\d{2,4})[\-\s/,]*([HK]\d{2,4})$", re.IGNORECASE)


def conectar():
    env = {}
    for line in (BASE / "config/.env").read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])


def leer_csv(path, encoding="utf-8"):
    return list(csv.DictReader(path.read_text(encoding=encoding).splitlines()))


def normalizar_aviso(valor):
    """Quita ceros a la izquierda y deja solo digitos. '000010279736' -> '10279736'."""
    if not valor:
        return ""
    s = re.sub(r"\D", "", str(valor))
    s = s.lstrip("0")
    return s if s else ""


def normalizar_local(valor, canonicos, alias):
    """Lleva un codigo de local escrito a mano al canonico del maestro."""
    if not valor:
        return None
    s = re.sub(r"[^A-Za-z0-9]", "", str(valor)).upper()
    if not s:
        return None
    if s in canonicos:
        return s
    if s in alias:
        return alias[s]
    m = re.match(r"^([A-Z]{1,2})(\d{1,4})(EC)?$", s)
    if not m:
        return None
    letras, numero = m.group(1), m.group(2)
    candidatos = {f"{letras}{numero.zfill(k)}EC" for k in (2, 3, 4)}
    candidatos |= {f"{letras}{numero.zfill(k)}" for k in (2, 3, 4)}
    hallados = {c for c in candidatos if c in canonicos}
    hallados |= {alias[c] for c in candidatos if c in alias}
    return hallados.pop() if len(hallados) == 1 else None


def normalizar_local_extendido(valor, canonicos, alias):
    """Como normalizar_local, mas dos reglas ya validadas en T1.6 para texto libre:

    - prefijo de marca pegado al codigo: 'Jv045' -> 'V045EC' (Juan Valdez),
      'Cj028' -> 'J028EC'. Se acepta solo si al quitar la primera letra el resto
      resuelve de forma unica contra el maestro.
    - digito extra tecleado de mas: 'K1167' -> 'K167EC'. Se acepta solo si existe
      exactamente un canonico a distancia de un digito borrado.
    """
    directo = normalizar_local(valor, canonicos, alias)
    if directo:
        return directo, "DIRECTO"
    if not valor:
        return None, ""
    s = re.sub(r"[^A-Za-z0-9]", "", str(valor)).upper()
    if len(s) > 2 and s[0].isalpha():
        sin_prefijo = normalizar_local(s[1:], canonicos, alias)
        if sin_prefijo:
            return sin_prefijo, "PREFIJO_MARCA_REMOVIDO"
    m = re.match(r"^([A-Z]{1,2})(\d{4,5})(EC)?$", s)
    if m:
        letras, numero = m.group(1), m.group(2)
        candidatos = set()
        for i in range(len(numero)):
            recorte = numero[:i] + numero[i + 1:]
            c = normalizar_local(f"{letras}{recorte}", canonicos, alias)
            if c:
                candidatos.add(c)
        if len(candidatos) == 1:
            return candidatos.pop(), "DIGITO_SOBRANTE_REMOVIDO"
    return None, ""


def normalizar_texto(s):
    if not s:
        return ""
    s = str(s).upper()
    for a, b in [("Á", "A"), ("É", "E"), ("Í", "I"), ("Ó", "O"), ("Ú", "U"), ("Ñ", "N")]:
        s = s.replace(a, b)
    return re.sub(r"[^A-Z0-9]", "", s)


def local_por_nombre_maestro(textos, indice_nombres, nombres_cadena):
    """Resuelve por coincidencia del texto libre del PDF contra el nombre del local
    en el maestro ('KFC Centro Historico' -> K167EC). Solo si es inequivoco.

    Se descarta el texto que solo aporta la MARCA ('American Deli', 'KFC'): el nombre
    de la cadena no identifica un local, y aceptarlo produjo un falso positivo real
    (A018 -> A010EC porque 'American Deli' solo calzaba con un local de esa cadena).
    Se exige que quede informacion de ubicacion propia despues de quitar la marca.
    """
    for t in textos:
        n = normalizar_texto(t)
        if len(n) < 6:
            continue
        resto = n
        for cad in nombres_cadena:
            resto = resto.replace(cad, "")
        if len(resto) < 5:
            continue  # el texto era solo la marca: no identifica local
        hits = {cod for nombre, cod in indice_nombres.items()
                if nombre == n or (len(n) >= 8 and (n in nombre or nombre in n))}
        if len(hits) == 1:
            return hits.pop()
    return None


def codigos_en_texto(texto, canonicos, alias):
    """Devuelve, en orden, todos los codigos canonicos que aparecen en un texto.

    'K073-H015' -> [K073EC, H015EC]   |   'H014 /h069' -> [H014EC, K069EC]
    Dos o mas codigos significan un sitio con dos marcas (KFC + Heladeria en el
    mismo local), no un error de escritura.
    """
    if not texto:
        return []
    hallados = []
    for token in re.findall(r"[A-Za-z]{1,2}\s?\d{2,4}", str(texto)):
        c = normalizar_local(token.replace(" ", ""), canonicos, alias)
        if c and c not in hallados:
            hallados.append(c)
    return hallados


def local_por_cadena_y_numero(pdf_cliente, textos_codigo, maestro):
    """Resuelve cuando la LETRA del codigo es la inicial de la marca y no la del maestro.

    'Local: J054' + 'Cliente: Juan valdez' -> V054EC, porque en el maestro Juan Valdez
    usa la letra V y existe exactamente un local suyo con el numero 054. Caso real: sin
    esta regla el documento se resolvia por el aviso SAP y terminaba archivado en un KFC.
    Solo se acepta si la cadena del PDF identifica una unica cadena del maestro y el
    numero da un unico local dentro de ella.
    """
    if not pdf_cliente:
        return None
    cliente_norm = normalizar_texto(pdf_cliente)
    cadenas = {r["cadena"] for r in maestro.values() if r["cadena"]}
    candidatas = [c for c in cadenas if normalizar_texto(c) and normalizar_texto(c) in cliente_norm]
    if len(candidatas) != 1:
        return None
    cadena = candidatas[0]
    for texto in textos_codigo:
        m = re.match(r"^[A-Za-z]{1,2}(\d{2,4})$", re.sub(r"[^A-Za-z0-9]", "", str(texto or "")))
        if not m:
            continue
        numero = int(m.group(1))
        hits = {cod for cod, r in maestro.items()
                if r["cadena"] == cadena
                and re.match(r"^[A-Z]{1,2}(\d+)EC$", cod)
                and int(re.match(r"^[A-Z]{1,2}(\d+)EC$", cod).group(1)) == numero}
        if len(hits) == 1:
            return hits.pop()
    return None


def codigos_por_numero(texto, maestro):
    """Codigos del maestro que comparten el numero de un token del texto.

    Sirve para el segundo miembro de un local compuesto cuando su letra esta mal
    escrita ('h069' por 'K069'). Solo devuelve el codigo si el numero identifica a
    uno solo en todo el maestro: si hay dos, no se adivina.
    """
    if not texto:
        return []
    salida = []
    for token in re.findall(r"[A-Za-z]{1,2}\s?\d{2,4}", str(texto)):
        m = re.match(r"^[A-Za-z]{1,2}\s?(\d{2,4})$", token)
        if not m:
            continue
        numero = int(m.group(1))
        hits = set()
        for cod in maestro:
            mm = re.match(r"^[A-Z]{1,2}(\d+)EC$", cod)
            if mm and int(mm.group(1)) == numero:
                hits.add(cod)
        if len(hits) == 1:
            c = hits.pop()
            if c not in salida:
                salida.append(c)
    return salida


def senal_local_pdf(nombre_archivo, pdf_local_txt, pdf_cliente, canonicos, alias, maestro=None):
    """Local declarado por el propio documento. Mismo criterio en todos los pasos:
    texto 'Local:', luego 'Cliente:', y como ultimo recurso el codigo del nombre del
    archivo (para los 'INFORME TECNICO K124 ...' que no tienen estructura de OT)."""
    for fuente in (pdf_local_txt, pdf_cliente):
        loc, regla = normalizar_local_extendido(fuente, canonicos, alias)
        if loc:
            return loc, regla
    codigos = codigos_en_texto(pdf_local_txt, canonicos, alias)
    if codigos:
        return codigos[0], "CODIGO_DENTRO_DEL_TEXTO"
    if maestro:
        porcadena = local_por_cadena_y_numero(pdf_cliente, [pdf_local_txt], maestro)
        if porcadena:
            return porcadena, "CADENA_Y_NUMERO"
    for token in re.findall(r"[A-Za-z]{1,2}\d{2,4}", nombre_archivo or ""):
        cand, _ = normalizar_local_extendido(token, canonicos, alias)
        if cand:
            return cand, "CODIGO_EN_NOMBRE_ARCHIVO"
    return None, ""


def main():
    cnx = conectar()
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT local_codigo, zona, cadena, nombre FROM locales")
    maestro = {r["local_codigo"]: r for r in cur.fetchall()}
    canonicos = set(maestro)
    cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
    alias = {re.sub(r"[^A-Za-z0-9]", "", r["alias_texto"]).upper(): r["local_codigo"]
             for r in cur.fetchall()}
    cur.execute("SELECT aviso, centro_coste FROM avisos_sap WHERE centro_coste IS NOT NULL")
    sap = {str(r["aviso"]): r["centro_coste"] for r in cur.fetchall()}
    indice_nombres = {normalizar_texto(r["nombre"]): cod for cod, r in maestro.items() if r["nombre"]}
    nombres_cadena = sorted({normalizar_texto(r["cadena"]) for r in maestro.values() if r["cadena"]},
                            key=len, reverse=True)
    print(f"Maestro: {len(maestro)} locales | alias: {len(alias)} | avisos SAP: {len(sap)}")

    idx = leer_csv(INDICE_PLANES)
    plan_por_aviso = collections.defaultdict(set)
    plan_por_zona_corr = collections.defaultdict(list)
    for f in idx:
        loc = normalizar_local(f["local_plan"], canonicos, alias)
        av = normalizar_aviso(f["aviso_plan"])
        if av and loc:
            plan_por_aviso[av].add(loc)
        if f["correlativo_ref"]:
            plan_por_zona_corr[(f["zona_plan"], f["correlativo_ref"])].append(
                {"local": loc, "local_ref": f["local_en_referencia"],
                 "aviso_ref": normalizar_aviso(f["aviso_en_referencia"]), "aviso_plan": av})
    print(f"Planes: {len(plan_por_aviso)} avisos con local asignado a mano")

    man = leer_csv(MANIFIESTO, encoding="utf-8-sig")
    # Se resuelven los dos grupos que quedaron sin destino en T1.6:
    #  - Nivel 3: los 364 casos enviados a cuarentena por local o aviso sin resolver.
    #  - Nivel 1 sin ruta canonica: 161 documentos correctos cuyo nombre usa otro patron
    #    ('OT-Cajun-10280653-CNLJ-023.pdf', con el correlativo al final) que el saneamiento
    #    no supo enrutar. No estaban mal: estaban sin clasificar.
    vistos = set()
    cuarentena = []
    for f in man:
        clave = (f["nombre_original"], f["ruta_original"])
        if clave in vistos:
            continue
        if f["nivel"] == "3" or not f["ruta_canonica"]:
            vistos.add(clave)
            cuarentena.append(f)
    print(f"Casos a resolver: {len(cuarentena)}")
    print("Leyendo el interior de cada PDF (fuente independiente del nombre)...")

    # ---- PASO 1: leer el interior de cada PDF (evidencia independiente del nombre) ----
    evidencia = []
    sin_pdf = 0
    for i, f in enumerate(cuarentena, 1):
        if i % 100 == 0:
            print(f"  {i}/{len(cuarentena)}")
        # Se lee SIEMPRE desde la ruta original (_ORIGEN_DRIVE): es la unica que no se
        # mueve. Leer desde la bandeja de cuarentena hacia perder la señal del PDF en
        # cuanto un documento se promovia, y con ella el criterio de los locales
        # compuestos: la resolucion cambiaba segun cuantas veces se hubiera corrido.
        ruta = Path(f["ruta_original"])
        if not ruta.exists():
            for alterna in (CUARENTENA / f["nombre_original"],
                            CUARENTENA / "_RESUELTOS" / f["nombre_original"]):
                if alterna.exists():
                    ruta = alterna
                    break
        pdf_local_txt = pdf_cliente = aviso_interno = ""
        if ruta.exists():
            d = extraer_pdf(ruta)
            if not d.get("error"):
                pdf_local_txt = (d.get("local_texto") or "").strip()
                pdf_cliente = (d.get("cliente") or "").strip()
                aviso_interno = normalizar_aviso(d.get("aviso"))
        else:
            sin_pdf += 1
        aviso = normalizar_aviso(f["aviso_resuelto"] or f["aviso_crudo"]) or aviso_interno
        evidencia.append((f, aviso, pdf_local_txt, pdf_cliente))

    # ---- PASO 2: detectar avisos reutilizados en locales distintos ----
    # Un mismo numero de aviso escrito en documentos de locales diferentes significa que
    # el tecnico reutilizo el numero: ese aviso no sirve para decidir el local de nadie.
    locales_por_aviso = collections.defaultdict(set)
    for f, aviso, pdf_local_txt, pdf_cliente in evidencia:
        loc, _ = senal_local_pdf(f["nombre_original"], pdf_local_txt, pdf_cliente, canonicos, alias, maestro)
        if aviso and loc:
            locales_por_aviso[aviso].add(loc)
    aviso_duplicado = {a: len(v) for a, v in locales_por_aviso.items()}
    repetidos = sum(1 for v in aviso_duplicado.values() if v > 1)
    print(f"Avisos reutilizados en mas de un local (no sirven para decidir): {repetidos}")

    # ---- PASO 3: decidir caso por caso ----
    resultados = []
    for f, aviso, pdf_local_txt, pdf_cliente in evidencia:
        nombre = f["nombre_original"]
        p_local, regla_p = senal_local_pdf(nombre, pdf_local_txt, pdf_cliente, canonicos, alias, maestro)
        codigos_pdf = codigos_en_texto(pdf_local_txt, canonicos, alias)
        if len(codigos_pdf) < 2:
            # 'H014 /h069': el segundo codigo lleva la letra equivocada (H069EC no existe,
            # el local real es K069EC). Cuando el texto trae DOS codigos y solo uno resuelve,
            # se busca el otro por su numero; se acepta solo si es unico en el maestro.
            codigos_pdf = codigos_pdf + [c for c in codigos_por_numero(pdf_local_txt, maestro)
                                         if c not in codigos_pdf]
        p_kfc = codigos_pdf[0] if len(codigos_pdf) >= 2 else None
        p_hel = codigos_pdf[1] if len(codigos_pdf) >= 2 else None

        # ---------- señal N: nombre del local en el maestro ----------
        n_local = local_por_nombre_maestro([pdf_cliente, pdf_local_txt], indice_nombres, nombres_cadena)

        # ---------- señal S: SAP ----------
        s_local = normalizar_local(sap.get(aviso), canonicos, alias) if aviso else None

        # ---------- señal A: plan de la administracion ----------
        locales_plan = plan_por_aviso.get(aviso, set()) if aviso else set()
        a_local = next(iter(locales_plan)) if len(locales_plan) == 1 else None
        if not a_local and f["correlativo"]:
            corroborados = set()
            for r in plan_por_zona_corr.get((f["zona_canonica"], f["correlativo"]), []):
                if not r["local"]:
                    continue
                ref_local = normalizar_local(r["local_ref"], canonicos, alias)
                if (aviso and (r["aviso_ref"] == aviso or r["aviso_plan"] == aviso)) or \
                   (p_local and ref_local == p_local) or \
                   (p_kfc and ref_local in (p_kfc, p_hel)):
                    corroborados.add(r["local"])
            if len(corroborados) == 1:
                a_local = corroborados.pop()

        # ---------- decision ----------
        señales = {"P_PDF": p_local, "S_SAP": s_local, "A_PLAN": a_local, "N_NOMBRE": n_local}
        presentes = {k: v for k, v in señales.items() if v}
        votos = collections.Counter(presentes.values())
        local_final = via = ""
        confianza = "NO_RESUELTO"
        nota = ""

        es_compuesto = bool(p_kfc and p_hel)
        if es_compuesto:
            # dos marcas en el mismo sitio: manda la administracion; SAP se conserva aparte
            if a_local in (p_kfc, p_hel):
                local_final, via, confianza = a_local, "COMPUESTO_SEGUN_ADMIN", "ALTA"
                nota = f"local compuesto {p_kfc}+{p_hel}; la administracion archiva en {a_local}"
                if s_local and s_local != a_local:
                    nota += f"; SAP factura a {s_local}"
            elif s_local in (p_kfc, p_hel):
                local_final, via, confianza = s_local, "COMPUESTO_SEGUN_SAP", "MEDIA"
                nota = f"local compuesto {p_kfc}+{p_hel}; sin criterio de la administracion, se usa el centro de coste SAP"
            else:
                local_final, via, confianza = p_kfc, "COMPUESTO_SIN_ARBITRO", "MEDIA"
                nota = f"local compuesto {p_kfc}+{p_hel}; sin SAP ni plan, se archiva bajo la marca principal {p_kfc}"
        elif p_local:
            # PRECEDENCIA DEL DOCUMENTO. El interior del PDF es lo que el tecnico declaro
            # en sitio; las otras señales se derivan del AVISO o del CORRELATIVO, que son
            # justamente los campos que se digitan mal. La verificacion lo respaldo: en los
            # 250 casos comparables el PDF coincidio con lo deducido del nombre sin una
            # sola discrepancia, y la muestra contra el texto crudo dio 34/34.
            # El archivo se guarda donde se hizo el trabajo, no donde se factura un aviso
            # equivocado. Toda discrepancia se registra como hallazgo de calidad.
            local_final = p_local
            coinciden = [k for k, v in presentes.items() if v == p_local and k != "P_PDF"]
            discrepan = [f"{k}={v}" for k, v in presentes.items() if v != p_local]
            if coinciden:
                via, confianza = "MAYORIA_P_PDF+" + "+".join(coinciden), "ALTA"
            else:
                via, confianza = "PDF_MANDA", "MEDIA"
            if aviso_duplicado.get(aviso, 0) > 1:
                nota = "aviso reutilizado en varios locales distintos"
            elif not discrepan:
                nota = "unica fuente disponible" if not coinciden else ""
            if discrepan:
                nota = (nota + "; " if nota else "") + "discrepa " + ", ".join(discrepan) +                        " (posible aviso mal digitado)"
        elif votos and votos.most_common(1)[0][1] >= 2:
            local_final = votos.most_common(1)[0][0]
            coinciden = [k for k, v in presentes.items() if v == local_final]
            via, confianza = "MAYORIA_" + "+".join(coinciden), "ALTA"
            discrepan = [f"{k}={v}" for k, v in presentes.items() if v != local_final]
            if discrepan:
                nota = "discrepa " + ", ".join(discrepan) + " (posible aviso mal digitado)"
        elif len(presentes) == 1:
            via_k, local_final = next(iter(presentes.items()))
            via = "UNICA_SENAL_" + via_k
            confianza = "MEDIA"
            nota = "unica fuente disponible"
        elif len(presentes) > 1:
            confianza = "CONFLICTO_REVISION_HUMANA"
            nota = "sin mayoria: " + ", ".join(f"{k}={v}" for k, v in presentes.items())
        elif f["tipo"] == "PREVENTIVO":
            prev = f["local_canonico"] or normalizar_local(f["local_crudo"], canonicos, alias)
            if prev:
                local_final, via, confianza = prev, "PREVENTIVO_SIN_AVISO", "MEDIA"
                nota = "preventivo sin aviso SAP (normal); local tomado del nombre ya normalizado"

        # ---------- clasificacion de lo que no se resuelve ----------
        # No todo lo que queda sin local es un defecto. Se separa en tres causas
        # distintas para que la administracion decida sobre lo que si le compete.
        categoria = "OT_DE_GRUPO_KFC"
        if not local_final and presentes:
            # hay señales pero se contradicen: es un conflicto, no un cliente externo
            categoria = "CONFLICTO_ENTRE_FUENTES"
        elif not local_final:
            texto_ref = f"{pdf_cliente} {pdf_local_txt}".upper()
            codigo_visible = re.match(r"^[A-Za-z]{1,2}\d{2,4}$",
                                      re.sub(r"[^A-Za-z0-9]", "", pdf_local_txt or ""))
            if not pdf_local_txt and not pdf_cliente:
                categoria = "NO_ES_UNA_OT"
                nota = "documento sin estructura de OT (informe tecnico suelto)"
            elif codigo_visible:
                categoria = "FALTA_EN_MAESTRO"
                nota = (f"codigo {pdf_local_txt!r} ({pdf_cliente}) no existe en el maestro "
                        f"de 95 locales: requiere decision de la administracion")
            elif texto_ref.strip():
                categoria = "OTRO_CLIENTE"
                nota = f"cliente fuera del Grupo KFC: {pdf_cliente!r} / {pdf_local_txt!r}"

        resultados.append({
            "nombre_original": nombre,
            "ruta_original": f["ruta_original"],
            "tipo": f["tipo"],
            "regla_t16": f["regla"],
            "zona_nombre": f["zona_canonica"],
            "correlativo": f["correlativo"],
            "local_crudo": f["local_crudo"],
            "aviso": aviso,
            "aviso_crudo_original": f["aviso_crudo"],
            "pdf_local_texto": pdf_local_txt,
            "pdf_cliente": pdf_cliente,
            "senal_pdf": p_local or "",
            "senal_sap": s_local or "",
            "senal_plan": a_local or "",
            "senal_nombre": n_local or "",
            "categoria": categoria,
            "local_resuelto": local_final,
            "cadena_resuelta": maestro[local_final]["cadena"] if local_final in maestro else "",
            "zona_resuelta": maestro[local_final]["zona"] if local_final in maestro else "",
            "via_resolucion": via,
            "confianza": confianza,
            "nota": nota,
        })

    with SALIDA.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=list(resultados[0]))
        w.writeheader()
        w.writerows(resultados)

    print(f"\nPDFs no encontrados en _CUARENTENA: {sin_pdf}")
    print("\n=== Por via de resolucion ===")
    for via, c in collections.Counter(r["via_resolucion"] or "(sin via)" for r in resultados).most_common():
        print(f"  {c:4d}  {via}")
    print("\n=== Por confianza ===")
    for cf, c in collections.Counter(r["confianza"] for r in resultados).most_common():
        print(f"  {c:4d}  {cf}")
    resueltos = [r for r in resultados if r["local_resuelto"]]
    print(f"\nResueltos: {len(resueltos)} de {len(resultados)} ({len(resueltos)/len(resultados)*100:.1f}%)")

    disc = [r for r in resueltos if "discrepa" in r["nota"]]
    print(f"\nResueltos por mayoria PERO con una fuente en desacuerdo: {len(disc)}")
    print("  (son hallazgos reales de calidad: aviso mal digitado por el tecnico)")
    for r in disc[:10]:
        print(f"    {r['nombre_original'][:44]:44s} -> {r['local_resuelto']:8s} | {r['nota'][:60]}")

    zdif = [r for r in resueltos if r["zona_resuelta"] and r["zona_nombre"] and r["zona_resuelta"] != r["zona_nombre"]]
    print(f"\nCambian de zona respecto del nombre: {len(zdif)}")
    for r in zdif[:10]:
        print(f"    {r['nombre_original'][:44]:44s} {r['zona_nombre']} -> {r['zona_resuelta']} ({r['local_resuelto']})")

    print(f"\nInforme: {SALIDA}")
    cur.close()
    cnx.close()


if __name__ == "__main__":
    main()
