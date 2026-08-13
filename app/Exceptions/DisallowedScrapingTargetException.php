<?php

namespace App\Exceptions;

/**
 * El destino no está permitido: host fuera de la allowlist, dirección privada
 * o ruta prohibida por robots.txt.
 */
class DisallowedScrapingTargetException extends SourceScrapingException {}
