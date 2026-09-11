/* =========================================================================
   reglas.js — Los controles del formato único, del lado del navegador.

   TERCER espejo de las mismas reglas. Ya hay dos:
     - agentes/scripts/t2_5_validacion.py   (ingesta e histórico)
     - app/nucleo/Validacion.php            (servidor, la que MANDA)
   Este es el del cliente: el técnico se entera del error en el momento,
   detrás de la freidora, y no después de subir 35 fotos.

   Que haya tres copias es un riesgo real. Lo que lo controla es que las tres
   corren el MISMO fixture (pruebas/fixture_validacion.json). Si una se separa,
   el fixture la delata:

     node reglas.fixture.mjs      # esta copia
     D:\SOFTWARE\PHP83\php.exe ../pruebas/validacion_test.php
     cd ../../agentes && .venv/Scripts/python.exe scripts/t2_5_validacion.py --fixture

   Las tres tienen que decir lo mismo. El fixture es el árbitro; ninguna
   validación de cliente reemplaza a la del servidor (I-13).
   ========================================================================= */

(function (root) {
  'use strict';

  var BLOQUEA = 'BLOQUEA', ADVIERTE = 'ADVIERTE', INFORMA = 'INFORMA';

  // Las ocho ortografías de "no se usó repuesto" que aparecen en los datos.
  // 2.168 de 4.359 filas con repuesto anotado, el 50%.
  var SIN_REPUESTO = [
    'S/N', 'SN', 'NINGUNO', 'NINGUNA', 'SINREPUESTOS', 'SINREPUESTO',
    '-', 'N/A', 'NA', 'SINRESPUESTOS', '0', 'NINGUN', 'SINREPUESTOSUSADOS'
  ];

  var SEVERIDADES = {
    LOCAL_REQUERIDO: BLOQUEA,
    LOCAL_FUERA_DE_CATALOGO: BLOQUEA,
    ZONA_CRUZADA: INFORMA,
    AVISO_VACIO_SIN_DECLARAR: BLOQUEA,
    AVISO_MAL_FORMADO: BLOQUEA,
    TIPO_INVALIDO: BLOQUEA,
    DIA_REQUERIDO_EN_PREVENTIVO: BLOQUEA,
    DIA_FUERA_DE_RANGO: BLOQUEA,
    DIA_EN_CORRECTIVO: ADVIERTE,
    SIN_EQUIPO: BLOQUEA,
    EQUIPO_DE_OTRO_LOCAL: BLOQUEA,
    EQUIPO_SIN_IDENTIFICAR: BLOQUEA,
    TIPO_FUERA_DE_CATALOGO: ADVIERTE,
    EQUIPO_ELEGIBLE_POR_ACTIVO: INFORMA,
    REPUESTO_NO_DECLARADO: BLOQUEA,
    REPUESTO_MARCADO_SIN_DETALLE: BLOQUEA,
    REPUESTO_CONTRADICTORIO: ADVIERTE,
    FECHA_FUTURA: BLOQUEA,
    FIN_NO_POSTERIOR_A_INICIO: BLOQUEA,
    DURACION_INVEROSIMIL: ADVIERTE,
    TIEMPOS_INCOMPLETOS: ADVIERTE,
    SIN_TRABAJO_REALIZADO: BLOQUEA,
    TRABAJO_DEMASIADO_ESCUETO: ADVIERTE,
    SIN_FIRMA: BLOQUEA,
    SIN_FOTOS: ADVIERTE,
    SIN_TECNICO: BLOQUEA,
    TECNICO_NO_VIGENTE: BLOQUEA,
    TECNICO_YA_NO_VIGENTE: INFORMA,
    VARIOS_TECNICOS_EN_UN_CAMPO: INFORMA
  };

  function quitarTildes(s) {
    return String(s == null ? '' : s).normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  // Tokens de un nombre, sin tildes y sin partículas cortas (<=2 letras).
  // La nómina guarda el nombre legal completo (ANTHONY MEDARDO JUMBO ROJANO) y
  // el técnico se firma como se le conoce (Anthony Jumbo).
  function tokensNombre(s) {
    var partes = quitarTildes(s).toUpperCase().split(/[^A-Z]+/);
    var out = {};
    for (var i = 0; i < partes.length; i++) {
      if (partes[i].length > 2) out[partes[i]] = true;
    }
    return out;
  }

  function contenido(a, b) {
    var vacio = true;
    for (var k in a) {
      if (!Object.prototype.hasOwnProperty.call(a, k)) continue;
      vacio = false;
      if (!b[k]) return false;
    }
    return !vacio;
  }

  function esSinRepuesto(v) {
    if (v == null || v === '' || v === false) return true;
    var n = String(v).replace(/[.\s]/g, '').toUpperCase();
    return SIN_REPUESTO.indexOf(n) !== -1;
  }

  function fechaSolo(s) { return String(s == null ? '' : s).slice(0, 10); }

  // Devuelve segundos desde epoch para un "YYYY-MM-DDTHH:MM" o "HH:MM".
  function aMomento(s) {
    if (!s) return null;
    s = String(s);
    var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (m) return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) / 1000;
    var h = s.match(/^(\d{1,2}):(\d{2})/);
    if (h) return (+h[1] * 60 + +h[2]) * 60;
    var t = Date.parse(s);
    return isNaN(t) ? null : t / 1000;
  }

  /**
   * Construye un validador sobre un catálogo.
   * catalogo = { locales:[{codigo,zona,...}], equipos:{LOCAL:[{equipo_sap,tipo}]},
   *              tipos:[str], tecnicos:[{nombre}] }
   */
  function crear(catalogo) {
    var locales = {};
    (catalogo.locales || []).forEach(function (l) { locales[l.codigo] = l; });
    var tipos = {};
    (catalogo.tipos || []).forEach(function (t) { tipos[String(t).toUpperCase()] = true; });
    var tecnicos = (catalogo.tecnicos || []).map(function (t) { return tokensNombre(t.nombre); });
    var equiposCat = catalogo.equipos || {};

    function validar(o, contexto) {
      contexto = contexto || 'CAPTURA';
      var h = [];
      function add(campo, regla, msg) {
        h.push({ campo: campo, regla: regla, severidad: SEVERIDADES[regla], mensaje: msg });
      }

      var tipo = String(o.tipo || '').toUpperCase();

      // --- LOCAL ---
      var local = (o.local == null || o.local === '') ? null : o.local;
      if (local === null) {
        add('local', 'LOCAL_REQUERIDO', 'sin local no se puede archivar, cruzar ni facturar la orden');
      } else if (!locales[local]) {
        add('local', 'LOCAL_FUERA_DE_CATALOGO',
          "'" + local + "' no está en el maestro de " + Object.keys(locales).length + ' locales');
      }

      // --- ZONA (derivada, no se valida: se compara) ---
      var info = local !== null ? (locales[local] || null) : null;
      if (info && o.zona && o.zona !== info.zona) {
        add('zona', 'ZONA_CRUZADA',
          'el envío dice ' + o.zona + ' y el local ' + local + ' es de ' + info.zona + '; manda el maestro');
      }

      // --- AVISO SAP ---
      var aviso = o.aviso;
      if (aviso == null || aviso === '' || aviso === '0' || aviso === 0) {
        if (!o.sin_aviso) {
          add('aviso', 'AVISO_VACIO_SIN_DECLARAR', "marca 'esta orden nace sin aviso' o escribe el aviso");
        }
      } else if (!/^\d{8}$/.test(String(aviso))) {
        add('aviso', 'AVISO_MAL_FORMADO', "'" + aviso + "' no son 8 dígitos");
      }

      // --- TIPO y DÍA ---
      if (tipo !== 'CORRECTIVO' && tipo !== 'PREVENTIVO') {
        add('tipo', 'TIPO_INVALIDO', "'" + tipo + "' no es CORRECTIVO ni PREVENTIVO");
      }
      var dia = o.dia_intervencion;
      var diaVacio = (dia == null || dia === '' || dia === 0 || dia === '0');
      if (tipo === 'PREVENTIVO') {
        if (diaVacio) {
          add('dia_intervencion', 'DIA_REQUERIDO_EN_PREVENTIVO', 'un preventivo pertenece a un día del ingreso');
        } else if (!/^\d+$/.test(String(dia)) || +dia < 1 || +dia > 5) {
          add('dia_intervencion', 'DIA_FUERA_DE_RANGO', "'" + dia + "' no está entre 1 y 5");
        }
      } else if (!diaVacio) {
        add('dia_intervencion', 'DIA_EN_CORRECTIVO', 'un correctivo no tiene día de intervención');
      }

      // --- EQUIPOS ---
      var equipos = o.equipos || [];
      if (!equipos.length) add('equipos', 'SIN_EQUIPO', 'toda orden interviene al menos un equipo');

      var delLocal = local !== null ? (equiposCat[local] || []) : [];
      var activos = {}, tiposLocal = {};
      delLocal.forEach(function (e) {
        activos[String(e.equipo_sap)] = true;
        tiposLocal[String(e.tipo).toUpperCase()] = true;
      });
      var hayActivos = Object.keys(activos).length > 0;
      var hayTiposLocal = Object.keys(tiposLocal).length > 0;

      equipos.forEach(function (eq, i) {
        var n = i + 1;
        var ref = eq.equipo_sap != null ? String(eq.equipo_sap) : '';
        var tipoEq = String(eq.tipo || '').toUpperCase();
        if (ref !== '') {
          if (hayActivos && !activos[ref]) {
            add('equipos[' + n + ']', 'EQUIPO_DE_OTRO_LOCAL', 'el activo ' + ref + ' no pertenece a ' + local);
          }
        } else if (tipoEq === '') {
          add('equipos[' + n + ']', 'EQUIPO_SIN_IDENTIFICAR', 'elige el activo del local, o al menos su tipo');
        } else if (!tipos[tipoEq]) {
          add('equipos[' + n + ']', 'TIPO_FUERA_DE_CATALOGO',
            "'" + tipoEq + "' no está entre los " + Object.keys(tipos).length + ' tipos conocidos');
        }
        if (hayTiposLocal && tipoEq !== '' && ref === '' && tiposLocal[tipoEq]) {
          add('equipos[' + n + ']', 'EQUIPO_ELEGIBLE_POR_ACTIVO',
            local + " tiene activos de tipo '" + tipoEq + "' en el catálogo; conviene elegir cuál");
        }
      });

      // --- REPUESTOS ---
      var uso = Object.prototype.hasOwnProperty.call(o, 'uso_repuesto') ? o.uso_repuesto : null;
      var detalle = o.repuestos == null ? null : o.repuestos;
      if (uso === null) {
        add('uso_repuesto', 'REPUESTO_NO_DECLARADO', 'responde si se usó repuesto o no');
      } else if (uso && esSinRepuesto(detalle)) {
        add('repuestos', 'REPUESTO_MARCADO_SIN_DETALLE', 'dice que se usó repuesto pero no dice cuál');
      } else if (!uso && detalle && !esSinRepuesto(detalle)) {
        add('repuestos', 'REPUESTO_CONTRADICTORIO',
          "marca que no hubo repuesto pero anota '" + String(detalle).slice(0, 40) + "'");
      }

      // --- FECHA ---
      var fecha = o.fecha_atencion;
      if (fecha) {
        // `_hoy` lo pone app.js con la fecha local. El respaldo, también local:
        // toISOString() da la fecha de UTC, que desde las 19:00 ya es mañana.
        var ahora = new Date();
        var hoy = o._hoy || (ahora.getFullYear() + '-' + ('0' + (ahora.getMonth() + 1)).slice(-2) +
                             '-' + ('0' + ahora.getDate()).slice(-2));
        if (fechaSolo(fecha) > fechaSolo(hoy)) {
          add('fecha_atencion', 'FECHA_FUTURA', fecha + ' es posterior a hoy');
        }
      }

      // --- TIEMPOS ---
      var ini = o.inicio ? aMomento(o.inicio) : null;
      var fin = o.fin ? aMomento(o.fin) : null;
      if (ini && fin) {
        if (fin <= ini) {
          add('fin', 'FIN_NO_POSTERIOR_A_INICIO', 'la hora de fin debe ser posterior a la de inicio');
        } else if ((fin - ini) > 12 * 3600) {
          add('fin', 'DURACION_INVEROSIMIL', ((fin - ini) / 3600).toFixed(1) + ' horas de atención');
        }
      } else {
        add('inicio', 'TIEMPOS_INCOMPLETOS', 'sin hora de inicio y fin no se puede medir la atención');
      }

      // --- TRABAJO REALIZADO ---
      var act = String(o.actividades == null ? '' : o.actividades).trim();
      if (act === '') {
        add('actividades', 'SIN_TRABAJO_REALIZADO', 'describe qué se hizo');
      } else if (act.length < 15) {
        add('actividades', 'TRABAJO_DEMASIADO_ESCUETO', "'" + act + "' no alcanza a describir una intervención");
      }

      // --- FIRMA Y EVIDENCIA ---
      if (!o.firma_presente) add('firma', 'SIN_FIRMA', 'la orden la firma el administrador del local');
      if (!o.fotos_cantidad) add('fotos', 'SIN_FOTOS', 'sin fotos la orden no tiene evidencia de lo hecho');

      // --- TÉCNICOS ---
      var tec = String(o.tecnico == null ? '' : o.tecnico).trim();
      if (tec === '') {
        add('tecnico', 'SIN_TECNICO', 'sin responsable');
      } else {
        var partes = tec.split(/\s*[,\/;]\s*|\s+y\s+/i)
          .map(function (x) { return x.trim(); })
          .filter(function (x) { return x !== ''; });
        partes.forEach(function (parte) {
          var t = tokensNombre(parte);
          var encontrado = tecnicos.some(function (n) { return contenido(t, n); });
          if (Object.keys(t).length && !encontrado) {
            if (contexto === 'CAPTURA') {
              add('tecnico', 'TECNICO_NO_VIGENTE', "'" + parte + "' no está entre los técnicos vigentes");
            } else {
              add('tecnico', 'TECNICO_YA_NO_VIGENTE',
                "'" + parte + "' no está en la nómina actual; esperable por la rotación");
            }
          }
        });
        if (partes.length > 1) {
          add('tecnico', 'VARIOS_TECNICOS_EN_UN_CAMPO',
            partes.length + ' técnicos en un campo de texto; el formato único los lleva como lista');
        }
      }

      return h;
    }

    validar.bloquea = function (hallazgos) {
      return hallazgos.some(function (x) { return x.severidad === BLOQUEA; });
    };
    return validar;
  }

  var api = { crear: crear, tokensNombre: tokensNombre, quitarTildes: quitarTildes,
              SEVERIDADES: SEVERIDADES, BLOQUEA: BLOQUEA, ADVIERTE: ADVIERTE, INFORMA: INFORMA };

  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.Reglas = api;

})(typeof self !== 'undefined' ? self : this);
