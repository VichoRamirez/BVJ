<?php

namespace App\Support;

use App\Enums\AnalysisStatus;
use App\Enums\BriefingEdition;
use App\Enums\EntityType;
use App\Enums\NewsCategory;
use App\Enums\RelevanceLevel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Contenido de demostración para levantar el frontend antes de que exista el
 * pipeline. Devuelve objetos con la misma forma que tendrán los modelos Eloquent
 * (`Briefing`, `Event`, `Article`, `Source`, `Entity`), de modo que las vistas no
 * cambien cuando se conecte la base de datos: solo cambia quién provee los datos.
 *
 * ELIMINAR cuando existan los modelos reales (ver PLAN.md §3.2).
 *
 * @phpstan-type EventShape object{slug:string,title:string,category:NewsCategory,relevance:RelevanceLevel,summary:string,importance:string,first_seen_at:CarbonImmutable,entities:Collection,tags:array<int,string>,articles:Collection,analysis_status:AnalysisStatus}
 */
final class DemoContent
{
    /**
     * Todos los briefings publicados, del más reciente al más antiguo.
     *
     * @return Collection<int, object>
     */
    public static function briefings(): Collection
    {
        return once(function (): Collection {
            $events = self::events()->keyBy('slug');
            $today = CarbonImmutable::now('America/Santiago')->startOfDay();

            $plan = [
                ['days' => 0, 'edition' => BriefingEdition::Evening, 'events' => ['banco-central-mantiene-tpm', 'ipsa-cierra-en-maximo-historico', 'codelco-sqm-litio', 'latam-resultados-trimestre', 'peso-chileno-presion', 'datacenters-ia-chile']],
                ['days' => 0, 'edition' => BriefingEdition::Morning, 'events' => ['cobre-supera-los-cinco-dolares', 'banco-central-mantiene-tpm', 'imacec-sorprende-al-alza', 'fed-senala-recorte', 'reforma-pensiones-comision', 'cencosud-plan-expansion', 'salmon-exportaciones-record']],
                ['days' => 1, 'edition' => BriefingEdition::Evening, 'events' => ['imacec-sorprende-al-alza', 'falabella-refinancia-deuda', 'ipsa-cierra-en-maximo-historico', 'peso-chileno-presion', 'salmon-exportaciones-record']],
                ['days' => 1, 'edition' => BriefingEdition::Morning, 'events' => ['fed-senala-recorte', 'cobre-supera-los-cinco-dolares', 'reforma-pensiones-comision', 'datacenters-ia-chile']],
                ['days' => 2, 'edition' => BriefingEdition::Evening, 'events' => ['codelco-sqm-litio', 'cencosud-plan-expansion', 'latam-resultados-trimestre', 'falabella-refinancia-deuda']],
            ];

            return collect($plan)->map(function (array $row, int $index) use ($events, $today): object {
                $day = $today->subDays($row['days']);
                $publishedAt = $day->setTime($row['edition']->scheduledHour(), 0);

                return (object) [
                    'id' => $index + 1,
                    'edition' => $row['edition'],
                    'published_on' => $day,
                    'published_at' => $publishedAt,
                    'events' => collect($row['events'])
                        ->map(fn (string $slug): object => $events->get($slug))
                        ->filter()
                        ->values(),
                ];
            })->values();
        });
    }

    /**
     * El briefing más reciente, o null si todavía no se ha publicado ninguno.
     */
    public static function latestBriefing(): ?object
    {
        return self::briefings()->first();
    }

    public static function findBriefing(int $id): ?object
    {
        return self::briefings()->firstWhere('id', $id);
    }

    /**
     * Todos los eventos conocidos, ordenados por relevancia y luego por fecha.
     *
     * @return Collection<int, object>
     */
    public static function events(): Collection
    {
        return once(fn (): Collection => collect(self::eventDefinitions())
            ->map(self::hydrateEvent(...))
            ->sort(fn (object $a, object $b): int => [$b->relevance->weight(), $b->first_seen_at->timestamp]
                <=> [$a->relevance->weight(), $a->first_seen_at->timestamp])
            ->values());
    }

