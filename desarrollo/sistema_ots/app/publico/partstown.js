/* partstown.js — T2.28.11 (obs. 9): el enlace a Parts Town de un equipo o de
   un repuesto, siguiendo la «Guía de uso Parts Town» de INDUSTEC (marca y
   modelo de la placa → despiece → número de parte).

   GEMELO DE nucleo/PartsTown.php: la misma entrada da el mismo enlace en el
   formulario y en Repuestos. prueba_contratos.mjs ejecuta los dos y compara;
   si se cambia uno, se cambia el otro. Por qué cada regla: ver el PHP.

   No consulta Parts Town (no tiene API y Cloudflare bloquea a los navegadores
   automáticos): solo arma el enlace y copia al portapapeles lo que conviene
   pegar en su buscador si la página no aparece. La tabla de marcas y prefijos
   la manda catalogos.php (`CAT.partstown`), así también sirve sin señal.

   En las pantallas del servidor (Repuestos), un enlace con `data-pt-copiar`
   copia ese texto al tocarlo y deja que el enlace se abra solo. */
(function (window) {
  'use strict';

  var BASE = 'https://www.partstown.com/es/';
  var FALTA = 'Anota marca y modelo de la placa: la guía de INDUSTEC lo pide antes de buscar.';

  // Minúsculas y sin tildes, como Busqueda.normalizar y Ui::sinTildes. El rango
  // ̀-ͯ va como escape para aguantar editores con otra codificación.
  function sinTildes(s) {
    s = String(s == null ? '' : s).toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  }
  function claveMarca(m) { return sinTildes(m).replace(/[^a-z0-9]+/g, '').toUpperCase(); }
  function slug(s) { return sinTildes(s).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  // Los mismos caracteres que trim() y \s de PHP (sin /u), no los de String.trim:
  // con un espacio duro (U+00A0) el navegador y el servidor darían otro enlace.
  function recortar(s) { return String(s == null ? '' : s).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, ''); }

  /* Lo que se escribe en el campo del número de parte hasta el primer «/», «,»
     o «;» (en el catálogo de KFC hay celdas con dos números: `Fm8100705 /
     FM8100703`). NO se corta en los espacios: «HP FR21800» y «Hen 22455» son UN
     número, y cortarlos copiaba «HP» y «Hen» (revisión del 2026-10-01). */
  function primerNumero(n) { return recortar(recortar(n).split(/[\/,;\n\r]+/)[0]); }

  /* El número como lo escribiría Parts Town para buscar su ficha: si lo que se
     tecleó es un prefijo de letras, un espacio y el número («Hen 22455»), se
     juntan. Cualquier otro espacio corta: «Hen22455 (original)» es `Hen22455`. */
  function numeroDeFicha(numero) {
    var t = String(numero).split(/[ \t\n\r\f\x0B]+/).filter(Boolean);
    if (t.length > 1 && /^[A-Za-z]+$/.test(t[0]) && /^[A-Za-z0-9]/.test(t[1])) { return t[0] + t[1]; }
    return t[0] || '';
  }

  /* Lo que NO es un dato de la placa: la marca o el modelo vacíos, solo signos,
     con espacios de cualquier clase (el duro U+00A0 también) y los rellenos que
     el histórico trae por miles: «N/V», «NO VISIBLE», «SIN MODELO», «XXXX»…
     (239 modelos y 8 marcas de relleno en las sugerencias del formulario, el
     2026-10-01). Con ellos se armaba un despiece inventado:
     …/henny-penny/no-visible/parts. Los primeros son los de Reglas.esMarcador. */
  var RELLENOS = ['s/n', 'sn', 's/m', 'sm', 'n/a', 'na', '0', 'no tiene', 'sin serie', 'sin placa',
                  'n/v', 'nv', 'n/o', 'no visible', 'no aplica', 'no legible', 'ilegible',
                  'sin modelo', 'sin marca', 'sin dato', 'sin datos'];
  function relleno(s) {
    // Todo lo que no es letra, número o «/» pasa a espacio, y sin espacios junto a «/».
    var n = sinTildes(s).replace(/[^a-z0-9\/]+/g, ' ').replace(/ ?\/ ?/g, '/').replace(/^ +| +$/g, '');
    return n === '' || RELLENOS.indexOf(n) !== -1 || /^x{2,}$/.test(n);
  }

  function ficha(numero, tabla) {
    var n = slug(numeroDeFicha(primerNumero(numero)));
    var mejor = null;
    var prefijos = (tabla && tabla.prefijos) || {};
    Object.keys(prefijos).forEach(function (p) {
      var resto = n.slice(p.length);
      if (p && n.indexOf(p) === 0 && resto.length >= 2 && /^[a-z0-9]/.test(resto) && /\d/.test(resto) &&
          (mejor === null || p.length > mejor[0].length)) {
        mejor = [p, String(prefijos[p])];
      }
    });
    return mejor === null ? null : mejor[1] + '/' + n;
  }

  function enlace(d, tabla) {
    d = d || {};
    var marca = recortar(d.marca);
    var modelo = recortar(d.modelo);
    if (relleno(marca) || relleno(modelo)) { return { tipo: null, url: null, copiar: null, falta: FALTA }; }
    var sku = recortar(d.sku);
    var numero = primerNumero(sku !== '' ? sku : d.numero_parte);
    var copiar = numero !== '' ? numero : marca + ' ' + modelo;
    var f = ficha(numero, tabla);
    if (f !== null) { return { tipo: 'FICHA', url: BASE + f, copiar: copiar, falta: null }; }
    var marcas = (tabla && tabla.marcas) || {};
    var sm = Object.prototype.hasOwnProperty.call(marcas, claveMarca(marca)) ? marcas[claveMarca(marca)] : null;
    var sMod = slug(modelo);
    if (typeof sm === 'string' && sm !== '' && sMod !== '') {
      return { tipo: 'MODELO', url: BASE + sm + '/' + sMod + '/parts', copiar: copiar, falta: null };
    }
    return { tipo: 'BUSCAR', url: BASE, copiar: copiar, falta: null };
  }

  function aviso(e) {
    if (!e || e.tipo === null) { return (e && e.falta) || FALTA; }
    var pega = 'pega «' + e.copiar + '» en el buscador de Parts Town';
    if (e.tipo === 'FICHA') { return 'Se abre la ficha del repuesto en Parts Town. Si no aparece, ' + pega + '.'; }
    if (e.tipo === 'MODELO') { return 'Se abre el despiece del modelo en Parts Town. Si no aparece, ' + pega + '.'; }
    // Sin atribuirle nada a Parts Town: aquí también cae el formulario con una
    // caché vieja que no trae la tabla de marcas, y ahí «no tiene enlace» sería falso.
    return 'No tenemos el enlace directo de esta marca: ' + pega + '.';
  }

  /* Copiar sin bloquear: si el navegador no deja (sin https, sin permiso), no
     pasa nada -- el aviso sigue diciendo qué pegar. */
  function copiar(texto) {
    try {
      var nav = window.navigator;
      if (nav && nav.clipboard && nav.clipboard.writeText) {
        return nav.clipboard.writeText(String(texto)).then(function () { return true; }, function () { return false; });
      }
    } catch (e) { /* sin portapapeles */ }
    return Promise.resolve(false);
  }

  /* Abrir desde un botón del formulario: si falta marca o modelo NO abre
     (la guía los pide primero); si no, abre en pestaña nueva y copia. Devuelve
     el texto que hay que mostrarle a la persona. */
  function abrir(d, tabla) {
    var e = enlace(d, tabla);
    if (e.tipo === null) { return { enlace: e, texto: aviso(e) }; }
    // noopener: la pestaña de Parts Town no puede tocar el formulario.
    var w = window.open(e.url, '_blank', 'noopener');
    if (w) { try { w.opener = null; } catch (x) { /* ya sin opener */ } }
    copiar(e.copiar);
    return { enlace: e, texto: aviso(e) };
  }

  // Repuestos (pendientes.php): el enlace se abre solo; aquí solo se copia.
  var doc = window.document;
  if (doc && doc.addEventListener) {
    doc.addEventListener('click', function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[data-pt-copiar]') : null;
      if (a) { copiar(a.getAttribute('data-pt-copiar')); }
    });
  }

  window.PartsTown = { enlace: enlace, aviso: aviso, abrir: abrir, slug: slug, claveMarca: claveMarca,
                       ficha: ficha, primerNumero: primerNumero, numeroDeFicha: numeroDeFicha,
                       relleno: relleno, FALTA: FALTA };
})(window);
