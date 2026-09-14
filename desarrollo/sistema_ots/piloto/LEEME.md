# El paquete del piloto (T2.14.8)

Lo que INDUSTEC necesita para empezar las pruebas del sistema nuevo con la zona UIO: una hoja por rol, las cuentas, el guion de la semana, cómo reportar fallos y la lista de comprobación previa.

| Archivo | Para quién | Qué es |
|---|---|---|
| [`ANTES_DE_EMPEZAR.md`](ANTES_DE_EMPEZAR.md) | Andrés | Los diez pasos del día anterior, cada uno con su comprobación |
| [`CUENTAS.md`](CUENTAS.md) | Andrés y la administración | Dónde se entra, cómo se entregan las claves, reglas de la sesión. **Sin ninguna clave** |
| [`HOJA_TECNICO.md`](HOJA_TECNICO.md) | Los cinco técnicos de UIO | La app en el celular: bandeja, formulario, repuestos, archivo |
| [`HOJA_JEFE_ZONA.md`](HOJA_JEFE_ZONA.md) | El jefe de zona de UIO | Repartir, validar repuestos, cronograma, reportes de su zona |
| [`HOJA_ADMINISTRACION.md`](HOJA_ADMINISTRACION.md) | La administradora y la dirección | Las tres zonas, veredictos, SAP y KFC, reportes exportables, usuarios, bitácora |
| [`QUE_PROBAR.md`](QUE_PROBAR.md) | Todos | Guion de cinco días, por rol, con el resultado esperado de cada prueba |
| [`COMO_REPORTAR_FALLOS.md`](COMO_REPORTAR_FALLOS.md) | Todos | El canal, los datos que hay que mandar y qué no es un fallo |
| `capturas/` | — | Las pantallas reales del sitio de pruebas que usan las hojas |

## De dónde salen las capturas

Se toman contra el sitio de pruebas con las cuentas de prueba y **sin nombres del personal**:

```bat
cd desarrollo\sistema_ots\app\pruebas\servidor
set INDUSTEC_LLAVE_SSH=C:\Users\andre\.ssh\industec_hostinger_pc
node capturar_pantallas.mjs --piloto --recorte --anonimizar --salida ..\..\..\piloto\capturas
```

`--recorte` guarda solo lo que cabe en la ventana (1400 × 900 en escritorio, 390 × 844 en el celular); `--anonimizar` reemplaza en la página, antes de capturar, los nombres, usuarios y correos del personal real por «Técnico 1», «Jefe de zona 2»… (los lee de la base por SSH y no los escribe en ningún archivo). Requiere `preparar_prueba.php` corrido en el servidor. Se vuelven a tomar cada vez que cambia una pantalla.

## Cómo se entrega

La fuente es esta carpeta, en el repositorio. La copia que se le da a INDUSTEC es `SALIDAS IA\OTS\paquete_piloto_uio\` (fuera de git, como todo `SALIDAS IA`): se copia entera, con `capturas/`, y se entrega por el canal que decida Andrés. Los `.md` se leen en cualquier visor de Markdown; si hace falta en PDF o Word, se convierten desde aquí.

```bat
robocopy desarrollo\sistema_ots\piloto "SALIDAS IA\OTS\paquete_piloto_uio" /MIR /XF LEEME.md
```

## Lo que no está aquí a propósito

- Claves: ninguna, en ningún archivo. Se generan desde `Usuarios` y se muestran una sola vez.
- Nombres del personal: las hojas usan cuentas de prueba y capturas anonimizadas; la lista real de usuarios se imprime desde el sistema el día de la entrega.
- Datos de KFC: los locales y los avisos que aparecen en las capturas son los del sitio de pruebas.
