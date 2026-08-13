<?php

namespace App\Services\Clustering;

final class EntityNormalizer
{
    public function canonical(string $entity): string
    {
        $normalized = mb_strtolower(trim($entity), 'UTF-8');
        $normalized = class_exists('Normalizer') ? \Normalizer::normalize($normalized, \Normalizer::FORM_D) ?: $normalized : $normalized;
        $normalized = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ß',
        ]);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        foreach ([' sociedad anonima', ' sa', ' s a', ' sociedad limitada', ' limitada', ' sl', ' s l', ' inc', ' incorporated', ' ltd', ' llc'] as $suffix) {
            if (str_ends_with($normalized, $suffix) && strlen($normalized) > strlen($suffix)) {
                $normalized = trim(substr($normalized, 0, -strlen($suffix)));
                break;
            }
        }

        return $normalized;
    }

    /** @param list<string> $entities @return list<string> */
    public function canonicalize(array $entities): array
    {
        $entities = array_map(fn (string $entity): string => $this->canonical($entity), $entities);

        return array_values(array_unique(array_filter($entities)));
    }

    /**
     * Palabras que por sí solas no identifican a nadie.
     *
     * Sin esta lista, la coincidencia por subconjunto uniría "banco" con "banco
     * central" y con "banco estado", y de ahí en cascada dos acontecimientos
     * distintos que solo comparten vocabulario financiero.
     *
     * @var list<string>
     */
    private const GENERIC_TOKENS = [
        'banco', 'ministerio', 'ministro', 'ministra', 'gobierno', 'fiscalia', 'fiscal',
        'empresa', 'empresas', 'grupo', 'comision', 'corte', 'tribunal', 'juzgado',
        'sociedad', 'fondo', 'fondos', 'bolsa', 'sindicato', 'consejo', 'camara',
        'asociacion', 'federacion', 'instituto', 'agencia', 'servicio', 'superintendencia',
        'presidente', 'presidenta', 'director', 'directora', 'gerente', 'senador',
        'senadora', 'diputado', 'diputada', 'central', 'nacional', 'estado',
    ];

    /**
     * Longitud mínima de un token para que pueda identificar por sí solo.
     *
     * Evita que siglas y restos de normalización ("sa", "ex", "el") unan entidades.
     */
    private const MINIMUM_DISTINCTIVE_LENGTH = 4;

    /**
     * ¿Las dos menciones apuntan a la misma entidad?
     *
     * Además de la igualdad exacta, acepta que una mención sea subconjunto
     * estricto de la otra: los medios alternan "larrain" y "pedro pablo larrain"
     * para la misma persona, y compararlas por igualdad exacta era la causa de
     * que un mismo hecho quedara como dos acontecimientos.
     *
     * El subconjunto tiene que aportar algo distintivo: al menos un token que no
     * sea genérico y que llegue a MINIMUM_DISTINCTIVE_LENGTH.
     */
    public function matches(string $left, string $right): bool
    {
        $left = $this->canonical($left);
        $right = $this->canonical($right);

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $leftTokens = explode(' ', $left);
        $rightTokens = explode(' ', $right);

        [$shorter, $longer] = count($leftTokens) <= count($rightTokens)
            ? [$leftTokens, $rightTokens]
            : [$rightTokens, $leftTokens];

        if (array_diff($shorter, $longer) !== []) {
            return false;
        }

        return $this->distinctiveTokens($shorter) !== [];
    }

    /**
     * Menciones compartidas entre dos artículos, bajo la regla de `matches()`.
     *
     * Devuelve siempre la variante más específica de cada coincidencia ("pedro
     * pablo larrain", no "larrain"), que es la que termina guardada en el Event.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    public function sharedEntities(array $left, array $right): array
    {
        $shared = [];

        foreach ($this->canonicalize($left) as $leftEntity) {
            foreach ($this->canonicalize($right) as $rightEntity) {
                if ($this->matches($leftEntity, $rightEntity)) {
                    $shared[] = $this->mostSpecific($leftEntity, $rightEntity);
                }
            }
        }

        $shared = array_values(array_unique($shared));
        sort($shared);

        return $shared;
    }

    /**
     * De dos menciones equivalentes, la que más identifica.
     */
    public function mostSpecific(string $left, string $right): string
    {
        $leftTokens = count(explode(' ', $left));
        $rightTokens = count(explode(' ', $right));

        if ($leftTokens !== $rightTokens) {
            return $leftTokens > $rightTokens ? $left : $right;
        }

        // Empate en tokens: la más larga, y si también empatan, la primera
        // alfabéticamente, para que el resultado no dependa del orden de entrada.
        return match (true) {
            strlen($left) > strlen($right) => $left,
            strlen($right) > strlen($left) => $right,
            default => strcmp($left, $right) <= 0 ? $left : $right,
        };
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function distinctiveTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => strlen($token) >= self::MINIMUM_DISTINCTIVE_LENGTH
                && ! in_array($token, self::GENERIC_TOKENS, true),
        ));
    }
}
