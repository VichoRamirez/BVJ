<?php

namespace App\Enums;

/**
 * Estado del análisis por LLM de un artículo. `Failed` es un estado visible en la
 * interfaz: un artículo sin análisis válido se muestra como tal, nunca a medias.
 */
enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Análisis pendiente',
            self::Completed => 'Analizado',
            self::Failed => 'Análisis fallido',
        };
    }
}