    public static function findEvent(string $slug): ?object
    {
        return self::events()->firstWhere('slug', $slug);
    }

    /**
     * @return Collection<int, object>
     */
    public static function eventsByCategory(NewsCategory $category): Collection
    {
        return self::events()->where('category', $category)->values();
    }

    /**
     * Cuántos eventos hay por categoría, para la navegación lateral.
     *
     * @return Collection<string, int>
     */
    public static function categoryCounts(): Collection
    {
        return self::events()
            ->groupBy(fn (object $event): string => $event->category->value)
            ->map->count();
    }

    /**
     * Instrumentos seguidos para el panel de mercado (equivalente a
     * `market_snapshots` una vez conectado Yahoo Finance).
     *
     * @return Collection<int, object>
     */
    public static function markets(): Collection
    {
        return once(fn (): Collection => collect([
            ['symbol' => '^IPSA', 'name' => 'IPSA', 'detail' => 'Bolsa de Santiago', 'unit' => 'pts', 'price' => 6842.31, 'change_percent' => 1.24, 'history' => [6612, 6588, 6640, 6701, 6688, 6733, 6760, 6742, 6798, 6842]],
            ['symbol' => 'CLP=X', 'name' => 'USD / CLP', 'detail' => 'Dólar observado', 'unit' => '$', 'price' => 942.60, 'change_percent' => -0.42, 'history' => [961, 958, 954, 957, 949, 946, 950, 947, 946, 942]],
            ['symbol' => 'HG=F', 'name' => 'Cobre', 'detail' => 'COMEX, US$/lb', 'unit' => 'US$', 'price' => 5.12, 'change_percent' => 2.87, 'history' => [4.62, 4.68, 4.71, 4.79, 4.83, 4.88, 4.94, 4.97, 5.02, 5.12]],
            ['symbol' => 'BZ=F', 'name' => 'Brent', 'detail' => 'Crudo, US$/bbl', 'unit' => 'US$', 'price' => 78.44, 'change_percent' => -1.06, 'history' => [82.1, 81.4, 80.9, 81.2, 80.1, 79.6, 79.9, 79.2, 79.3, 78.4]],
            ['symbol' => '^GSPC', 'name' => 'S&P 500', 'detail' => 'Estados Unidos', 'unit' => 'pts', 'price' => 5638.19, 'change_percent' => 0.61, 'history' => [5498, 5512, 5487, 5533, 5561, 5548, 5580, 5602, 5604, 5638]],
            ['symbol' => 'BTC-USD', 'name' => 'Bitcoin', 'detail' => 'US$', 'unit' => 'US$', 'price' => 68120.00, 'change_percent' => -3.18, 'history' => [72400, 71800, 72100, 70900, 71200, 70400, 69800, 70100, 69300, 68120]],
        ])->map(function (array $row): object {
            return (object) [
                ...$row,
                'captured_at' => CarbonImmutable::now('America/Santiago')->subMinutes(12),
            ];
        }));
    }

    /**
     * Fuentes configuradas y su estado de la última corrida. Alimenta el aviso de
     * "una fuente falló" sin voltear el resto de la página.
     *
     * @return Collection<int, object>
     */
    public static function sources(): Collection
    {
        return once(fn (): Collection => collect([
            ['name' => 'Diario Financiero', 'slug' => 'diario-financiero', 'is_active' => true, 'failure_count' => 0],
            ['name' => 'Bloomberg Línea', 'slug' => 'bloomberg-linea', 'is_active' => true, 'failure_count' => 0],
            ['name' => 'Pulso · La Tercera', 'slug' => 'pulso', 'is_active' => true, 'failure_count' => 0],
            ['name' => 'El Mercurio Inversiones', 'slug' => 'mercurio-inversiones', 'is_active' => true, 'failure_count' => 1],
            ['name' => 'Reuters', 'slug' => 'reuters', 'is_active' => false, 'failure_count' => 3],
        ])->map(fn (array $row): object => (object) $row));
    }

