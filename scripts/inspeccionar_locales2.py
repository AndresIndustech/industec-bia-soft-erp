import openpyxl
PATH = r"D:\RESPALDOS\_ORIGEN_DRIVE\LOCALES_INDUSTEC_GENERAL\LOCALES_INDUSTEC_GENERAL.xlsx"
wb = openpyxl.load_workbook(PATH, data_only=True)

for name in ['GENERAL']:
    ws = wb[name]
    print(f"===== {name} completo =====")
    for row in ws.iter_rows(min_row=1, max_row=ws.max_row, max_col=ws.max_column):
        vals = [f"{c.coordinate}={c.value}" for c in row if c.value is not None]
        if vals: print("  " + " | ".join(vals))

ws = wb['LOCALES INDUSTEC']
print(f"\n===== LOCALES INDUSTEC completo (99 filas) =====")
codigos_vistos = set()
zonas_vistas = set()
for row in ws.iter_rows(min_row=2, max_row=ws.max_row, max_col=ws.max_column):
    b, c, d, e, f, g = [row[i].value if i < len(row) else None for i in range(6)]
    if b:
        codigos_vistos.add(b)
        if c: zonas_vistas.add(c)
print("Codigos unicos:", len(codigos_vistos))
print("Zonas vistas:", zonas_vistas)
print("Primeros 5 codigos:", list(codigos_vistos)[:5])
# Mostrar filas con correo distinto al patron comun, y las ultimas filas
for row in ws.iter_rows(min_row=90, max_row=99, max_col=7):
    vals = [f"{c.coordinate}={c.value}" for c in row if c.value is not None]
    if vals: print("  " + " | ".join(vals))
