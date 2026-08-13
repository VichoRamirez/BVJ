<?php

namespace App\Data;

use App\Exceptions\InvalidArticleUrlException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Artículo tal como lo devuelve un spider, antes de tocar la base de datos.
 *
 * Es deliberadamente tonto: no sabe de Eloquent ni de hashes. Quien lo persiste
 * es ScrapeSourceJob, que calcula el `url_hash` y decide si es alta o
 * actualización. Así un spider se puede probar sin base de datos.
 *
 * Solo se guarda lo mínimo para analizar: no se republica el texto con
 * copyright (CLAUDE.md §4).
 */
readonly class ScrapedArticle
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $author = null,
        public ?DateTimeImmutable $publishedAt = null,
        public ?string $excerpt = null,
        public ?string $content = null,
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('El título del artículo no puede estar vacío.');
        }

        $parsedUrl = parse_url(trim($url));

        if ($parsedUrl === false || ! in_array($parsedUrl['scheme'] ?? null, ['http', 'https'], true) || empty($parsedUrl['host'])) {
            throw new InvalidArticleUrlException('La URL del artículo debe ser absoluta y tener un host válido.');
        }
    }
}
