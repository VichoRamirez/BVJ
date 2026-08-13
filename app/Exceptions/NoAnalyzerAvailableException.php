<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

/**
 * Se agotó la cadena: ningún proveedor está disponible.
 *
 * Implementa AnalyzerUnavailable a propósito: para AnalyzeArticleJob esto es un
 * fallo de infraestructura, no del artículo, así que el artículo queda pendiente
 * y la cola reintenta más tarde en vez de marcarlo como fallido.
 */
class NoAnalyzerAvailableException extends NewsAnalysisException implements AnalyzerUnavailable {}
