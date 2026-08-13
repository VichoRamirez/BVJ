<?php

namespace App\Services\Clustering;

final class TitleNormalizer
{
    /** @return list<string> */
    public function tokens(string $title): array
    {
        $normalized = mb_strtolower($title, 'UTF-8');
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
        $tokens = preg_split('/\s+/', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = ['a', 'al', 'con', 'de', 'del', 'el', 'en', 'la', 'las', 'los', 'para', 'por', 'un', 'una', 'y'];

        return array_values(array_unique(array_diff($tokens, $stopwords)));
    }
}
