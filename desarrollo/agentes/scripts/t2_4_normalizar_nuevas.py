"""
T2.4.3 - Del espejo crudo del sistema al arbol canonico.

Toma lo que t2_4_sync_hostinger.py bajo a D:\\RESPALDOS\\_ORIGEN_SISTEMA (los
nombres tal cual los emitio el servidor) y lo promueve al arbol canonico
D:\\RESPALDOS\\ORDENES DE TRABAJO, con el nombre y la carpeta que manda el
estandar. A partir de ahi lo recoge t1_7_ingesta.py, que ya existe y esta
probado sobre 7.069 ordenes: no se duplica esa logica aqui.

POR QUE HACE FALTA UN PASO INTERMEDIO:
Lo que el sistema emite y lo que la base espera no coinciden. Medido sobre los
1.946 archivos de la copia del 2026-09-03:
  - 158 grafias distintas de local en 3 meses, y solo 26 traen el sufijo EC.
    El sistema pone lo que el tecnico teclea en un <input type="text">.
  - El preventivo escribe "Dia 3" con espacio; el canonico exige "D3".
  - 23 archivos salen con local, aviso y zona vacios (OT-0023---.pdf): son los
    87 envios con POST vacio que el formulario acepta por no verificar el metodo
    HTTP. No son ordenes de trabajo.
  - El preventivo mete las 3 zonas en un mismo directorio con un contador por
    zona, asi que 43 correlativos estan repetidos entre zonas.

NADA SE INVENTA (I-6). Un archivo cuyo local no resuelve de forma UNICA contra
el maestro no se promueve: se lista en el informe para que una persona decida.
El criterio de resolucion es el mismo de T1.6b, importado de alli, no una copia
adaptada: si el criterio cambia, cambia en un solo sitio.

NO BORRA NI MUEVE EL ORIGEN. Copia y verifica por hash (I-4). El espejo crudo
queda intacto como evidencia de lo que el sistema realmente produjo.

USO:
    .venv/Scripts/python.exe scripts/t2_4_normalizar_nuevas.py            # simula
    .venv/Scripts/python.exe scripts/t2_4_normalizar_nuevas.py --ejecutar # copia
"""
import argparse
import csv
import re
import shutil
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

import mysql.connector

sys.path.insert(0, str(Path(__file__).parent))
from t1_6b_resolver_cuarentena import normalizar_local_extendido  # noqa: E402
from t2_4_sync_hostinger import DESTINO as ESPEJO, sha256_de  # noqa: E402

from comun import RESPALDOS, SALIDAS  # noqa: E402  (rutas relativas, T2.15.1)
CANONICO = RESPALDOS / "ORDENES DE TRABAJO"
INFORMES = SALIDAS

# El modulo lo manda la carpeta de origen, no el nombre del archivo. Deducirlo
# del sufijo -D{n} fallaba en los preventivos que no llevan numero de dia (T1.6).
MODULO_DE = {"uio": "CORRECTIVO", "larb": "CORRECTIVO", "cnlj": "CORRECTIVO",
             "mant": "PREVENTIVO", "otros": "OTROS"}
ZONA_DE = {"uio": "UIO", "larb": "LARB", "cnlj": "CNLJ"}

# Nombre que emite el correctivo: OT-{corr}-{local}-{aviso}-{zona}.pdf
# El aviso es opcional: hay correctivos emitidos sin numero de aviso SAP porque
# el tecnico ya estaba en sitio y el aviso todavia no existia (regla del cliente,
# 2026-09-04). Exigirlo mandaba a cuarentena documentos correctos.
RE_CORRECTIVO = re.compile(
    r"^OT-(\d{3,5})-([A-Za-z0-9]*)-(\d*)-?([A-Za-z]*)\.pdf$")
# Nombre que emite el preventivo: OT-{corr}-{local}-{aviso}-Dia {n}-{zona}.pdf
RE_PREVENTIVO = re.compile(
    r"^OT-(\d{3,5})-([A-Za-z0-9]*)-(\d*)-?D[ií]a\s*(\d*)-([A-Za-z]*)\.pdf$",
    re.IGNORECASE)


