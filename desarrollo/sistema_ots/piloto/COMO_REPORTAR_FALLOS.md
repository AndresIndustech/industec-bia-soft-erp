# Cómo reportar un fallo durante el piloto

Un fallo reportado con estos datos se arregla el mismo día. Uno reportado como «no me funcionó» toma tres conversaciones para entenderlo.

## Por dónde

- **Canal:** el grupo de WhatsApp del piloto que abre Andrés con el jefe de zona de UIO y la administración. Los técnicos le reportan a su jefe de zona o a la administración, que lo pasan al grupo.
- **Urgente** (no puedo enviar una OT INDUSTEC, no puedo entrar, la pantalla está en blanco): llamada a Andrés, y después el mensaje con los datos de abajo.
- **No urgente** (algo se ve mal, un texto confuso, una idea): mensaje en el grupo, cuando se pueda.

Lo que se reporta de palabra y no se escribe se pierde. **Todo va escrito**, aunque sea corto.

## Qué mandar (copiar y llenar)

```
Quién: (tu usuario, no tu clave)
Cuándo: (día y hora, aproximados)
Dónde: (la pantalla: «Mis órdenes», «Asignación», el formulario de la OT INDUSTEC…, o la dirección que sale arriba)
Qué hice: (paso a paso, en orden)
Qué esperaba que pasara:
Qué pasó en su lugar: (el texto exacto del mensaje de error, si lo hubo)
Orden u OT INDUSTEC: (el aviso SAP o la OT-xxxx, si aplica)
Captura de pantalla: (adjunta)
Celular / computador y navegador: (por ejemplo «Android, Chrome» o «PC, Edge»)
¿Tenías señal?: (sí / no / poca)
```

Con eso, y con la **bitácora** del sistema (que registra cada acción con su hora, su usuario y su resultado), casi todo se reconstruye sin volver a preguntar.

## Qué pasa después

| Gravedad | Ejemplo | Compromiso |
|---|---|---|
| **Bloquea** | no se puede enviar una OT INDUSTEC; no se puede entrar; un dato se perdió | Se atiende en el momento; si no tiene arreglo inmediato, se indica cómo seguir trabajando (el formulario viejo sigue disponible durante todo el piloto) |
| **Estorba** | un botón no hace lo que dice; una lista sale vacía; un PDF no abre | Corrección el mismo día o el siguiente, desplegada en el sitio de pruebas |
| **Mejora** | un texto poco claro; un paso de más; algo que estaría bien tener | Se anota y se decide con Andrés al cierre de la semana |

Cada fallo recibe una respuesta en el grupo: «reproducido y en arreglo», «arreglado, prueba de nuevo» o «no es un fallo, es así por esto». Los arreglos se anotan en `ESTADO.md` del proyecto con la fecha.

## Lo que no es un fallo (y por qué)

- **La OT INDUSTEC no salió al instante.** Sin señal, la app la guarda en el celular y la manda sola cuando vuelve la conexión; mientras tanto aparece «en cola» («enviando» cuando vuelve la señal). Si dice «detenida en el celular», hay que volver a entrar con el usuario. No hay que volver a llenarla.
- **No llegó el correo de la OT INDUSTEC al local.** En el sitio de pruebas el correo está **retenido a propósito**: ningún correo sale hasta el corte. La OT INDUSTEC sí tiene número y PDF.
- **Veo OT INDUSTEC con número 90xx.** Son las de las pruebas automáticas, anteriores al piloto. Las reales del piloto continúan la numeración del sistema viejo cuando se haga el corte.
- **Me sacó del sistema.** La sesión caduca a las 2 horas sin usarla, o si la misma cuenta entró desde otro aparato.
- **No veo las órdenes de otra zona en el Buzón de órdenes.** Es la regla: cada zona ve y gestiona lo suyo. El **Archivo de OT INDUSTEC** sí es de todas las zonas, solo para consultar.
- **La tarjeta «Por zona» no da la misma cifra que SAP o que el STATUS.** Cuenta el buzón de B.IA (las órdenes de los últimos 90 días que llegaron por el correo de SAP), y el correo trae más o menos la mitad de las órdenes abiertas en SAP: no avisa reaperturas ni cierres. Además, el TOTAL DE ÓRDENES ABIERTAS ya no incluye las atendidas, por cerrar en SAP. Sí es un fallo si la cifra de una tarjeta no coincide con las filas que muestra su enlace.
- **Una captura de las hojas dice otra cosa que la pantalla.** Las capturas son del 13 de septiembre de 2026, anteriores al vocabulario único; manda lo que dice la pantalla y el texto de la hoja.
- **No puedo borrar una OT INDUSTEC, una orden ni un documento.** Nada se borra: se corrige, se cancela con motivo o se retira, y queda en la bitácora.

## Lo que sí queremos oír, aunque no sea un fallo

- Un paso que sobra o un dato que se pide dos veces.
- Un diagnóstico pre-redactado que no corresponde a cómo se dice en el taller.
- Un repuesto frecuente que falta en la lista.
- Un equipo que no está en el catálogo de un local (en el formulario: «Equipo nuevo / no está en la lista» lo registra; avisar igual).
- Cualquier cosa que en el formulario viejo era más rápida.
