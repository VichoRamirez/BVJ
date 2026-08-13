<?php

namespace App\Data;

use App\Exceptions\ArticleInputTooLargeException;
use App\Exceptions\InvalidArticleUrlException;

readonly class NewsArticleInput
{
    /**
     * The optional URL is a validated article reference; this DTO never fetches it.
     */
    public function __construct(
        public string $title,
        public string $content,
        public ?string $excerpt = null,
        public ?string $url = null,
        ?NewsAnalysisLimits $limits = null,
    ) {
        $limits ??= new NewsAnalysisLimits;

        $lengths = [
            'title' => mb_strlen($title),
            'content' => mb_strlen($content),
            'excerpt' => $excerpt === null ? 0 : mb_strlen($excerpt),
        ];

        $lengthLimits = [
            'title' => $limits->title,
            'content' => $limits->content,
            'excerpt' => $limits->excerpt,
        ];

        foreach ($lengths as $field => $length) {
            if ($length > $lengthLimits[$field]) {
                throw new ArticleInputTooLargeException($field, $lengthLimits[$field]);
            }
        }

        if ($url !== null) {
            if (mb_strlen($url) > $limits->url) {
                throw new InvalidArticleUrlException('La URL del artículo supera el límite permitido.');
            }

            $parsedUrl = parse_url($url);

            if (($parsedUrl['scheme'] ?? null) !== 'https' || empty($parsedUrl['host'])) {
                throw new InvalidArticleUrlException('La URL del artículo debe usar HTTPS y tener un host válido.');
            }
        }
    }
}
