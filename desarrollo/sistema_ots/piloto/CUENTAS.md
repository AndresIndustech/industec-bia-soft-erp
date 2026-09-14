# Cuentas del piloto — dónde se entra y cómo se entregan las claves

> **Este documento no contiene ninguna contraseña**, y no debe contenerla nunca. Las claves se entregan en persona, una sola vez, y cada persona la cambia al entrar.

## Dónde se entra

| Qué | Dirección | Quién |
|---|---|---|
| El sistema completo (escritorio) | `https://darkviolet-armadillo-872352.hostingersite.com/ot/login.php` | Administración, dirección, jefes de zona |
| La app del técnico (celular) | `https://darkviolet-armadillo-872352.hostingersite.com/ot/` | Técnicos (los demás roles también pueden entrar desde el celular) |
| Desde la web de INDUSTEC | `https://darkviolet-armadillo-872352.hostingersite.com/acceso/` → «Ingresar al sistema» o «Abrir la app del técnico» | Todos |

Es el **sitio de pruebas**. La dirección definitiva (`ot.industec.me` u otra que decida INDUSTEC) se activa al corte, después del piloto. Lo que se guarde durante el piloto se conserva.

## Quién tiene cuenta

Las cuentas ya existen desde el 9 de septiembre de 2026, creadas a partir del padrón vigente de técnicos. El nombre de usuario es **la inicial del nombre seguida del apellido, en minúsculas y sin tildes** (por ejemplo, `mperez` para María Pérez).

| Rol en el sistema | Cuántas hay | Zona |
|---|---|---|
| Dirección (`SUPERADMIN`) | 2 | todas |
| Administración (`ADMIN`) | 1 | todas |
| Jefe de zona (`JEFE_ZONA`) | 3 | una por zona: UIO, LARB, CNLJ |
| Técnico (`TECNICO`) | 16 | UIO 5 · LARB 5 · CNLJ 6 |

El piloto es de **UIO**: su jefe de zona, sus cinco técnicos y la administración. Las cuentas de LARB y CNLJ existen y funcionan, pero no se les entrega clave hasta que el piloto termine.

La lista con nombre y usuario de cada persona **no va en este documento**: se imprime desde la pantalla `Usuarios` del sistema el día de la entrega, y esa hoja impresa se destruye después. Las cuentas de prueba (`*_prueba_*`) quedan **inactivas** antes del piloto ([`ANTES_DE_EMPEZAR.md`](ANTES_DE_EMPEZAR.md), paso 2).

## Cómo se entrega la clave inicial

1. La administración entra a `Usuarios`, busca a la persona y pulsa **«Nueva clave»**.
2. El sistema genera una clave temporal y **la muestra una sola vez** en pantalla. Se le dicta o se le muestra a la persona en ese momento; no se manda por WhatsApp ni por correo.
3. Si la clave se pierde antes de usarla, se repite el paso 1: la anterior deja de valer.

Al generar una clave nueva, el sistema marca la cuenta con **«debe cambiar la clave»**.

## El primer ingreso (lo hace cada persona)

1. Abre la dirección que le corresponde y escribe su usuario y la clave temporal.
2. El sistema lo lleva a **«Cambia tu contraseña»** y no lo deja pasar a ninguna pantalla hasta que la cambie: escribe la temporal, la nueva y la repite. La nueva es suya y nadie más la conoce.
3. Desde ese momento entra con su clave. Puede cambiarla cuando quiera desde el menú de su nombre → «Cambiar contraseña».

## Reglas de la sesión

- **Una sesión por persona.** Si la misma cuenta entra desde otro dispositivo, el sistema pregunta si quiere «desplazar» la sesión anterior; al aceptar, la otra se cierra. Una cuenta no se comparte entre dos personas: la bitácora registra quién hizo cada cosa.
- La sesión se cierra sola tras **2 horas sin usarla** y, como máximo, a las **12 horas**. Al técnico en campo no le afecta: la orden que está llenando queda guardada en el celular y sale cuando vuelve a entrar.
- **Cinco intentos fallidos** seguidos bloquean el ingreso durante **15 minutos** (también al equivocarse cinco veces en «Cambia tu contraseña»). Pasado ese tiempo se puede volver a intentar; si la persona ya no recuerda la clave, la administración le genera una «Nueva clave».
- «Salir» está en el menú de su nombre, arriba a la derecha (en el celular, en la barra de abajo).

## Si alguien olvida su clave

No hay «recuperar contraseña» por correo a propósito (el correo no sale del sitio de pruebas). Le pide a la administración una «Nueva clave» y vuelve al primer ingreso.

## Quién puede crear o cambiar cuentas

| Acción | Quién |
|---|---|
| Crear técnicos y jefes de zona, editar nombre, correo, zona y rol, generar clave nueva, desactivar | Administración y dirección |
| Crear cuentas de administración o dirección | Solo dirección |
| Ver la actividad de una cuenta (`Bitácora`) | Administración y dirección |

Cada alta, edición, clave nueva y desactivación queda en la bitácora con quién la hizo. Si a un técnico se le cambia de zona, el sistema avisa cuántos casos tiene asignados para que se repartan antes.
