<?php
declare(strict_types=1);

/**
 * Despacho.php — Las dos decisiones puras de despachar_correo_cli.php: si ya
 * se llegó al tope por hora, y qué hacer con un error del SMTP (T2.28.2, §2c).
 *
 * POR QUÉ ESTÁ SEPARADO. `despachar_correo_cli.php` conecta a un SMTP de
 * verdad y escribe en la base: no se puede probar en una corrida local sin
 * mandar correos ni tocar `email_queue` de ningún sitio. Estas dos reglas -sí-
 * son puras (una cuenta contra un número, una cadena de texto contra un
 * patrón) y son las que de verdad importan probar: si el tope se calcula mal,
 * el SMTP corta la cuenta; si el cupo por hora se clasifica como cualquier
 * otro error, un correo bueno se marca FALLIDO por algo que no es culpa suya.
 */
final class Despacho
{
    /** Cuántos ENVIADO por hora deja pasar antes de que Titan corte la cuenta. */
    public const TOPE_HORA = 45;

    /** Esperas crecientes entre intentos normales, en minutos. */
    public const ESPERAS = [5, 15, 60, 240, 1440];

    /** A los cuántos intentos un error normal (no el cupo por hora) se da por FALLIDO. */
    public const MAX_INTENTOS = 6;

    /** ¿Ya se llegó al tope por hora? Si es así, esta corrida no debe conectar. */
    public static function superoTopeHora(int $enviadosEnLaUltimaHora): bool
    {
        return $enviadosEnLaUltimaHora >= self::TOPE_HORA;
    }

    /**
     * Qué hacer con el error de un intento de envío.
     *
     * @return array{permanente:bool, cupoHora:bool, espera:?int, intentosGuardar:int, motivo:?string}
     *         `intentosGuardar` es lo que se escribe en email_queue.intentos:
     *         igual a $intentosPrevios (sin subir) cuando es el cupo por hora,
     *         porque eso no cuenta contra los MAX_INTENTOS del correo -es el
     *         SMTP cortando el envío en curso, no un rechazo de este correo-.
     */
    public static function clasificar(string $mensaje, int $intentosPrevios): array
    {
        $intento  = $intentosPrevios + 1;
        // «Sender Hourly Quota Exceeded»: el cupo por hora de Titan. No es un
        // rechazo DE ESTE correo, así que nunca cuenta para los MAX_INTENTOS
        // ni se marca FALLIDO por eso, y siempre reintenta a los 60 min.
        $cupoHora = stripos($mensaje, 'hourly quota exceeded') !== false;
        // 5xx es permanente (buzón inexistente, rechazado por política): no se insiste.
        $permanente = !$cupoHora && ((bool) preg_match('/\b5\d\d\b/', $mensaje) || $intento >= self::MAX_INTENTOS);

        if ($permanente) {
            return [
                'permanente' => true, 'cupoHora' => false, 'espera' => null,
                'intentosGuardar' => $intento,
                'motivo' => $intento >= self::MAX_INTENTOS ? 'agotó los ' . self::MAX_INTENTOS . ' intentos' : 'rechazo permanente del SMTP',
            ];
        }
        return [
            'permanente' => false, 'cupoHora' => $cupoHora,
            'espera' => $cupoHora ? 60 : self::ESPERAS[min($intento - 1, count(self::ESPERAS) - 1)],
            'intentosGuardar' => $cupoHora ? $intentosPrevios : $intento,
            'motivo' => null,
        ];
    }
}
