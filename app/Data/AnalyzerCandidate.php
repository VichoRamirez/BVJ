<?php

namespace App\Data;

use App\Contracts\NewsAnalyzer;
use Closure;
use InvalidArgumentException;

/**
 * Un eslabón de la cadena de análisis.
 *
 * El analizador va detrás de un `Closure` y no construido, por dos razones:
 *
 * 1. Los constructores validan configuración y **lanzan** si está mal
 *    (`OllamaAnalyzer` exige host loopback, `OpenRouterAnalyzer` exige API key).
 *    Construir toda la cadena por adelantado haría que un eslabón mal
 *    configurado tumbara también a los que sí funcionan.
 * 2. Si el primero responde, los demás nunca se construyen.
 *
 * La etiqueta identifica al eslabón en el log y en el cortocircuito, así que
 * tiene que ser estable entre corridas.
 */
readonly class AnalyzerCandidate
{
    /**
     * @param  Closure(): NewsAnalyzer  $resolve
     */
    public function __construct(
        public string $label,
        public Closure $resolve,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('La etiqueta del analizador no puede estar vacía.');
        }
    }
}
