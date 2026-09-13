# Librerías de la app de OTs

Lo que la app necesita y **no va en la carpeta web**. Desde el 2026-09-13 son cuatro, todas
libres:

| Librería | Para qué | Quién la carga |
|---|---|---|
| **dompdf** | El PDF de la orden (T2.13, la 008) y el PDF de los reportes | `nucleo/Emision.php`, `reporte_exportar.php` |
| **PHPMailer** | El despachador de la cola de correo (T2.14.6): reintentos con espera creciente, 4xx contra 5xx | `despachar_correo_cli.php` |
| **PhpSpreadsheet** | Los reportes en Excel para Grupo KFC (T2.14.5) | `reporte_exportar.php` |
| **PhpPresentation** | Los reportes en PowerPoint (T2.14.5) | `reporte_exportar.php` |

El servidor ya tiene lo que exigen: `zip`, `gd`, `xml`, `mbstring`, `imagick` (medido el
2026-09-12). Si una falta, la pantalla que la usa responde 503 diciendo cuál, no un 500.

## Por qué fuera de la carpeta web

Producción usa dompdf con `isRemoteEnabled` encendido y dentro de la carpeta pública del módulo.
Eso deja que un PDF pida archivos de afuera, y las versiones viejas de dompdf han tenido fallos
graves por esa vía. En la app nueva el PDF se arma sin nada remoto —el logo, las fotos y la firma
van embebidos— y la librería vive donde la web no la alcanza.

## Instalación

**En el sitio de pruebas de Hostinger** va en `~/lib/ot`, junto a `domains/`, no dentro de
`public_html`:

```bash
mkdir -p ~/lib/ot
scp -P 65002 -i <llave> composer.json u671729428@82.25.73.181:lib/ot/
ssh -p 65002 -i <llave> u671729428@82.25.73.181 'cd ~/lib/ot && composer install --no-dev --no-interaction'
```

**En la estación** va aquí mismo: `composer install --no-dev` en esta carpeta. `vendor/` no se
versiona.

`Emision::cargarDompdf()` lo busca en ese orden: primero la clave `dompdf_autoload` de
`nucleo/config.php`, si existe; después `~/lib/ot/vendor/autoload.php`, y por último
`app/lib/vendor/autoload.php`. Si no lo encuentra, la orden **igual queda guardada**: la emisión
anota el motivo en `ot_capturadas.emision_error` y se reintenta en el siguiente envío.
