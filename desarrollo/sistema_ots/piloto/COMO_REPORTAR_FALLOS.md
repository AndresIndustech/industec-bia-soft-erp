# Cómo reportar un fallo durante el piloto

Un fallo reportado con estos datos se arregla el mismo día. Uno reportado como «no me funcionó» toma tres conversaciones para entenderlo.

## Por dónde

- **Canal:** el grupo de WhatsApp del piloto que abre Andrés con el jefe de zona de UIO y la administración. Los técnicos le reportan a su jefe de zona o a la administración, que lo pasan al grupo.
- **Urgente** (no puedo enviar una orden, no puedo entrar, la pantalla está en blanco): llamada a Andrés, y después el mensaje con los datos de abajo.
- **No urgente** (algo se ve mal, un texto confuso, una idea): mensaje en el grupo, cuando se pueda.

Lo que se reporta de palabra y no se escribe se pierde. **Todo va escrito**, aunque sea corto.

## Qué mandar (copiar y llenar)

```
Quién: (tu usuario, no tu clave)
Cuándo: (día y hora, aproximados)
Dónde: (la pantalla: «Mis órdenes», «Asignar», el formulario…, o la dirección que sale arriba)
Qué hice: (paso a paso, en orden)
Qué esperaba que pasara:
Qué pasó en su lugar: (el texto exacto del mensaje de error, si lo hubo)
Caso o número de orden: (el aviso de SAP o la OT-xxxx, si aplica)
Captura de pantalla: (adjunta)
Celular / computador y navegador: (por ejemplo «Android, Chrome» o «PC, Edge»)
¿Tenías señal?: (sí / no / poca)
```

Con eso, y con la **bitácora** del sistema (que registra cada acción con su hora, su usuario y su resultado), casi todo se reconstruye sin volver a preguntar.

## Qué pasa después

| Gravedad | Ejemplo | Compromiso |
|---|---|---|
| **Bloquea** | no se puede enviar una orden; no se puede entrar; un dato se perdió | Se atiende en el momento; si no tiene arreglo inmediato, se indica cómo seguir trabajando (el formulario viejo sigue disponible durante todo el piloto) |
| **Estorba** | un botón no hace lo que dice; una lista sale vacía; un PDF no abre | Corrección el mismo día o el siguiente, desplegada en el sitio de pruebas |
| **Mejora** | un texto poco claro; un paso de más; algo que estaría bien tener | Se anota y se decide con Andrés al cierre de la semana |

Cada fallo recibe una respuesta en el grupo: «reproducido y en arreglo», «arreglado, prueba de nuevo» o «no es un fallo, es así por esto». Los arreglos se anotan en `ESTADO.md` del proyecto con la fecha.

## Lo que no es un fallo (y por qué)

- **La orden no salió al instante.** Sin señal, la app la guarda en el celular y la manda sola cuando vuelve la conexión; en «Mis órdenes» aparece «en cola». No hay que volver a llenarla.
- **No llegó el correo de la orden al local.** En el sitio de pruebas el correo está **retenido a propósito**: ningún correo sale hasta el corte. La orden sí tiene número y PDF.
- **Veo órdenes con número 90xx.** Son las de las pruebas automáticas, anteriores al piloto. Las reales del piloto continúan la numeración del sistema viejo cuando se haga el corte.
- **Me sacó del sistema.** La sesión caduca a las 2 horas sin usarla, o si la misma cuenta entró desde otro aparato.
- **No veo los casos de otra zona en el Buzón.** Es la regla: cada zona ve y gestiona lo suyo. El **Archivo** de órdenes sí es de todas las zonas, solo para consultar.
- **No puedo borrar una orden, un caso ni un documento.** Nada se borra: se corrige, se cancela con motivo o se retira, y queda en la bitácora.

## Lo que sí queremos oír, aunque no sea un fallo

- Un paso que sobra o un dato que se pide dos veces.
- Un diagnóstico pre-redactado que no corresponde a cómo se dice en el taller.
- Un repuesto frecuente que falta en la lista.
- Un equipo que no está en el catálogo de un local (en el formulario: «Equipo nuevo / no está en la lista» lo registra; avisar igual).
- Cualquier cosa que en el formulario viejo era más rápida.
