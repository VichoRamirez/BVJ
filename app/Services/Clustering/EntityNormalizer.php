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
}