    private static function hydrateEvent(array $definition): object
    {
        $sources = self::sources()->keyBy('slug');
        $firstSeen = CarbonImmutable::now('America/Santiago')->subHours($definition['hours_ago']);

        $articles = collect($definition['articles'])->map(fn (array $article, int $index): object => (object) [
            'title' => $article['title'],
            'url' => $article['url'],
            'author' => $article['author'],
            'source' => $sources->get($article['source']),
            'published_at' => $firstSeen->addMinutes($index * 37),
            'analysis_status' => AnalysisStatus::Completed,
        ]);

        return (object) [
            'slug' => $definition['slug'],
            'title' => $definition['title'],
            'category' => $definition['category'],
            'relevance' => $definition['relevance'],
            'summary' => $definition['summary'],
            'importance' => $definition['importance'],
            'first_seen_at' => $firstSeen,
            'entities' => collect($definition['entities'])->map(fn (array $entity): object => (object) [
                'type' => $entity[0],
                'name' => $entity[1],
            ]),
            'tags' => $definition['tags'],
            'articles' => $articles,
            'analysis_status' => $definition['analysis_status'] ?? AnalysisStatus::Completed,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function eventDefinitions(): array
    {
        return [
            [
                'slug' => 'cobre-supera-los-cinco-dolares',
                'title' => 'El cobre supera los US$5 la libra y arrastra al peso',
                'category' => NewsCategory::Commodities,
                'relevance' => RelevanceLevel::Critical,
                'hours_ago' => 6,
                'summary' => 'El cobre cerró en Londres sobre los US$5 la libra, su nivel más alto en catorce meses, empujado por una caída de inventarios en la LME y por señales de reactivación industrial en China. Las mineras chilenas anticipan revisiones al alza en sus proyecciones de ingresos para el trimestre.',
                'importance' => 'El cobre explica cerca de la mitad de las exportaciones chilenas: cada diez centavos de dólar por libra se traducen en varios cientos de millones en recaudación fiscal y presionan a la baja el tipo de cambio. Un precio sostenido sobre US$5 cambia el escenario del presupuesto y del peso.',
                'entities' => [[EntityType::Company, 'Codelco'], [EntityType::Company, 'BHP'], [EntityType::Company, 'Antofagasta Minerals'], [EntityType::Person, 'Máximo Pacheco']],
                'tags' => ['cobre', 'LME', 'exportaciones', 'China'],
                'articles' => [
                    ['title' => 'Cobre toca máximos de 14 meses y supera los US$5 la libra', 'url' => 'https://www.df.cl/mercados/commodities/cobre-maximos-14-meses', 'author' => 'Redacción Mercados', 'source' => 'diario-financiero'],
                    ['title' => 'Copper rally extends as LME stockpiles hit multi-year low', 'url' => 'https://www.bloomberglinea.com/mercados/copper-rally-lme-stockpiles', 'author' => 'M. Herrera', 'source' => 'bloomberg-linea'],
                    ['title' => 'El repunte del cobre y su efecto en las cuentas fiscales', 'url' => 'https://www.latercera.com/pulso/cobre-cuentas-fiscales', 'author' => 'C. Vergara', 'source' => 'pulso'],
                ],
            ],
            [
                'slug' => 'banco-central-mantiene-tpm',
                'title' => 'El Banco Central mantiene la TPM en 5,0% y modera su sesgo',
                'category' => NewsCategory::Monetary,
                'relevance' => RelevanceLevel::Critical,
                'hours_ago' => 3,
                'summary' => 'El Consejo del Banco Central resolvió por unanimidad mantener la Tasa de Política Monetaria en 5,0%. El comunicado eliminó la referencia a recortes graduales y condicionó los próximos movimientos a la trayectoria de la inflación subyacente, que sigue por sobre la meta.',
                'importance' => 'La decisión define el costo del crédito para hogares y empresas en los próximos 45 días y ancla las expectativas de los depósitos a plazo. El cambio de lenguaje en el comunicado suele mover la curva de tasas más que la decisión misma.',
                'entities' => [[EntityType::Company, 'Banco Central de Chile'], [EntityType::Person, 'Rosanna Costa'], [EntityType::Company, 'Banco de Chile']],
                'tags' => ['TPM', 'inflación', 'IPoM', 'tasas'],
                'articles' => [
                    ['title' => 'Banco Central mantiene la TPM en 5% y ajusta su sesgo', 'url' => 'https://www.df.cl/economia-y-politica/banco-central-tpm-5-por-ciento', 'author' => 'Equipo Economía', 'source' => 'diario-financiero'],
                    ['title' => 'Chile central bank holds rate, drops easing guidance', 'url' => 'https://www.bloomberglinea.com/economia/chile-central-bank-holds', 'author' => 'J. Fuentes', 'source' => 'bloomberg-linea'],
                    ['title' => 'Las señales del comunicado que el mercado leyó como un freno', 'url' => 'https://www.elmercurio.com/inversiones/banco-central-comunicado', 'author' => 'P. Silva', 'source' => 'mercurio-inversiones'],
                ],
            ],
            [
                'slug' => 'imacec-sorprende-al-alza',
                'title' => 'El Imacec sorprende con un alza de 3,4% anual',
                'category' => NewsCategory::Economy,
                'relevance' => RelevanceLevel::High,
                'hours_ago' => 9,
                'summary' => 'La actividad económica creció 3,4% en doce meses, por sobre el 2,6% que esperaba el consenso de mercado. El comercio y la minería explicaron la mayor parte de la expansión, mientras la construcción siguió contrayéndose por décimo mes consecutivo.',
                'importance' => 'Un Imacec por sobre lo esperado reduce la probabilidad de recortes de tasa en el corto plazo y suele adelantar revisiones al alza en las proyecciones de crecimiento del año. La debilidad persistente de la construcción marca el punto de tensión.',
                'entities' => [[EntityType::Company, 'Banco Central de Chile'], [EntityType::Company, 'Cámara Chilena de la Construcción'], [EntityType::Person, 'Rosanna Costa']],
                'tags' => ['Imacec', 'actividad', 'crecimiento', 'construcción'],
                'articles' => [
                    ['title' => 'Imacec crece 3,4% y supera todas las proyecciones', 'url' => 'https://www.df.cl/economia-y-politica/imacec-3-4-por-ciento', 'author' => 'Equipo Economía', 'source' => 'diario-financiero'],
                    ['title' => 'La cara amarga del Imacec: la construcción no levanta', 'url' => 'https://www.latercera.com/pulso/imacec-construccion', 'author' => 'A. Muñoz', 'source' => 'pulso'],
                ],
            ],
            [
                'slug' => 'codelco-sqm-litio',
                'title' => 'Codelco y SQM cierran el acuerdo del litio en el Salar de Atacama',
                'category' => NewsCategory::Companies,
                'relevance' => RelevanceLevel::High,
                'hours_ago' => 14,
                'summary' => 'Las partes firmaron el acuerdo definitivo que da a Codelco el control mayoritario de la operación del Salar de Atacama a partir de 2031, con una transición que parte el próximo año. El pacto todavía requiere aprobaciones regulatorias en China.',
                'importance' => 'Define quién capta la renta del segundo mayor productor mundial de litio durante las próximas dos décadas y fija el modelo de asociación público-privada que el Estado replicará en los demás salares.',
                'entities' => [[EntityType::Company, 'Codelco'], [EntityType::Company, 'SQM'], [EntityType::Person, 'Máximo Pacheco'], [EntityType::Person, 'Ricardo Ramos']],
                'tags' => ['litio', 'Salar de Atacama', 'CORFO', 'joint venture'],
                'articles' => [
                    ['title' => 'Codelco y SQM firman el acuerdo definitivo por el litio', 'url' => 'https://www.df.cl/empresas/mineria/codelco-sqm-acuerdo-litio', 'author' => 'Redacción Empresas', 'source' => 'diario-financiero'],
                    ['title' => 'Codelco-SQM deal clears the way for state control of Atacama', 'url' => 'https://www.bloomberglinea.com/negocios/codelco-sqm-atacama', 'author' => 'R. Díaz', 'source' => 'bloomberg-linea'],
                ],
            ],
            [
                'slug' => 'ipsa-cierra-en-maximo-historico',
                'title' => 'El IPSA cierra en máximo histórico impulsado por la banca',
                'category' => NewsCategory::Markets,
                'relevance' => RelevanceLevel::High,
                'hours_ago' => 2,
                'summary' => 'El selectivo de la Bolsa de Santiago avanzó 1,24% hasta los 6.842 puntos, su mayor nivel desde que se calcula el índice. Los bancos y las eléctricas concentraron el 70% del volumen negociado en la jornada.',
                'importance' => 'El récord llega con volúmenes altos, lo que sugiere entrada de flujo institucional y no solo un rebote técnico. Es la referencia que usan los fondos de pensiones para su exposición local.',
                'entities' => [[EntityType::Company, 'Banco de Chile'], [EntityType::Company, 'Enel Chile'], [EntityType::Company, 'Bolsa de Santiago'], [EntityType::Company, 'Falabella']],
                'tags' => ['IPSA', 'renta variable', 'banca', 'flujos'],
                'articles' => [
                    ['title' => 'IPSA anota nuevo máximo histórico y suma seis alzas seguidas', 'url' => 'https://www.df.cl/mercados/bolsa-monedas/ipsa-maximo-historico', 'author' => 'Redacción Mercados', 'source' => 'diario-financiero'],
                    ['title' => '¿Queda recorrido? Lo que dicen las corredoras tras el récord', 'url' => 'https://www.elmercurio.com/inversiones/ipsa-recorrido-corredoras', 'author' => 'P. Silva', 'source' => 'mercurio-inversiones'],
                ],
            ],
            [
                'slug' => 'fed-senala-recorte',
                'title' => 'La Fed abre la puerta a un recorte en septiembre',
                'category' => NewsCategory::Monetary,
                'relevance' => RelevanceLevel::High,
                'hours_ago' => 20,
                'summary' => 'Las actas de la última reunión del Comité Federal de Mercado Abierto muestran que la mayoría de los miembros considera apropiado comenzar a bajar tasas si la inflación sigue cediendo. Los futuros pasaron a descontar dos recortes antes de fin de año.',
                'importance' => 'Un ciclo de recortes en Estados Unidos debilita al dólar y suele empujar flujos hacia mercados emergentes, incluido Chile. Es el principal factor externo detrás del tipo de cambio y de la bolsa local.',
                'entities' => [[EntityType::Company, 'Reserva Federal'], [EntityType::Person, 'Jerome Powell']],
                'tags' => ['Fed', 'tasas', 'dólar', 'emergentes'],
                'articles' => [
                    ['title' => 'Actas de la Fed abren la puerta a un recorte en septiembre', 'url' => 'https://www.bloomberglinea.com/mercados/fed-actas-recorte-septiembre', 'author' => 'J. Fuentes', 'source' => 'bloomberg-linea'],
                    ['title' => 'Qué significa para Chile el giro de la Reserva Federal', 'url' => 'https://www.df.cl/mercados/fed-giro-chile', 'author' => 'Equipo Mercados', 'source' => 'diario-financiero'],
                ],
            ],
            [
                'slug' => 'peso-chileno-presion',
                'title' => 'El peso se aprecia y el dólar perfora los $945',
                'category' => NewsCategory::Markets,
                'relevance' => RelevanceLevel::High,
                'hours_ago' => 4,
                'summary' => 'El tipo de cambio cerró en $942,6, con una caída de 0,42% en la jornada y de casi 2% en la semana, arrastrado por el alza del cobre y por un dólar global más débil tras las actas de la Fed.',
                'importance' => 'Un peso más fuerte abarata las importaciones y ayuda a la inflación, pero comprime los márgenes de los exportadores no cobre, en particular la agroindustria y el salmón.',
                'entities' => [[EntityType::Company, 'Banco Central de Chile'], [EntityType::Company, 'Reserva Federal']],
                'tags' => ['tipo de cambio', 'dólar', 'cobre'],
                'articles' => [
                    ['title' => 'Dólar cae bajo los $945 y acumula su peor semana del año', 'url' => 'https://www.df.cl/mercados/bolsa-monedas/dolar-cae-945', 'author' => 'Redacción Mercados', 'source' => 'diario-financiero'],
                ],
            ],
            [
                'slug' => 'reforma-pensiones-comision',
                'title' => 'La comisión mixta destraba el reparto del 6% de cotización',
                'category' => NewsCategory::Regulation,
                'relevance' => RelevanceLevel::High,
                'hours_ago' => 26,
                'summary' => 'Tras semanas de bloqueo, la comisión mixta aprobó una fórmula que divide la cotización adicional entre cuenta individual y un fondo de reparto acotado. El texto pasa ahora a la sala de ambas cámaras.',
                'importance' => 'Define el destino de varios miles de millones de dólares anuales y el tamaño futuro del mercado de capitales local, principal fuente de financiamiento de largo plazo para las empresas chilenas.',
                'entities' => [[EntityType::Company, 'AFP Habitat'], [EntityType::Company, 'Asociación de AFP'], [EntityType::Person, 'Mario Marcel']],
                'tags' => ['pensiones', 'reforma', 'mercado de capitales'],
                'articles' => [
                    ['title' => 'Comisión mixta cierra acuerdo por el 6% y despeja la reforma', 'url' => 'https://www.df.cl/economia-y-politica/comision-mixta-reforma-pensiones', 'author' => 'Equipo Política', 'source' => 'diario-financiero'],
                    ['title' => 'El acuerdo del 6%: quién gana y quién pierde', 'url' => 'https://www.latercera.com/pulso/acuerdo-6-por-ciento-pensiones', 'author' => 'A. Muñoz', 'source' => 'pulso'],
                ],
            ],
            [
                'slug' => 'latam-resultados-trimestre',
                'title' => 'Latam Airlines duplica sus utilidades trimestrales',
                'category' => NewsCategory::Companies,
                'relevance' => RelevanceLevel::Medium,
                'hours_ago' => 11,
                'summary' => 'La aerolínea reportó utilidades por US$312 millones en el trimestre, más del doble que un año atrás, con una ocupación promedio de 84% y menores costos de combustible. La compañía elevó su guidance para el año.',
                'importance' => 'Latam es uno de los papeles de mayor peso en el IPSA y su resultado suele arrastrar al índice. La mejora del guidance sugiere que la demanda de viajes en la región sigue firme pese a las tasas altas.',
                'entities' => [[EntityType::Company, 'Latam Airlines'], [EntityType::Person, 'Roberto Alvo']],
                'tags' => ['resultados', 'aerolíneas', 'IPSA'],
                'articles' => [
                    ['title' => 'Latam Airlines duplica utilidades y sube su guidance anual', 'url' => 'https://www.df.cl/empresas/latam-airlines-resultados-trimestre', 'author' => 'Redacción Empresas', 'source' => 'diario-financiero'],
                    ['title' => 'Latam beats estimates as regional travel demand holds', 'url' => 'https://www.bloomberglinea.com/negocios/latam-airlines-beats', 'author' => 'R. Díaz', 'source' => 'bloomberg-linea'],
                ],
            ],
            [
                'slug' => 'cencosud-plan-expansion',
                'title' => 'Cencosud anuncia un plan de inversión por US$1.400 millones',
                'category' => NewsCategory::Companies,
                'relevance' => RelevanceLevel::Medium,
                'hours_ago' => 30,
                'summary' => 'El retailer detalló un plan trienal centrado en Estados Unidos y Brasil, con 40 nuevas tiendas y una expansión de su plataforma logística. El 60% del capital irá fuera de Chile.',
                'importance' => 'Marca la continuidad de la estrategia de diversificación geográfica del retail chileno, que reduce su exposición al consumo local en un año de crecimiento débil.',
                'entities' => [[EntityType::Company, 'Cencosud'], [EntityType::Person, 'Rodrigo Larraín']],
                'tags' => ['retail', 'inversión', 'expansión'],
                'articles' => [
                    ['title' => 'Cencosud invertirá US$1.400 millones hasta 2029', 'url' => 'https://www.df.cl/empresas/retail/cencosud-plan-inversion', 'author' => 'Redacción Empresas', 'source' => 'diario-financiero'],
                ],
            ],
            [
                'slug' => 'falabella-refinancia-deuda',
                'title' => 'Falabella refinancia US$900 millones y mejora su perfil de vencimientos',
                'category' => NewsCategory::Companies,
                'relevance' => RelevanceLevel::Medium,
                'hours_ago' => 34,
                'summary' => 'La compañía colocó un bono internacional a diez años con una demanda que triplicó la oferta, y usará los fondos para prepagar vencimientos de 2026 y 2027.',
                'importance' => 'La operación despeja el principal riesgo de liquidez que las clasificadoras venían señalando y suele anteceder una revisión de perspectiva crediticia.',
                'entities' => [[EntityType::Company, 'Falabella'], [EntityType::Company, 'Fitch Ratings']],
                'tags' => ['deuda', 'bonos', 'refinanciamiento'],
                'articles' => [
                    ['title' => 'Falabella coloca bono por US$900 millones con demanda récord', 'url' => 'https://www.df.cl/mercados/renta-fija/falabella-bono-900-millones', 'author' => 'Equipo Mercados', 'source' => 'diario-financiero'],
                    ['title' => 'El canje de deuda que le compra tiempo a Falabella', 'url' => 'https://www.elmercurio.com/inversiones/falabella-canje-deuda', 'author' => 'P. Silva', 'source' => 'mercurio-inversiones'],
                ],
            ],
            [
                'slug' => 'datacenters-ia-chile',
                'title' => 'Nueva ola de centros de datos para IA tensiona la red eléctrica',
                'category' => NewsCategory::Technology,
                'relevance' => RelevanceLevel::Medium,
                'hours_ago' => 40,
                'summary' => 'Tres operadores internacionales presentaron proyectos de centros de datos en la Región Metropolitana y Valparaíso por más de US$2.000 millones. El Coordinador Eléctrico advirtió que la demanda proyectada exige adelantar obras de transmisión.',
                'importance' => 'La capacidad de cómputo se está volviendo un factor de localización industrial, y el cuello de botella eléctrico define si esa inversión llega a Chile o se va a otro país de la región.',
                'entities' => [[EntityType::Company, 'Coordinador Eléctrico Nacional'], [EntityType::Company, 'Amazon Web Services'], [EntityType::Company, 'Microsoft']],
                'tags' => ['datacenters', 'IA', 'energía', 'inversión extranjera'],
                'articles' => [
                    ['title' => 'Centros de datos para IA: US$2.000 millones en carpeta y una red al límite', 'url' => 'https://www.df.cl/empresas/tecnologia/datacenters-ia-red-electrica', 'author' => 'Redacción Tecnología', 'source' => 'diario-financiero'],
                ],
            ],
            [
                'slug' => 'salmon-exportaciones-record',
                'title' => 'Las exportaciones de salmón anotan su mejor julio',
                'category' => NewsCategory::Commodities,
                'relevance' => RelevanceLevel::Low,
                'hours_ago' => 46,
                'summary' => 'Los envíos alcanzaron US$620 millones en el mes, un alza de 8% anual, con Estados Unidos y Brasil como principales destinos. Los precios promedio, en cambio, cedieron 3%.',
                'importance' => 'El salmón es el segundo producto de exportación del país. El alza en volumen con precios a la baja anticipa presión de márgenes en los resultados del sector.',
                'entities' => [[EntityType::Company, 'SalmonChile'], [EntityType::Company, 'AquaChile']],
                'tags' => ['salmón', 'exportaciones', 'acuicultura'],
                'articles' => [
                    ['title' => 'Exportaciones de salmón suben 8% y marcan su mejor julio', 'url' => 'https://www.df.cl/empresas/exportaciones-salmon-julio', 'author' => 'Redacción Empresas', 'source' => 'diario-financiero'],
                ],
            ],
        ];
    }
}