def conectar(env):
    cnx = mysql.connector.connect(
        host=env["DB_HOST"], port=int(env["DB_PORT"]), user=env["DB_USER"],
        password=env["DB_PASSWORD"], database=env["DB_NAME"])
    return cnx


def cargar_maestro(cnx):
    cur = cnx.cursor(dictionary=True)
    cur.execute("SELECT local_codigo, zona, cadena FROM locales")
    maestro = {r["local_codigo"]: r for r in cur.fetchall()}
    cur.execute("SELECT alias_texto, local_codigo FROM locales_alias")
    alias = {re.sub(r"[^A-Za-z0-9]", "", r["alias_texto"]).upper(): r["local_codigo"]
             for r in cur.fetchall()}
    cur.execute("SELECT aviso, centro_coste FROM avisos_sap WHERE centro_coste IS NOT NULL")
    sap = {str(r["aviso"]): r["centro_coste"] for r in cur.fetchall()}
    cur.close()
    return maestro, set(maestro), alias, sap


def resolver(nombre, modulo_dir, canonicos, alias, sap, maestro, anio=None):
    """Devuelve (destino_relativo, nombre_canonico, regla) o (None, None, motivo).

    El motivo de rechazo se escribe tal cual en el informe: quien lo lea tiene
    que poder decidir sin volver a abrir el archivo.
    """
    modulo = MODULO_DE[modulo_dir]

    if modulo == "PREVENTIVO":
        m = RE_PREVENTIVO.match(nombre)
        if not m:
            return None, None, "el nombre no encaja con el patron del preventivo"
        corr, local_txt, aviso, dia, zona_txt = m.groups()
    elif modulo == "CORRECTIVO":
        m = RE_CORRECTIVO.match(nombre)
        if not m:
            return None, None, "el nombre no encaja con el patron del correctivo"
        corr, local_txt, aviso, zona_txt = m.groups()
        dia = None
    else:
        # ot_normal_otros no captura ni aviso ni zona; su nombre es
        # OT-{local}-{corr:3}. No se puede cruzar con avisos_sap ni asignar zona,
        # asi que no entra al arbol canonico de KFC por su cuenta.
        return None, None, "modulo OTROS: sin zona ni aviso, requiere decision manual"

    if not local_txt:
        return None, None, "sin codigo de local: envio con POST vacio, no es una OT"

    # El aviso SAP canonico tiene 8 digitos. El formulario lo deja escribir
    # libre, asi que llegan "0" y "1031" (el tecnico tecleo a medias). Un
    # aviso que no son 8 digitos NO es un aviso: se trata como orden sin
    # aviso, que es un caso de negocio legitimo (regla del cliente,
    # 2026-09-04), en vez de tirar el documento entero. Exigir el patron
    # completo rechazaba 4 preventivos reales solo por este campo.
    if aviso and not re.fullmatch(r"\d{8}", aviso):
        aviso = None

    # La zona de la carpeta manda sobre la del nombre. En correctivo cada zona
    # tiene su propio directorio en el servidor, asi que la carpeta es un dato
    # duro; el sufijo del nombre viene de un campo del formulario que llega
    # vacio en los 87 envios rotos.
    zona = ZONA_DE.get(modulo_dir) or (zona_txt or "").upper()
    if modulo == "PREVENTIVO":
        zona = (zona_txt or "").upper()
        if zona not in ("UIO", "LARB", "CNLJ"):
            return None, None, f"zona no reconocida en el nombre: '{zona_txt}'"

    local, regla = normalizar_local_extendido(local_txt, canonicos, alias)

    # Segunda fuente independiente: el centro de coste del aviso SAP. Es el
    # cruce que resolvio 519 de 554 casos en T1.6b.
    aviso_sap = normalizar_local_extendido(sap[aviso], canonicos, alias)[0] \
        if (aviso and aviso in sap) else None

    if not local and aviso_sap:
        local, regla = aviso_sap, "POR_AVISO_SAP"
    elif local and aviso_sap and aviso_sap != local:
        # DECISION 6 DEL PROYECTO: el interior del PDF manda sobre SAP. El
        # segmento de local del nombre y el campo "Local:" del PDF salen del
        # MISMO $campos['local'] que teclea el tecnico; el centro de coste sale
        # del numero de aviso, que es justo el campo que se digita mal. Asi que
        # el nombre gana y el documento SI se promueve -- rechazarlo, como hacia
        # la primera version de este script, mandaba 60 OTs correctas a revision
        # manual por contradecir un criterio ya cerrado del proyecto.
        #
        # Ahora bien, no todas las discrepancias son iguales, y la diferencia
        # importa. Medido sobre los 1.952 archivos reales: K073EC/H015EC,
        # K099EC/H032EC, K146EC/H052EC y K069EC/H014EC son PARES DE MARCAS EN EL
        # MISMO SITIO -- el KFC y la Heladeria de Miraflores Cuenca comparten
        # local fisico y nombre en el maestro. Ahi no hay error de nadie: el
        # tecnico nombra el sitio y SAP nombra la marca cuyo equipo fallo.
        # Se distinguen por el nombre del maestro, no por el codigo.
        n_nombre = (maestro.get(local) or {}).get("nombre")
        n_sap = (maestro.get(aviso_sap) or {}).get("nombre")
        if n_nombre and n_sap and n_nombre == n_sap:
            regla = f"{regla}; MARCA CONTIGUA (SAP dice {aviso_sap}, mismo sitio)"
        else:
            regla = f"{regla}; DISCREPA DE SAP (aviso {aviso} apunta a {aviso_sap})"

    if not local:
        return None, None, f"el local '{local_txt}' no resuelve de forma unica contra el maestro"

    info = maestro.get(local)
    if not info:
        return None, None, f"local {local} ausente del maestro"

    # La zona canonica es la del maestro, no la del formulario: el tecnico puede
    # enviar desde la zona equivocada y eso no cambia a que zona pertenece el local.
    zona_maestro = info["zona"]
    cadena = (info["cadena"] or "SIN CADENA").upper()

    corr4 = corr.zfill(4)
    partes = ["OT", corr4, local]
    if aviso:
        partes.append(aviso)
    if modulo == "PREVENTIVO" and dia:
        partes.append(f"D{int(dia)}")
    partes.append(zona_maestro)
    canon = "-".join(partes) + ".pdf"

    # El anio sale de la fecha del archivo EN EL SERVIDOR (el sync la preserva
    # con `get -p`), nunca de la fecha de hoy: una OT emitida el 31 de diciembre
    # y sincronizada el 2 de enero pertenece al arbol del anio anterior.
    anio = str(anio or datetime.now().year)
    destino = Path(anio) / modulo / zona_maestro / cadena / canon
    nota = regla if zona_maestro == zona else f"{regla}; ZONA CORREGIDA {zona}->{zona_maestro}"
    return destino, canon, nota


