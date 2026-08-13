<?php

namespace App\Contracts;

/**
 * Marca los fallos que significan "este modelo no está disponible", a
 * diferencia de "este modelo respondió cualquier cosa".
 *
 * Es la frontera que decide si `FallbackNewsAnalyzer` pasa al siguiente
 * proveedor. Un timeout o un 503 no dicen nada del artículo y conviene
 * reintentar en otro modelo; un JSON que no cumple el esquema sí dice algo —
 * probablemente del prompt— y taparlo cambiando de modelo esconde el problema.
 *
 * Una excepción nueva de proveedor debe implementar esta interfaz **solo** si
 * corresponde a indisponibilidad.
 */
interface AnalyzerUnavailable {}
