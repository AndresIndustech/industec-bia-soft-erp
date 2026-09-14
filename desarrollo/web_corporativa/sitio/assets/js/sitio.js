/* INDUSTEC · comportamiento del sitio.
   Sin dependencias, sin cookies, sin almacenamiento en el navegador y sin llamadas de red.
   El formulario de /contacto/ solo arma el mensaje y lo pone en dos enlaces reales (WhatsApp y correo)
   que la persona pulsa: no guarda ni envía nada a ningún servidor. */
(function () {
  'use strict';

  var raiz = document.documentElement;

  /* 0. Desplazamiento suave solo después de cargar: el salto a un ancla que viene en la dirección
        (por ejemplo /servicios/#correctivo) es inmediato; los clics dentro de la página, suaves. */
  if (document.readyState === 'complete') raiz.classList.add('suave');
  else window.addEventListener('load', function () { raiz.classList.add('suave'); });

  /* 1. Menú del celular */
  var boton = document.querySelector('.menu-boton');
  var nav = document.getElementById('menu-principal');
  if (boton && nav) {
    var icono = boton.querySelector('.ico');
    var estaAbierto = function () { return boton.getAttribute('aria-expanded') === 'true'; };
    var fijarMenu = function (abrir) {
      boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
      boton.setAttribute('aria-label', abrir ? 'Cerrar menú' : 'Abrir menú');
      nav.classList.toggle('abierto', abrir);
      if (icono) {
        icono.classList.toggle('ico-menu', !abrir);
        icono.classList.toggle('ico-cerrar', abrir);
      }
    };
    boton.addEventListener('click', function () { fijarMenu(!estaAbierto()); });
    nav.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('a')) fijarMenu(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && estaAbierto()) { fijarMenu(false); boton.focus(); }
    });
    document.addEventListener('click', function (e) {
      if (estaAbierto() && !nav.contains(e.target) && !boton.contains(e.target)) fijarMenu(false);
    });
    var escritorio = window.matchMedia('(min-width: 960px)');
    var alCambiar = function () { if (escritorio.matches) fijarMenu(false); };
    if (escritorio.addEventListener) escritorio.addEventListener('change', alCambiar);
    else if (escritorio.addListener) escritorio.addListener(alCambiar);
  }

  /* 2. Sombra de la cabecera al desplazarse */
  var cabecera = document.querySelector('.cabecera');
  if (cabecera) {
    var sombra = function () { cabecera.classList.toggle('con-sombra', window.scrollY > 8); };
    sombra();
    window.addEventListener('scroll', sombra, { passive: true });
  }

  /* 3. Aparición suave de los bloques. Se omite si la persona pidió reducir el movimiento
        o si el navegador no la soporta: en ese caso todo se ve desde el inicio. */
  var bloques = document.querySelectorAll('.revelar');
  var reducir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (bloques.length && !reducir && 'IntersectionObserver' in window) {
    raiz.classList.add('anim');
    var observador = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (entrada) {
        if (entrada.isIntersecting) {
          entrada.target.classList.add('visible');
          observador.unobserve(entrada.target);
        }
      });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.06 });
    Array.prototype.forEach.call(bloques, function (b) { observador.observe(b); });
  }

  /* 4. Año del pie de página */
  var anio = new Date().getFullYear();
  Array.prototype.forEach.call(document.querySelectorAll('[data-anio]'), function (el) {
    if (anio > 2026) el.textContent = String(anio);
  });

  /* 5. Formulario «Prepara tu mensaje» (/contacto/) */
  var form = document.getElementById('form-mensaje');
  if (!form) return;

  var NUMERO = '593997887709';
  var CORREO = 'servicioalcliente@industec.me';
  var SALUDO = 'Hola INDUSTEC, les escribo desde su página web.';
  var porId = function (id) { return document.getElementById(id); };
  var campos = {
    nombre: porId('f-nombre'),
    empresa: porId('f-empresa'),
    ciudad: porId('f-ciudad'),
    necesidad: porId('f-necesidad'),
    detalle: porId('f-detalle'),
    telefono: porId('f-telefono')
  };
  /* La empresa es obligatoria: la web atiende a empresas y cadenas. */
  var obligatorios = [
    { campo: campos.nombre, error: porId('e-nombre') },
    { campo: campos.empresa, error: porId('e-empresa') },
    { campo: campos.necesidad, error: porId('e-necesidad') },
    { campo: campos.detalle, error: porId('e-detalle') }
  ];
  var previa = porId('vista-previa-texto');
  var aviso = porId('aviso-urgente');
  var enlaceWa = porId('enviar-wa');
  var enlaceCorreo = porId('enviar-correo');

  var linea = function (el) { return el.value.replace(/\s+/g, ' ').trim(); };
  var parrafo = function (el) {
    return el.value.replace(/\r\n?/g, '\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  };

  /* Mismo mensaje para WhatsApp y para el cuerpo del correo; los campos opcionales vacíos se omiten,
     pero la línea «Empresa:» va siempre (en la vista previa, con «…» mientras esté vacía). */
  var armar = function (conHuecos) {
    var hueco = conHuecos ? '…' : '';
    var nombre = linea(campos.nombre);
    var empresa = linea(campos.empresa);
    var ciudad = linea(campos.ciudad);
    var necesidad = campos.necesidad.value;
    var telefono = linea(campos.telefono);
    var detalle = parrafo(campos.detalle);
    var l = [SALUDO, 'Nombre: ' + (nombre || hueco), 'Empresa: ' + (empresa || hueco)];
    if (ciudad) l.push('Ciudad: ' + ciudad);
    l.push('Necesito: ' + (necesidad || hueco));
    if (telefono) l.push('Teléfono: ' + telefono);
    l.push('Detalle: ' + (detalle || hueco));
    return l.join('\n');
  };

  /* Los dos enlaces de envío son enlaces de verdad: cada vez que se escribe, su href lleva el mensaje
     tal como está. Sin clic sintético ni ventana emergente: funciona igual en Safari de iPhone. */
  var actualizar = function () {
    var mensaje = armar(false);
    if (previa) previa.textContent = armar(true);
    if (aviso) aviso.hidden = campos.necesidad.value !== 'Reparación o emergencia';
    if (enlaceWa) enlaceWa.href = 'https://wa.me/' + NUMERO + '?text=' + encodeURIComponent(mensaje);
    if (enlaceCorreo) {
      enlaceCorreo.href = 'mailto:' + CORREO +
        '?subject=' + encodeURIComponent('Solicitud desde la web: ' + (campos.necesidad.value || 'consulta') + ' - ' + linea(campos.empresa)) +
        '&body=' + encodeURIComponent(mensaje.replace(/\n/g, '\r\n'));
    }
  };

  var esValido = function (item) {
    return item.campo === campos.detalle ? parrafo(item.campo) !== '' : linea(item.campo) !== '';
  };

  var marcar = function (item, mal) {
    var ids = (item.campo.getAttribute('aria-describedby') || '').split(' ').filter(function (id) {
      return id && id !== item.error.id;
    });
    if (mal) {
      item.campo.setAttribute('aria-invalid', 'true');
      ids.unshift(item.error.id);
    } else {
      item.campo.removeAttribute('aria-invalid');
    }
    if (ids.length) item.campo.setAttribute('aria-describedby', ids.join(' '));
    else item.campo.removeAttribute('aria-describedby');
    item.error.hidden = !mal;
  };

  var validar = function () {
    var primero = null;
    obligatorios.forEach(function (item) {
      var mal = !esValido(item);
      marcar(item, mal);
      if (mal && !primero) primero = item.campo;
    });
    if (primero) primero.focus();
    return !primero;
  };

  var alEditar = function (e) {
    actualizar();
    obligatorios.forEach(function (item) {
      if (item.campo === e.target && item.campo.getAttribute('aria-invalid') === 'true' && esValido(item)) {
        marcar(item, false);
      }
    });
  };
  form.addEventListener('input', alEditar);
  form.addEventListener('change', alEditar);

  /* Si falta un dato, el enlace no se abre: se marcan los campos y el foco va al primero. */
  var frenarSiFalta = function (e) {
    if (!validar()) { e.preventDefault(); }
  };
  if (enlaceWa) enlaceWa.addEventListener('click', frenarSiFalta);
  if (enlaceCorreo) enlaceCorreo.addEventListener('click', frenarSiFalta);

  /* Enter en un campo: como pulsar «Enviar por WhatsApp». */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (validar() && enlaceWa) enlaceWa.click();
  });

  actualizar();
})();