def main():
    ap = argparse.ArgumentParser(
        description="Promueve el espejo crudo del sistema al arbol canonico. Por defecto simula.")
    ap.add_argument("--ejecutar", action="store_true", help="copia de verdad")
    ap.add_argument("--modulo", choices=sorted(MODULO_DE), action="append")
    args = ap.parse_args()

    env = _env_minimo()
    cnx = conectar(env)
    maestro, canonicos, alias, sap = cargar_maestro(cnx)
    print(f"Maestro: {len(maestro)} locales | {len(alias)} alias | {len(sap)} avisos SAP\n")

    modulos = args.modulo or [m for m in MODULO_DE if (ESPEJO / m).is_dir()]
    promovidos, rechazados, ya_estaban, colisiones = [], [], [], []

    for mod in modulos:
        origen = ESPEJO / mod
        if not origen.is_dir():
            print(f"[{mod}] sin espejo local todavia, se omite")
            continue
        archivos = sorted(p for p in origen.glob("*.pdf") if p.is_file())
        print(f"[{mod}] {len(archivos)} PDFs en el espejo")

        for p in archivos:
            anio = datetime.fromtimestamp(p.stat().st_mtime).year
            destino_rel, canon, nota = resolver(p.name, mod, canonicos, alias, sap, maestro, anio)
            if destino_rel is None:
                rechazados.append({"modulo": mod, "origen": p.name, "motivo": nota})
                continue
            destino = CANONICO / destino_rel
            h_origen = sha256_de(p)

            if destino.exists():
                # Mismo nombre canonico ya presente. Si el contenido es identico
                # es que ya lo promovimos: nada que hacer. Si difiere, son dos
                # documentos distintos peleando por el mismo nombre -- pasa por la
                # condicion de carrera del contador -- y eso lo decide una persona.
                if sha256_de(destino) == h_origen:
                    ya_estaban.append({"modulo": mod, "origen": p.name, "canonico": canon})
                else:
                    colisiones.append({"modulo": mod, "origen": p.name, "canonico": canon,
                                       "motivo": "ya existe con contenido distinto"})
                continue

            promovidos.append({"modulo": mod, "origen": p.name, "canonico": canon,
                               "destino": str(destino_rel), "regla": nota,
                               "sha256": h_origen})
            if args.ejecutar:
                destino.parent.mkdir(parents=True, exist_ok=True)
                tmp = destino.with_suffix(".pdf.parcial")
                shutil.copy2(p, tmp)
                # Verificar ANTES de dejarlo con su nombre definitivo: un archivo
                # a medias con nombre canonico entraria a la ingesta como bueno.
                if sha256_de(tmp) != h_origen:
                    tmp.unlink()
                    rechazados.append({"modulo": mod, "origen": p.name,
                                       "motivo": "la copia no verifico por hash"})
                    promovidos.pop()
                    continue
                tmp.replace(destino)

    INFORMES.mkdir(parents=True, exist_ok=True)
    sello = datetime.now().strftime("%Y%m%dT%H%M%S")
    inf = INFORMES / f"NORMALIZACION_{sello}.csv"
    with open(inf, "w", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f)
        w.writerow(["estado", "modulo", "archivo_origen", "nombre_canonico", "destino", "detalle"])
        for r in promovidos:
            w.writerow(["PROMOVIDO", r["modulo"], r["origen"], r["canonico"], r["destino"], r["regla"]])
        for r in ya_estaban:
            w.writerow(["YA_ESTABA", r["modulo"], r["origen"], r["canonico"], "", ""])
        for r in colisiones:
            w.writerow(["COLISION", r["modulo"], r["origen"], r["canonico"], "", r["motivo"]])
        for r in rechazados:
            w.writerow(["NO_RESUELTO", r["modulo"], r["origen"], "", "", r["motivo"]])

    print("\n" + "=" * 66)
    print(f"{'Promovidos' if args.ejecutar else 'Promovibles':<14}: {len(promovidos)}")
    print(f"{'Ya estaban':<14}: {len(ya_estaban)}")
    print(f"{'Colisiones':<14}: {len(colisiones)}")
    print(f"{'No resueltos':<14}: {len(rechazados)}")
    if rechazados:
        print("\nMotivos de lo no resuelto:")
        for motivo, n in Counter(r["motivo"].split(":")[0] for r in rechazados).most_common():
            print(f"  {n:>5}  {motivo}")
    print(f"\nInforme: {inf}")
    if args.ejecutar and promovidos:
        print("\nSiguiente paso, para que entren a la base:")
        print("  .venv/Scripts/python.exe scripts/t1_7_ingesta.py")
    elif not args.ejecutar:
        print("\nSimulacion. Para copiar de verdad: --ejecutar")
    cnx.close()


def _env_minimo():
    """El espejo puede no existir todavia (primera corrida antes del sync). En
    ese caso solo hacen falta las credenciales de la base, no las de Hostinger."""
    env = {}
    from comun import ENV_PATH as p
    for line in p.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip()
    return env


if __name__ == "__main__":
    main()
