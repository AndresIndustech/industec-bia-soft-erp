/* =========================================================================
   ui.js — Las piezas de interfaz que comparten todas las pantallas.

   QUE HAY AQUI
     UI.toast()      avisos efímeros: confirman algo que YA pasó
     UI.confirmar()  el diálogo de «¿seguro?» para lo que no se deshace
     contadores      las cifras suben en vez de aparecer, para que se noten
     novedades       la barra de «el buzón cambió», sin recargar por su cuenta
     tiempo relativo  «hace 3 h» calculado en el navegador

   NO HAY UN SOLO FRAMEWORK, Y ES A PROPOSITO. El técnico abre esto en un
   celular de gama baja, con datos móviles y a veces sin señal. Cada kilobyte
   que se descarga es un segundo de pie en la cocina de un local. Todo esto
   pesa menos que el logo.

   REGLA DE REPARTO DE LOS AVISOS, la misma que documenta estilo.css:
     hay que hacer algo  -> aviso fijo en la página (.aviso)
     ya salió bien       -> aviso efímero (toast)
     no se puede deshacer-> diálogo de confirmación
   Un error nunca va en toast: el toast se va y el error hay que leerlo.
   ========================================================================= */
(function (global) {
  'use strict';

  var UI = {};

  /* --- Avisos efímeros ---------------------------------------------------
     Se van solos a los 5 s. Los de error se quedan hasta que se cierren, por
     si aparece uno donde no debía: mejor que estorbe a que se pierda. */
  var ICONO = { ok: '✓', info: 'i', warn: '!', err: '✕' };

  UI.toast = function (texto, tono, opciones) {
    tono = tono || 'info';
    opciones = opciones || {};
    var caja = document.getElementById('toasts');
    if (!caja) {
      // Las pantallas PHP lo traen de Ui::cabecera; el formulario del técnico
      // (index.html) no, y ahí se perdían en silencio el «no se pudo guardar la
      // orden en este celular» y los avisos del recibo. Se crea donde falte.
      caja = document.createElement('div');
      caja.id = 'toasts';
      caja.setAttribute('role', 'status');
      caja.setAttribute('aria-live', 'polite');
      document.body.appendChild(caja);
    }

    var t = document.createElement('div');
    t.className = 'toast ' + tono;
    t.setAttribute('role', tono === 'err' ? 'alert' : 'status');

    var ic = document.createElement('span');
    ic.className = 'ic';
    ic.setAttribute('aria-hidden', 'true');
    ic.textContent = ICONO[tono] || '·';

    var cuerpo = document.createElement('div');
    cuerpo.style.flex = '1';
    cuerpo.style.minWidth = '0';
    cuerpo.textContent = texto;

    var x = document.createElement('button');
    x.className = 'cerrar';
    x.type = 'button';
    x.setAttribute('aria-label', 'Cerrar');
    x.textContent = '×';
    x.addEventListener('click', function () { quitar(t); });

    t.appendChild(ic); t.appendChild(cuerpo); t.appendChild(x);
    caja.appendChild(t);

    var vida = opciones.vida != null ? opciones.vida : (tono === 'err' ? 0 : 5000);
    if (vida > 0) { setTimeout(function () { quitar(t); }, vida); }
    return t;
  };

  function quitar(t) {
    if (!t || !t.parentNode) { return; }
    t.classList.add('saliendo');
    setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 220);
  }

  /* --- Confirmación ------------------------------------------------------
     Para lo que no se deshace. `confirm()` del navegador no se puede redactar
     y en el celular sale como una alerta del sistema, que la gente descarta
     sin leer. Este dice qué va a pasar y quién lo va a ver. */
  UI.confirmar = function (opciones, alAceptar) {
    var d = document.createElement('dialog');
    d.innerHTML =
      '<h2></h2><p class="sub"></p>' +
      '<div class="row" style="margin-top:16px;gap:8px">' +
      '<button class="btn primary" value="si" type="button"></button>' +
      '<button class="btn" value="no" type="button">Cancelar</button></div>';
    d.querySelector('h2').textContent = opciones.titulo || '¿Confirmas?';
    d.querySelector('p').textContent = opciones.detalle || '';
    var ok = d.querySelector('button[value=si]');
    ok.textContent = opciones.ok || 'Confirmar';
    if (opciones.peligro) { ok.className = 'btn danger'; }

    ok.addEventListener('click', function () { d.close(); alAceptar(); });
    d.querySelector('button[value=no]').addEventListener('click', function () { d.close(); });
    d.addEventListener('close', function () { d.remove(); });
    document.body.appendChild(d);
    d.showModal();
  };

  /* --- Contadores --------------------------------------------------------
     La cifra sube desde cero. No es adorno: cuando la administradora recarga y
     «con alerta» pasa de 3 a 11, el movimiento se lo dice; el número quieto,
     no. Se salta si son cifras grandes o si pidió menos movimiento. */
  var quieto = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;

  UI.contar = function (el) {
    var fin = parseInt(el.getAttribute('data-n'), 10);
    if (isNaN(fin)) { return; }
    if (quieto || fin > 5000) { el.textContent = fin.toLocaleString('es-EC'); return; }
    var t0 = null, dur = Math.min(220 + fin * 6, 900);
    function paso(ts) {
      if (t0 === null) { t0 = ts; }
      var p = Math.min((ts - t0) / dur, 1);
      // Desaceleración: arranca rápido y frena, que es como se lee un número.
      var v = Math.round(fin * (1 - Math.pow(1 - p, 3)));
      el.textContent = v.toLocaleString('es-EC');
      if (p < 1) { requestAnimationFrame(paso); }
    }
    requestAnimationFrame(paso);
  };

  /* --- Tiempo relativo ---------------------------------------------------
     «hace 3 h» se entiende de un vistazo; «2026-09-09 11:20» hay que restarlo
     mentalmente. Las fechas llegan del servidor en hora de Ecuador (Db.php
     fija la zona de la base y la de PHP) y sin zona escrita, así que el
     navegador las lee como hora local: cuadra en un celular de Ecuador. Hasta
     el 2026-09-11 llegaban en UTC y esto salía corrido cinco horas. */
  UI.hace = function (iso) {
    var t = Date.parse((iso || '').replace(' ', 'T'));
    if (isNaN(t)) { return ''; }
    var s = (Date.now() - t) / 1000;
    if (s < 60) { return 'recién'; }
    if (s < 3600) { return 'hace ' + Math.floor(s / 60) + ' min'; }
    if (s < 86400) { return 'hace ' + Math.floor(s / 3600) + ' h'; }
    var d = Math.floor(s / 86400);
    if (d < 30) { return 'hace ' + d + (d === 1 ? ' día' : ' días'); }
    return new Date(t).toLocaleDateString('es-EC');
  };

  /* --- Vocabulario único -------------------------------------------------
     Las palabras con que se nombra cada estado salen de `vocabulario.json`,
     el mismo archivo que leen Vocabulario.php y comun.py: una palabra, un
     significado, para todos los roles (decisión del 24-sep-2026). El código
     pide por CLAVE de concepto, nunca escribe el texto:
       UI.T('ORDEN')          -> 'orden'
       UI.T('ORDEN', 3)       -> 'órdenes'   (plural también con 0)
       UI.T.titulo('ABIERTA') -> 'ÓRDENES ABIERTAS'
       UI.T.deEstado('ASIGNADO')            -> 'ASIGNADA'
       UI.T.deEstado('amarillo', 'semaforo') -> 'LE_TOCA_INDUSTEC'

     POR QUE HAY UN RESPALDO EMBEBIDO: UI.T tiene que contestar ya, al pintar,
     y el celular abre la app sin señal. El respaldo es el JSON recortado a lo
     que la pantalla usa; lo escribe app/herramientas/generar_vocabulario.php y
     prueba_vocabulario.php falla si se desvía. Cuando llega vocabulario.json
     (sw.js lo deja precargado) reemplaza al respaldo tal cual: tienen la misma
     forma.

     Una clave que no existe LANZA un error con la clave y la versión (I-7):
     devolver la clave cruda es como aparecía ASIGNADO en mayúsculas delante
     del cliente. */
  // <vocabulario:RESPALDO> Generado desde vocabulario.json por app/herramientas/generar_vocabulario.php. No se edita a mano.
  var RESPALDO = {
    "version": "2026-09-24.3",
    "conceptos": {
      "ORDEN": {"termino":"orden","plural":"órdenes","titulo":"Órdenes","ayuda":"El trabajo que pide Grupo KFC, identificado por su aviso SAP; es lo que se cuenta en todos los tableros."},
      "AVISO_SAP": {"termino":"aviso SAP","plural":"avisos SAP","titulo":"Aviso SAP","ayuda":"El número que SAP le da a la orden de KFC (10… u 0000…); solo identifica la orden, no es un estado."},
      "OT_INDUSTEC": {"termino":"OT INDUSTEC","plural":"OT INDUSTEC","titulo":"OT INDUSTEC","corto":"OT","ayuda":"El documento que emite el técnico por una orden, de evaluación o de cierre, con número OT-NNNN; es el que llega a KFC en PDF y por correo."},
      "OT_EVALUACION": {"termino":"OT INDUSTEC de evaluación","plural":"OT INDUSTEC de evaluación","titulo":"OT INDUSTEC de evaluación","ayuda":"OT INDUSTEC emitida con «Estado de OT: Abierta»: hubo visita y falta el cierre."},
      "OT_CIERRE": {"termino":"OT INDUSTEC de cierre","plural":"OT INDUSTEC de cierre","titulo":"OT INDUSTEC de cierre","ayuda":"OT INDUSTEC emitida con «Estado de OT: Cerrada»: INDUSTEC dio el trabajo por terminado."},
      "OT_NO_EMITIDA": {"termino":"OT INDUSTEC no emitida","plural":"OT INDUSTEC no emitidas","titulo":"OT INDUSTEC no emitida","ayuda":"Se numeró o se capturó, pero no salió el PDF ni el correo (NUMERADA o FALLIDA): no cuenta como OT emitida."},
      "ENVIO_OT": {"termino":"envío de la OT INDUSTEC","plural":"envíos de OT INDUSTEC","titulo":"Envío de la OT","ayuda":"Lo que pasó con el PDF y el correo de una OT INDUSTEC: en cola, enviada, no enviada o rechazada."},
      "INFORME_DETALLADO": {"termino":"informe técnico detallado","plural":"informes técnicos detallados","titulo":"Informe técnico detallado","ayuda":"Documento aparte que se adjunta en el hilo de la OT INDUSTEC para una baja, una garantía o una solicitud de material."},
      "SIN_AVISO_SAP": {"termino":"OT INDUSTEC sin aviso SAP","plural":"OT INDUSTEC sin aviso SAP","titulo":"Sin aviso SAP","ayuda":"OT INDUSTEC emitida para un trabajo que todavía no tiene aviso en SAP; hay que pedirle el aviso a KFC."},
      "SIN_ASIGNAR": {"termino":"sin asignar","plural":"sin asignar","titulo":"Sin asignar","ayuda":"Orden que llegó del correo de SAP y todavía no tiene técnico."},
      "ASIGNADA": {"termino":"asignada","plural":"asignadas","titulo":"Asignadas","ayuda":"Orden que ya tiene técnico."},
      "ASIGNADA_3D": {"termino":"asignada hace 3+ días, a espera de informe técnico","plural":"asignadas hace 3+ días, a espera de informe técnico","titulo":"Asignadas hace 3+ días","ayuda":"Orden asignada hace 3 días o más que todavía no tiene ninguna OT INDUSTEC emitida."},
      "EN_REVISION": {"termino":"en revisión","plural":"en revisión","titulo":"En revisión","ayuda":"El jefe de zona mandó la orden a la administración con un motivo; la administración la resuelve."},
      "RESOLUCION_ADMIN": {"termino":"resolución de la administración","plural":"resoluciones de la administración","titulo":"Resolución","ayuda":"Lo que resuelve la administración sobre una orden en revisión: nos compete (sigue o ya está cerrada en SAP) o no nos compete."},
      "ESPERA_REPUESTO": {"termino":"a espera de repuesto","plural":"a espera de repuesto","titulo":"A espera de repuesto","ayuda":"El técnico ya fue o diagnosticó, y el trabajo depende de un repuesto, un taller, una garantía o una baja."},
      "ATENDIDA": {"termino":"atendida, por cerrar en SAP","plural":"atendidas, por cerrar en SAP","titulo":"Atendidas, por cerrar en SAP","ayuda":"INDUSTEC terminó su parte (OT INDUSTEC de cierre o enlace de continuidad); falta que la administración la cierre en SAP."},
      "CERRADA_SAP": {"termino":"cerrada en SAP","plural":"cerradas en SAP","titulo":"Cerradas en SAP","ayuda":"La administración confirmó que la orden ya está cerrada en SAP; es el único cierre que cuenta KFC."},
      "NO_COMPETE": {"termino":"no nos compete","plural":"no nos competen","titulo":"No nos compete","ayuda":"La administración resolvió que no es trabajo de INDUSTEC; se cierra y se pide a KFC que la derive."},
      "CERRADA_SIN_ATENCION": {"termino":"cerrada sin atención","plural":"cerradas sin atención","titulo":"Cerradas sin atención","ayuda":"Pasó una semana sin ninguna OT INDUSTEC y la orden se cerró; hay que explicarlo ante KFC."},
      "SIN_REGULARIZAR": {"termino":"cerrada sin atención, sin regularizar ante KFC","plural":"cerradas sin atención, sin regularizar ante KFC","titulo":"Sin regularizar","ayuda":"Orden cerrada sin atención que la administración todavía no ha explicado ante KFC."},
      "REGULARIZADA": {"termino":"regularizada","plural":"regularizadas","titulo":"Regularizadas","ayuda":"Se cerró sin atención y la administración ya lo explicó ante KFC."},
      "CERRADA_SIN_OK": {"termino":"cerrada por falta de OK","plural":"cerradas por falta de OK","titulo":"Cerradas por falta de OK","ayuda":"La administración cerró la orden sin ejecutarla porque Operaciones de KFC no dio el OK."},
      "ABIERTA": {"termino":"abierta","plural":"abiertas","titulo":"ÓRDENES ABIERTAS","ayuda":"Orden que ya tiene OT INDUSTEC de evaluación emitida (hubo visita) y le falta la de cierre; incluye siempre las que están a espera de repuesto."},
      "ESPERA_INFORME": {"termino":"a espera de informe técnico","plural":"a espera de informe técnico","titulo":"ÓRDENES A ESPERA DE INFORME TÉCNICO","ayuda":"Orden abierta que todavía no tiene ninguna OT INDUSTEC emitida: está sin asignar o asignada sin visita reportada."},
      "TOTAL_ABIERTAS": {"termino":"total de órdenes abiertas","plural":"total de órdenes abiertas","titulo":"TOTAL DE ÓRDENES ABIERTAS","ayuda":"Todas las órdenes que INDUSTEC aún no termina (ÓRDENES ABIERTAS + ÓRDENES A ESPERA DE INFORME TÉCNICO); es la vista del buzón de B.IA, no la cifra de SAP."},
      "TOTAL_GENERAL": {"termino":"total general de órdenes abiertas","plural":"total general de órdenes abiertas","titulo":"TOTAL GENERAL DE ÓRDENES ABIERTAS","ayuda":"Suma del total de órdenes abiertas de las tres zonas (UIO, LARB y CUENCA-LOJA), como el «TOTAL ORDENES» del STATUS del martes; no incluye OTRA ni las órdenes sin zona."},
      "FUERA_CATALOGO": {"termino":"con más de 90 días (fuera del catálogo)","plural":"con más de 90 días (fuera del catálogo)","titulo":"Con más de 90 días","ayuda":"Orden que salió de la ventana de 90 días del buzón pero sigue abierta en B.IA; puede incluir órdenes que KFC eliminó, porque el servidor todavía no recibe esa lista."},
      "ORDENES_NUEVAS_7D": {"termino":"orden nueva en 7 días","plural":"órdenes nuevas en 7 días","titulo":"Órdenes nuevas en 7 días","ayuda":"Órdenes que llegaron del correo de SAP en los últimos 7 días, en cualquier estado."},
      "FECHA_SAP_HOY": {"termino":"con fecha SAP hoy","plural":"con fecha SAP hoy","titulo":"Con fecha SAP hoy","ayuda":"Órdenes cuya fecha comprometida en SAP es hoy."},
      "EQUIPO_DESHABILITADO": {"termino":"equipo deshabilitado","plural":"equipos deshabilitados","titulo":"EQUIPOS DESHABILITADOS","ayuda":"Equipo cuyo último estado registrado es Deshabilitado; en los conteos se cuenta una vez por orden, como lo cuenta Isabel."},
      "EQUIPO_OPERATIVO": {"termino":"equipo operativo","plural":"equipos operativos","titulo":"Operativos","ayuda":"Equipo cuyo último estado registrado es Operativo."},
      "SIN_DATO_EQUIPO": {"termino":"sin dato del equipo","plural":"sin dato del equipo","titulo":"Sin dato del equipo","ayuda":"Ninguna OT INDUSTEC ni solicitud dice todavía cómo quedó el equipo; no se toma como operativo."},
      "SOLICITUD": {"termino":"solicitud","plural":"solicitudes","titulo":"Solicitud","ayuda":"Registro que abre el técnico cuando el equipo no quedó operativo: pide repuesto, taller, garantía o baja con su diagnóstico, y corre el plazo de 48 h."},
      "MODULO_REPUESTOS": {"termino":"repuestos y equipos","plural":"repuestos y equipos","titulo":"Repuestos y equipos","corto":"Repuestos","ayuda":"Módulo donde se siguen las solicitudes de los equipos que no quedaron operativos."},
      "SOLICITUD_EN_TRAMITE": {"termino":"solicitud en trámite","plural":"solicitudes en trámite","titulo":"En trámite","ayuda":"Solicitud que todavía no está terminada ni cancelada."},
      "POR_VALIDAR": {"termino":"por validar","plural":"por validar","titulo":"Por validar","ayuda":"El técnico pidió el repuesto con su diagnóstico y corre el plazo de 48 h para que el jefe de zona lo valide."},
      "VENCIDO_48H": {"termino":"vencida (más de 48 h sin validar)","plural":"vencidas (más de 48 h sin validar)","titulo":"Vencidas (48 h)","ayuda":"Solicitud con el equipo deshabilitado que lleva más de 48 horas sin que el jefe de zona la valide."},
      "VALIDADA": {"termino":"validada por el jefe de zona","plural":"validadas por el jefe de zona","titulo":"Validadas","ayuda":"El jefe de zona confirmó el diagnóstico y la vía de la solicitud; se mide si lo hizo dentro de las 48 h."},
      "POR_REGISTRAR_SAP": {"termino":"validada, por registrar en SAP","plural":"validadas, por registrar en SAP","titulo":"Por registrar en SAP","ayuda":"El jefe de zona ya validó la solicitud; falta que la administración la registre en SAP."},
      "PENDIENTE_OK_OPS": {"termino":"pendiente OK de OP´S","plural":"pendientes OK de OP´S","titulo":"PENDIENTE OK OP´S","ayuda":"La solicitud ya está registrada en SAP y se espera la aprobación de Operaciones de KFC."},
      "REPUESTO_DESPACHADO": {"termino":"repuesto despachado","plural":"repuestos despachados","titulo":"Repuesto despachado","ayuda":"KFC decidió enviar la pieza y la despachó; falta que llegue al local."},
      "REPUESTO_EN_LOCAL": {"termino":"repuesto en el local","plural":"repuestos en el local","titulo":"Repuesto en el local","ayuda":"La pieza ya está en el local, en manos del técnico; falta montarla."},
      "EN_TALLER_INDUSTEC": {"termino":"en taller de INDUSTEC","plural":"en taller de INDUSTEC","titulo":"En taller de INDUSTEC","ayuda":"KFC decidió que el equipo se repare en los talleres de INDUSTEC y está allá."},
      "DEVUELTO_TALLER": {"termino":"de vuelta del taller","plural":"de vuelta del taller","titulo":"De vuelta del taller","ayuda":"El equipo regresó reparado; falta montarlo."},
      "CON_OTRO_PROVEEDOR": {"termino":"con otro proveedor","plural":"con otro proveedor","titulo":"Con otro proveedor","ayuda":"KFC mandó el equipo a otro proveedor; el seguimiento queda fuera de INDUSTEC."},
      "BAJA_APROBADA": {"termino":"baja aprobada por KFC","plural":"bajas aprobadas por KFC","titulo":"Baja aprobada por KFC","ayuda":"KFC aceptó dar de baja el equipo; falta ejecutarla."},
      "SOLICITUD_TERMINADA": {"termino":"terminada","plural":"terminadas","titulo":"Terminadas","ayuda":"El equipo volvió a operar o su baja quedó ejecutada: la solicitud ya no espera nada."},
      "SOLICITUD_CANCELADA": {"termino":"cancelada (no procedía)","plural":"canceladas (no procedía)","titulo":"Canceladas","ayuda":"La solicitud no procedía y se resolvió de otra forma."},
      "SOLICITUD_HEREDADA": {"termino":"trámite anterior a la 009","plural":"trámites anteriores a la 009","titulo":"Trámite anterior","ayuda":"Solicitud abierta con el modelo de antes de la 009 (cuando INDUSTEC compraba la pieza): se muestra con su paso de entonces y se deja avanzar, pero a una nueva ya no se le ofrece."},
      "VIA": {"termino":"vía propuesta","plural":"vías propuestas","titulo":"Vía","ayuda":"Lo que el técnico propone para el equipo y el jefe de zona valida: repuesto, reparación en taller, garantía o baja."},
      "VIA_REPUESTO": {"termino":"vía de repuesto","plural":"vías de repuesto","titulo":"Repuesto","ayuda":"Se pide la pieza a Grupo KFC por requerimiento SAP; es la vía más frecuente."},
      "VIA_REPARACION": {"termino":"reparación en taller","plural":"reparaciones en taller","titulo":"Reparación en taller","ayuda":"Se pide que el equipo salga a reparación."},
      "VIA_GARANTIA": {"termino":"garantía","plural":"garantías","titulo":"Garantía","ayuda":"Le toca al fabricante o al proveedor, sin costo para el cliente."},
      "VIA_BAJA": {"termino":"baja","plural":"bajas","titulo":"Baja","ayuda":"El equipo no tiene arreglo razonable; se propone a Grupo KFC con el informe técnico detallado."},
      "DECISION_KFC": {"termino":"decisión de KFC","plural":"decisiones de KFC","titulo":"Decisión de KFC","ayuda":"Lo que Grupo KFC decide sobre una solicitud registrada en SAP: envía el repuesto, a taller de INDUSTEC, a otro proveedor o da de baja el equipo."},
      "KFC_SIN_DECISION": {"termino":"sin decisión de Grupo KFC todavía","plural":"sin decisión de Grupo KFC todavía","titulo":"Sin decisión de KFC","ayuda":"KFC todavía no responde a la solicitud registrada en SAP."},
      "KFC_ENVIA_REPUESTO": {"termino":"envía el repuesto","plural":"envía el repuesto","titulo":"Envía el repuesto","ayuda":"KFC decidió mandar la pieza."},
      "KFC_A_TALLER": {"termino":"a taller de INDUSTEC","plural":"a taller de INDUSTEC","titulo":"A taller de INDUSTEC","ayuda":"KFC decidió que el equipo se repare en los talleres de INDUSTEC."},
      "KFC_A_OTRO_PROVEEDOR": {"termino":"a otro proveedor","plural":"a otro proveedor","titulo":"A otro proveedor","ayuda":"KFC decidió mandar el equipo a otro proveedor."},
      "KFC_DA_DE_BAJA": {"termino":"da de baja el equipo","plural":"da de baja el equipo","titulo":"Da de baja el equipo","ayuda":"KFC decidió dar de baja el equipo."},
      "MIS_ORDENES": {"termino":"mis órdenes","plural":"mis órdenes","titulo":"Mis órdenes","ayuda":"Las órdenes asignadas a ti que siguen en tus manos: asignadas y a espera de repuesto."},
      "HISTORIAL": {"termino":"historial","plural":"historial","titulo":"Historial","ayuda":"Órdenes que ya no están en tus manos: atendidas, cerradas en SAP o que no nos competen."},
      "NOTIFICACION": {"termino":"notificación","plural":"notificaciones","titulo":"Notificaciones","ayuda":"Mensaje interno de B.IA para ti: te asignaron una orden, te respondieron, validaron tu solicitud."},
      "CONTINUIDAD": {"termino":"continúa la orden","plural":"continúan la orden","titulo":"Continúa la orden","ayuda":"Orden nueva que es el mismo trabajo de una anterior; se enlaza y se cuenta una sola vez."},
      "OTRO_TRABAJO": {"termino":"otro trabajo","plural":"otros trabajos","titulo":"Otros trabajos","ayuda":"Trabajo fuera del contrato de correctivos que la administración marcó como extra, autorizado o no."},
      "OTRO_TRABAJO_POR_DECIDIR": {"termino":"fuera del área, por decidir","plural":"fuera del área, por decidir","titulo":"Fuera del área, por decidir","ayuda":"Orden que parece no ser de INDUSTEC (alerta de alcance) y espera que la administración la resuelva."},
      "EQUIPO_PROPUESTO": {"termino":"equipo nuevo por confirmar","plural":"equipos nuevos por confirmar","titulo":"Equipos nuevos por confirmar","ayuda":"Equipo que un técnico registró porque no estaba en el maestro; la administración lo confirma."},
      "DOCUMENTO_POR_APROBAR": {"termino":"por aprobar","plural":"por aprobar","titulo":"Por aprobar","ayuda":"Documento de Aprendizaje que espera la aprobación de la administración."},
      "NOVEDAD": {"termino":"novedad","plural":"novedades","titulo":"Novedades","ayuda":"Algo que el técnico vio en el local y que puede hacer fallar equipos (instalación, agua, gas, obra civil…); no es una orden."},
      "NOVEDAD_REPORTADA": {"termino":"reportada","plural":"reportadas","titulo":"Reportadas","ayuda":"El técnico la registró y nadie la ha revisado."},
      "NOVEDAD_EN_ESTUDIO": {"termino":"en estudio","plural":"en estudio","titulo":"En estudio","ayuda":"Alguien la está mirando."},
      "NOVEDAD_CON_AVISO": {"termino":"con aviso SAP","plural":"con aviso SAP","titulo":"Con aviso SAP","ayuda":"Se le pidió el aviso a Grupo KFC y ya tiene número."},
      "NOVEDAD_ASUMIDA": {"termino":"la asume INDUSTEC","plural":"las asume INDUSTEC","titulo":"La asume INDUSTEC","ayuda":"Entra en la planificación de INDUSTEC sin aviso nuevo."},
      "NOVEDAD_DESCARTADA": {"termino":"descartada","plural":"descartadas","titulo":"Descartadas","ayuda":"Se revisó y no procedía, con su motivo."},
      "NOVEDAD_RESUELTA": {"termino":"resuelta","plural":"resueltas","titulo":"Resueltas","ayuda":"La novedad ya se atendió."},
      "NOVEDAD_POR_DECIDIR": {"termino":"por decidir","plural":"por decidir","titulo":"Por decidir","ayuda":"Novedad reportada o en estudio que todavía no tiene destino."},
      "ESTATUS_SAP": {"termino":"estatus SAP","plural":"estatus SAP","titulo":"Estatus SAP","ayuda":"Lo que dice SAP de la orden (ABIERTO, TRATAMIENTO o CERRADO en el libro de KFC); el servidor de B.IA no lo conoce, solo la estación con el export del lunes."},
      "ND_SIN_PROVEEDOR": {"termino":"orden N/D (sin proveedor)","plural":"órdenes N/D (sin proveedor)","titulo":"Órdenes N/D","ayuda":"Orden correctiva de KFC que todavía no tiene ningún proveedor en SAP (hoja #O_ND)."},
      "ATRASADO": {"termino":"atrasado","plural":"atrasados","titulo":"Atrasado","ayuda":"Pasó la fecha comprometida (del ingreso preventivo, del compromiso de fecha o la fecha comprometida en SAP) sin que se ejecutara."},
      "PREV_EJECUTADO": {"termino":"ejecutado","plural":"ejecutados","titulo":"Ejecutado","ayuda":"Ingreso preventivo marcado como ejecutado, con su fecha real."},
      "PREV_PENDIENTE": {"termino":"pendiente","plural":"pendientes","titulo":"Pendiente","ayuda":"Ingreso preventivo con fecha que todavía no le toca."},
      "PREV_POR_INICIAR": {"termino":"pendiente, arranca en 3 días o menos","plural":"pendientes, arrancan en 3 días o menos","titulo":"Pendiente · arranca en 3 días o menos","ayuda":"Ingreso preventivo pendiente que arranca dentro de 3 días o menos."},
      "PREV_SIN_AGENDAR": {"termino":"pendiente, sin agendar","plural":"pendientes, sin agendar","titulo":"Pendiente · sin agendar","ayuda":"Ingreso preventivo que todavía no tiene fecha; hay que agendarlo."},
      "PREV_EN_EJECUCION": {"termino":"pendiente, en ejecución","plural":"pendientes, en ejecución","titulo":"Pendiente · en ejecución","ayuda":"Ingreso preventivo que ya empezó y todavía está dentro de la fecha prevista."},
      "PREV_SIN_CIERRE": {"termino":"atrasado, por marcar como ejecutado","plural":"atrasados, por marcar como ejecutados","titulo":"Atrasado · por marcar como ejecutado","ayuda":"Ingreso preventivo con OT INDUSTEC emitidas cuya fecha prevista ya pasó y que nadie marcó como ejecutado."},
      "PREV_CANCELADO": {"termino":"cancelado del cronograma","plural":"cancelados del cronograma","titulo":"Cancelado del cronograma","ayuda":"Ingreso que se dio de baja del cronograma del año; no cuenta en el cumplimiento."},
      "PREV_REAGENDADO": {"termino":"reagendado","plural":"reagendados","titulo":"Reagendados","ayuda":"Ingreso cuya fecha vigente es distinta de la del plan original; es una marca, no un estado."},
      "PREV_MOVIMIENTO": {"termino":"movimiento del ingreso","plural":"movimientos del ingreso","titulo":"Movimientos del ingreso","ayuda":"Lo que pasó con un ingreso preventivo (agenda, reagenda, kit, marca de ejecutado o nota), con su fecha y quién lo hizo; no es una novedad del local."},
      "LE_TOCA_INDUSTEC": {"termino":"le toca a INDUSTEC","plural":"le toca a INDUSTEC","titulo":"Responsable: INDUSTEC","ayuda":"La orden espera algo de INDUSTEC: asignarla, visitarla o emitir su OT INDUSTEC."},
      "LE_TOCA_KFC": {"termino":"le toca a KFC","plural":"le toca a KFC","titulo":"Responsable: KFC","ayuda":"La orden espera una respuesta de KFC (administrador u Operaciones)."},
      "LE_TOCA_KFC_REPUESTO": {"termino":"le toca a KFC: repuesto en SAP","plural":"le toca a KFC: repuesto en SAP","titulo":"Responsable: KFC (repuesto en SAP)","ayuda":"La orden espera el repuesto que ya se pidió a KFC en SAP (bodega o importación)."},
      "EMERGENTE": {"termino":"emergente (más de 7 días)","plural":"emergentes (más de 7 días)","titulo":"Emergente","ayuda":"La orden lleva más de 7 días sin moverse y hay que atenderla ya."},
      "ZONA_UIO": {"termino":"zona UIO","plural":"zona UIO","titulo":"ZONA UIO","corto":"UIO","ayuda":"Quito y sus alrededores."},
      "ZONA_LARB": {"termino":"zona LARB","plural":"zona LARB","titulo":"ZONA LARB","corto":"LARB","ayuda":"Latacunga, Ambato y Riobamba."},
      "ZONA_CNLJ": {"termino":"zona Cuenca-Loja","plural":"zona Cuenca-Loja","titulo":"ZONA CUENCA-LOJA","corto":"CUENCA-LOJA","ayuda":"Cuenca y Loja."},
      "ZONA_OTRA": {"termino":"otra zona","plural":"otras zonas","titulo":"OTRA ZONA","corto":"OTRA","ayuda":"Local fuera de las tres zonas contratadas."},
      "SIN_ZONA": {"termino":"sin zona","plural":"sin zona","titulo":"SIN ZONA","ayuda":"Orden cuyo local no calza con ningún local del maestro; no entra en ninguna tarjeta hasta que se corrija."}
    },
    "estados_caso": {"NUEVO":"SIN_ASIGNAR","ASIGNADO":"ASIGNADA","EN_REVISION":"EN_REVISION","ESPERA_REPUESTO":"ESPERA_REPUESTO","ATENDIDO":"ATENDIDA","RESUELTO":"CERRADA_SAP","NO_COMPETE":"NO_COMPETE","CERRADO_SIN_ATENCION":"CERRADA_SIN_ATENCION","REGULARIZADO":"REGULARIZADA"},
    "estados_pendiente": {"SIN_VEREDICTO":"SOLICITUD_HEREDADA","COTIZANDO":"SOLICITUD_HEREDADA","COMPRADO":"SOLICITUD_HEREDADA","EN_BODEGA":"SOLICITUD_HEREDADA","EN_TALLER":"SOLICITUD_HEREDADA","GARANTIA_RECLAMADA":"SOLICITUD_HEREDADA","GARANTIA_APROBADA":"SOLICITUD_HEREDADA","GARANTIA_NEGADA":"SOLICITUD_HEREDADA","BAJA_PROPUESTA":"SOLICITUD_HEREDADA","ENTREGADO":"REPUESTO_EN_LOCAL","DEVUELTO_TALLER":"DEVUELTO_TALLER","BAJA_APROBADA":"BAJA_APROBADA","RESUELTO":"SOLICITUD_TERMINADA","CANCELADO":"SOLICITUD_CANCELADA","SOLICITADO":"POR_VALIDAR","VALIDADO_JEFE":"POR_REGISTRAR_SAP","REGISTRADO_SAP":"PENDIENTE_OK_OPS","ESPERA_KFC":"PENDIENTE_OK_OPS","REPUESTO_ENVIADO":"REPUESTO_DESPACHADO","TALLER_INDUSTEC":"EN_TALLER_INDUSTEC","OTRO_PROVEEDOR":"CON_OTRO_PROVEEDOR"},
    "estados_novedad": {"REPORTADA":"NOVEDAD_REPORTADA","EN_REVISION":"NOVEDAD_EN_ESTUDIO","DERIVADA_SAP":"NOVEDAD_CON_AVISO","ASUMIDA_INDUSTEC":"NOVEDAD_ASUMIDA","DESCARTADA":"NOVEDAD_DESCARTADA","RESUELTA":"NOVEDAD_RESUELTA"},
    "preventivo": {"estados":{"CUMPLIDO":"PREV_EJECUTADO","PLANIFICADO":"PREV_PENDIENTE","PORINICIAR":"PREV_POR_INICIAR","SINAGENDAR":"PREV_SIN_AGENDAR","ENCURSO":"PREV_EN_EJECUCION","SINCERRAR":"PREV_SIN_CIERRE","VENCIDO":"ATRASADO","CANCELADO":"PREV_CANCELADO"}},
    "via": {"REPUESTO":"VIA_REPUESTO","REPARACION":"VIA_REPARACION","GARANTIA":"VIA_GARANTIA","BAJA":"VIA_BAJA"},
    "decision_kfc": {"PENDIENTE":"KFC_SIN_DECISION","REPUESTO_ENVIADO":"KFC_ENVIA_REPUESTO","TALLER_INDUSTEC":"KFC_A_TALLER","OTRO_PROVEEDOR":"KFC_A_OTRO_PROVEEDOR","BAJA":"KFC_DA_DE_BAJA"},
    "estado_ot": {"ABIERTA":"OT_EVALUACION","CERRADA":"OT_CIERRE"},
    "estado_equipo": {"OPERATIVO":"EQUIPO_OPERATIVO","DESHABILITADO":"EQUIPO_DESHABILITADO","":"SIN_DATO_EQUIPO"},
    "zona": {"UIO":"ZONA_UIO","LARB":"ZONA_LARB","CNLJ":"ZONA_CNLJ","OTRA":"ZONA_OTRA","":"SIN_ZONA"},
    "semaforo": {"AMARILLO":"LE_TOCA_INDUSTEC","NARANJA":"LE_TOCA_KFC","VERDE":"LE_TOCA_KFC_REPUESTO","ROJO":"EMERGENTE"}
  };
  // </vocabulario:RESPALDO>
  var VOC = RESPALDO;

  // Dónde está el mapa estado de la base -> concepto de cada dominio. El
  // mismo cuadro que Vocabulario::DOMINIOS y DOMINIOS de comun.py.
  var DOMINIOS = {
    caso: ['estados_caso'], pendiente: ['estados_pendiente'], novedad: ['estados_novedad'],
    preventivo: ['preventivo', 'estados'], via: ['via'], decision_kfc: ['decision_kfc'],
    estado_ot: ['estado_ot'], estado_equipo: ['estado_equipo'], zona: ['zona'], semaforo: ['semaforo']
  };

  function vocError(que) {
    return new Error('Vocabulario: ' + que + ' (versión ' + VOC.version + ')');
  }
  function concepto(clave) {
    var c = Object.prototype.hasOwnProperty.call(VOC.conceptos, clave) ? VOC.conceptos[clave] : null;
    if (!c) { throw vocError('la clave «' + clave + '» no existe en vocabulario.json'); }
    return c;
  }
  function campo(clave, nombre) {
    var v = concepto(clave)[nombre];
    if (typeof v !== 'string' || v === '') {
      throw vocError('la clave «' + clave + '» no tiene «' + nombre + '»');
    }
    return v;
  }

  UI.T = function (clave, n) {
    var k = (n === undefined || n === null) ? 1 : Number(n);
    return campo(clave, k === 1 ? 'termino' : 'plural');
  };
  UI.T.titulo = function (clave) { return campo(clave, 'titulo'); };
  UI.T.ayuda  = function (clave) { return campo(clave, 'ayuda'); };
  /* La forma corta (barra del técnico, chip de zona); si el concepto no la
     trae, el término, como manda la regla «corto» del diccionario. */
  UI.T.corto  = function (clave) {
    var c = concepto(clave).corto;
    return (typeof c === 'string' && c !== '') ? c : UI.T(clave);
  };
  UI.T.deEstado = function (estado, dominio) {
    dominio = dominio || 'caso';
    var camino = DOMINIOS[dominio];
    if (!camino) { throw vocError('el dominio «' + dominio + '» no existe'); }
    var mapa = VOC;
    camino.forEach(function (p) { mapa = mapa ? mapa[p] : null; });
    var e = String(estado == null ? '' : estado).trim().toUpperCase();
    var clave = (mapa && Object.prototype.hasOwnProperty.call(mapa, e)) ? mapa[e] : null;
    if (typeof clave !== 'string' || clave === '') {
      throw vocError('el estado «' + e + '» del dominio «' + dominio + '» no tiene concepto');
    }
    return clave;
  };
  UI.T.version = function () { return VOC.version; };

  /* El archivo de verdad, si se puede. Sin señal lo sirve sw.js desde su
     copia; si tampoco hay copia, se queda el respaldo y no se molesta a nadie.
     Solo se acepta si trae lo que UI.T lee: un JSON a medias dejaría claves
     sin texto, y eso es peor que el respaldo completo. */
  if (typeof global.fetch === 'function') {
    global.fetch('vocabulario.json', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.version && d.conceptos && d.estados_caso) { VOC = d; }
      })
      .catch(function () { /* sin red y sin copia: vale el respaldo */ });
  }

  /* --- La barra de abajo del técnico -------------------------------------
     La misma que mis.php pinta en el servidor, para las pantallas estáticas
     —cronograma.html— que no pueden llamar a PHP (T2.13.4). Es una función
     pura para poder probarla sin navegador, y prueba_barra_tecnico.mjs la
     compara con la de mis.php: si cambia una y no la otra, falla. */
  UI.BARRA_TECNICO = [
    ['bandeja',     'mis.php',             '▤', 'Bandeja'],
    ['historial',   'mis.php?t=atendidas', '✓', 'Historial'],
    ['emitir',      'index.html',          '✎', 'Emitir'],
    ['repuestos',   'pendientes.php',      '◷', 'Repuestos'],
    ['preventivos', 'cronograma.html',     '▦', 'Preventivos']
  ];
  UI.barraTecnico = function (activa) {
    return '<nav class="nav-abajo" aria-label="Principal">' +
      UI.BARRA_TECNICO.map(function (b) {
        return '<a href="' + b[1] + '"' + (b[0] === activa ? ' class="on" aria-current="page"' : '') +
               '><span class="ic">' + b[2] + '</span>' + b[3] + '</a>';
      }).join('') + '</nav>';
  };

  /* --- Barra de novedades ------------------------------------------------
     El vigilante del buzón (t2_9) empuja los casos nuevos en segundos. Esta
     barra avisa y NO recarga sola: quien mira puede estar a medio leer un caso
     o a medio escribir un motivo, y recargarle la pantalla debajo es peor que
     no avisar. La decisión es de la persona. */
  function novedades() {
    var caja = document.getElementById('novedades');
    var txt  = document.getElementById('nov-txt');
    /* Se exigen LAS DOS partes, no solo la caja.
       Con solo la caja, esta funcion se enganchaba a cualquier elemento que se
       llamara `novedades` — y en el formulario del tecnico habia uno, el de las
       novedades de la visita. El resultado era que a los 30 segundos le ponia
       `hidden` a lo que el tecnico acababa de escribir: seguia en el envio,
       pero desaparecia de la pantalla. Los ids se separaron; esta comprobacion
       es para que no vuelva a pasar si alguien reusa el nombre. */
    if (!caja || !txt) { return; }
    var base = null, visto = null;

    function mirar() {
      fetch('novedades.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.hay) { return; }
          if (base === null) { base = d.version; return; }     // primera lectura
          if (d.version === base || d.version === visto) { caja.hidden = true; return; }
          if (typeof d.avisos === 'number') {
            // Al técnico no le cuenta el buzón sino SUS avisos (T2.13.5), y «Ver»
            // lo lleva a ellos en vez de recargar la pantalla en la que está.
            if (d.avisos === 0) { caja.hidden = true; return; }
            txt.textContent = d.avisos === 1 ? 'Tienes 1 aviso nuevo' : 'Tienes ' + d.avisos + ' avisos nuevos';
          } else {
            var n = (typeof d.total === 'number') ? d.total : null;
            txt.textContent = n === null
              ? 'El buzón se actualizó'
              : 'El buzón se actualizó — ahora hay ' + n + (n === 1 ? ' caso' : ' casos');
          }
          caja.dataset.ir = d.ir || '';
          caja.dataset.version = d.version;
          caja.hidden = false;
        })
        .catch(function () { /* sin señal: se reintenta al rato, sin molestar */ });
    }

    var ver = document.getElementById('nov-ver');
    var no  = document.getElementById('nov-no');
    if (ver) {
      ver.addEventListener('click', function () {
        if (caja.dataset.ir) { location.href = caja.dataset.ir; } else { location.reload(); }
      });
    }
    if (no) {
      no.addEventListener('click', function () {
        visto = Number(caja.dataset.version) || null;
        caja.hidden = true;
      });
    }
    mirar();
    setInterval(mirar, 30000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { mirar(); }
    });
  }

  /* --- Filtros que se aplican solos --------------------------------------
     Un formulario de filtros con botón «Filtrar» obliga a dos gestos por cada
     cambio. Los desplegables se envían al elegir; el texto no, porque enviar a
     cada tecla recarga la página en mitad de la palabra. */
  function filtrosVivos() {
    document.querySelectorAll('form[data-auto] select').forEach(function (s) {
      s.addEventListener('change', function () { s.form.submit(); });
    });
  }

  /* --- Arranque ---------------------------------------------------------- */
  function arranque() {
    document.querySelectorAll('[data-n]').forEach(UI.contar);
    document.querySelectorAll('[data-hace]').forEach(function (el) {
      var t = UI.hace(el.getAttribute('data-hace'));
      if (t) { el.textContent = t; }
    });
    // El escalonado se hace con una variable por hijo; ponerlo a mano en la
    // plantilla era una línea de PHP repetida en cada bucle.
    document.querySelectorAll('.escalona').forEach(function (c) {
      Array.prototype.forEach.call(c.children, function (h, i) { h.style.setProperty('--i', i); });
    });
    novedades();
    filtrosVivos();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arranque);
  } else {
    arranque();
  }

  global.UI = UI;
})(window);
