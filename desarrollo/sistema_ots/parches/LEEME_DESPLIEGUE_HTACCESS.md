# Cerrar la exposición de los PDFs — cómo subirlo

**Fecha:** 2026-09-08 · **Autorizado por:** Andrés Basantes
**Tiempo:** unos 5 minutos · **No hace falta SSH**

---

## Qué está pasando ahora mismo

Verificado por HTTP el 2026-09-08, sin ninguna autenticación:

```
200  /ot/produccion/ot_normal_v3/uio/uploads/OT-1427-G025-10334255-UIO.pdf
200  /ot/produccion/ot_normal_v3/larb/uploads/OT-1629-K080-10332528-LARB.pdf
200  /ot/produccion/ot_normal_v3/cnlj/uploads/OT-1688-K069-10330081-CNLJ.pdf
200  /ot/produccion/ot_mantenimiento/uploads/OT-0131-K073-...-CNLJ.pdf
200  /ot/produccion/ot_mantenimiento/registros/mail_normal.log
200  /ot/produccion/ot_normal_v3/uio/registros/error_normal.log
200  /ot/produccion/ot_normal_v3/uio/contadores/counter_UIO.txt
```

Cada PDF lleva nombre, apellido, correo y **firma manuscrita** del administrador
del local. El log de correos expone direcciones. El nombre de los archivos es
adivinable.

> **Ojo con el documento anterior.** `uploads.htaccess` documenta rutas bajo
> `ot/pruebas/`, que **ya no existen**: el sistema se movió a `ot/produccion/`.
> Si lo subes ahí, lo pones en carpetas vacías y la exposición sigue abierta.
> Usa las rutas de esta guía.

---

## Antes de subir: una pregunta a la administración

**¿Alguien recupera PDFs pegando la URL en el navegador?** Es lo único que deja
de funcionar. Si la respuesta es sí, avísale que a partir de ahora los busque en
`SALIDAS IA\OTS\CATALOGO OTS (generado agente).xlsx`, que tiene las 7.069
órdenes con la ruta de cada PDF.

Todo lo demás sigue igual: el técnico no nota nada y el local sigue recibiendo su
PDF adjunto en el correo.

---

## Paso 1 · Medir cómo está antes

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv\Scripts\python.exe scripts\verificar_exposicion.py
```

Debe decir **7 expuestos** y los dos formularios en `ok`. Guarda esa salida: es
la evidencia del antes.

---

## Paso 2 · Crear el archivo en hPanel

1. Entra a **hPanel** → el sitio `yellow-elephant-166233.hostingersite.com`
2. **Archivos → Administrador de archivos**
3. Navega hasta:
   ```
   public_html / ot / produccion
   ```
   *(Tiene que ser la carpeta `produccion`, no `pruebas`.)*
4. Arriba, en **Configuración**, activa **«Mostrar archivos ocultos»** — si no,
   no verás los archivos que empiezan con punto.
5. **Nuevo archivo** → nómbralo exactamente:
   ```
   .htaccess
   ```
   **Con el punto adelante y sin extensión.**
6. Ábrelo y pega el contenido de
   [`produccion.htaccess`](produccion.htaccess) (este mismo repositorio).
7. **Guardar**.

> **Si ya existía un `.htaccess` en esa carpeta:** no lo reemplaces. Descárgalo
> primero, guárdalo como respaldo, y **pega el contenido nuevo al final** del que
> ya está.

---

## Paso 3 · Comprobar que funcionó

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv\Scripts\python.exe scripts\verificar_exposicion.py
```

**Lo que tiene que salir:**

```
403  cerrado   PDF con firma, UIO
403  cerrado   PDF con firma, LARB
403  cerrado   PDF con firma, CNLJ
403  cerrado   PDF preventivo
403  cerrado   Log de correos
403  cerrado   Log de errores
403  cerrado   Contador UIO
403  cerrado   Listado de uploads

200  ok        Formulario UIO
200  ok        Formulario preventivo

Todo cerrado, y el formulario del técnico sigue en pie.
```

**Los dos formularios tienen que seguir en 200.** Si alguno cae, el script lo
grita y hay que quitar el `.htaccess` de inmediato.

---

## Paso 4 · Probar una orden de verdad

El verificador comprueba el HTTP, no el flujo completo. Antes de darlo por
cerrado, **envía una orden de prueba** desde el formulario y confirma que:

1. Llega el correo.
2. Trae el PDF adjunto y se abre bien.
3. El correlativo avanzó.

Si eso funciona, está cerrado sin efectos colaterales.

---

## Si algo se rompe: cómo deshacerlo

Borra el archivo `.htaccess` de `/ot/produccion/`. El efecto es inmediato, no
hay que reiniciar nada, y todo vuelve al estado anterior.

---

## Lo que esto NO resuelve

Cerrar el acceso web **detiene la exposición hacia adelante**; no borra lo que ya
pudo haberse consultado ni sustituye lo demás:

| Pendiente | Dónde está |
|---|---|
| Los PDFs siguen en el servidor sin cifrar | Purga a 90 días — `t2_4_purga_hostinger.py` |
| Nadie registra quién descargó qué | Etapa 6 del plan |
| `cleanup.php` sigue siendo una URL pública que borra `uploads` | Revisar el cron y borrarlo |
| Falta el contrato de encargo con INDUSTEC | `DECISION_ARQUITECTURA_Y_DATOS.md` §4.2 |
| Falta inscribir el delegado de protección de datos | `BRIEF_DPD_INDUSTECH.md` |
