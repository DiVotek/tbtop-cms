<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Page\Enums\ArticleSchemaType;
use Tbtop\Cms\Page\Enums\SchemaType;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;

/**
 * Builds the schema.org object describing the page itself — the counterpart
 * to JsonLd's site-wide objects (WebSite, the identity Organization/Person)
 * and to BreadcrumbList, which describes the trail rather than the page.
 *
 * Which type a page gets is decided by its page type, not by the editor:
 * an article is an Article, a category is a CollectionPage. Only static
 * pages carry a picker, because only there is "what kind of page is this"
 * a genuine editorial question rather than a fact already in the `type`
 * column.
 *
 * Everything derivable is derived — name, description, image, dates and url
 * all come from the resolved SEO values the caller already computed, so a
 * page that fills in nothing still gets correct structured data. The extra
 * fields on the SEO tab exist only for facts the model genuinely cannot
 * supply: an article's author (created_by is an id into a host-configurable
 * user table with no FK — see TracksAuthors) and a profile page's subject.
 *
 * @phpstan-type SchemaInput array{
 *     name: string, description: string, url: string,
 *     image: string|null, profileImage: string|null,
 * }
 */
class PageSchema
{
    /**
     * How many children a CollectionPage advertises in hasPart. Sized to a
     * typical listing page rather than to the category: the point of the
     * property is to describe what this page holds, not to mirror the whole
     * subtree into every response.
     */
    private const int MAX_PARTS = 100;

    public function __construct(private readonly PageUrl $url) {}

    /**
     * @param  SchemaInput  $input
     * @return array<string, mixed>|null
     */
    public function build(Page $page, string $locale, array $input): ?array
    {
        $seo = is_array($page->seo) ? $page->seo : [];
        /** @var array<string, mixed> $schema */
        $schema = is_array($seo['schema'] ?? null) ? $seo['schema'] : [];

        return match ($page->type) {
            'article' => $this->article($page, $locale, $input, $schema),
            'category' => $this->collection($page, $locale, $input),
            default => $this->webPage($page, $locale, $input, $schema),
        };
    }

    /**
     * @param  SchemaInput  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function article(Page $page, string $locale, array $input, array $schema): array
    {
        $object = [
            ...$this->base($input, ArticleSchemaType::fromValue($schema['article_type'] ?? null)->value),
            // The canonical URL identifies the article; mainEntityOfPage says
            // "this URL is where this article primarily lives", which is what
            // distinguishes an original from a syndicated copy.
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $input['url']],
            ...$this->dates($page),
        ];

        $author = LocalizedSeo::value($schema['author'] ?? null, $locale);

        if ($author !== '') {
            $object['author'] = ['@type' => 'Person', 'name' => $author];
        }

        return $object;
    }

    /**
     * @param  SchemaInput  $input
     * @return array<string, mixed>
     */
    private function collection(Page $page, string $locale, array $input): array
    {
        $object = [...$this->base($input, 'CollectionPage'), ...$this->dates($page)];
        $parts = $this->parts($page, $locale);

        if ($parts !== []) {
            $object['hasPart'] = $parts;
        }

        return $object;
    }

    /**
     * @param  SchemaInput  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function webPage(Page $page, string $locale, array $input, array $schema): array
    {
        $type = SchemaType::fromValue($schema['type'] ?? null);
        $entity = $type === SchemaType::ProfilePage
            ? $this->profileEntity($locale, $input, $schema)
            : null;

        // A ProfilePage without mainEntity is invalid — Search Console reports
        // it as a missing required field. With no name to describe, the page
        // falls back to a plain WebPage rather than publishing a broken type.
        $effective = $type === SchemaType::ProfilePage && $entity === null
            ? SchemaType::WebPage
            : $type;

        $object = [
            ...$this->base($input, $effective->value),
            // Dates belong to every page, not only to profiles: they are
            // CreativeWork properties WebPage inherits, and "when was this
            // last touched" is what a crawler asks of any page.
            ...$this->dates($page),
        ];

        if ($entity !== null) {
            $object['mainEntity'] = $entity;
        }

        return $object;
    }

    /**
     * The dates a page can state about itself.
     *
     * datePublished is gated on isLive(), not merely on published_at being
     * set: unpublishing a page keeps its historical timestamp, and a draft or
     * expired page reached through admin preview would otherwise advertise a
     * publication date for something the public cannot see. isLive() is the
     * same visibility rule scopeLive() enforces in queries, schedule included.
     *
     * dateCreated is deliberately not emitted — created_at records when the
     * row was inserted in this CMS, which for imported or migrated content is
     * not when the work was created, and a wrong date is worse than none.
     *
     * @return array<string, string>
     */
    private function dates(Page $page): array
    {
        $dates = [];

        if ($page->published_at !== null && $page->isLive()) {
            $dates['datePublished'] = $page->published_at->toIso8601String();
        }

        if ($page->updated_at !== null) {
            $dates['dateModified'] = $page->updated_at->toIso8601String();
        }

        return $dates;
    }

    /**
     * The profile's subject. Description and image fall back to the page's
     * own: on a profile page they are, in practice, the person's — asking an
     * editor to type them twice would only create two places to keep in sync.
     *
     * @param  SchemaInput  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function profileEntity(string $locale, array $input, array $schema): ?array
    {
        $name = LocalizedSeo::value($schema['profile_name'] ?? null, $locale);

        if ($name === '') {
            return null;
        }

        $entity = ['@type' => 'Person', 'name' => $name];

        $alternateName = LocalizedSeo::value($schema['profile_alternate_name'] ?? null, $locale);

        if ($alternateName !== '') {
            $entity['alternateName'] = $alternateName;
        }

        $description = LocalizedSeo::value($schema['profile_description'] ?? null, $locale)
            ?: $input['description'];

        if ($description !== '') {
            $entity['description'] = $description;
        }

        $image = $input['profileImage'] ?? $input['image'];

        if ($image !== null && $image !== '') {
            $entity['image'] = $image;
        }

        $sameAs = $this->sameAs($schema['profile_same_as'] ?? null);

        if ($sameAs !== []) {
            $entity['sameAs'] = $sameAs;
        }

        return $entity;
    }

    /**
     * Validated at emit time rather than trusted from storage, same reasoning
     * as SeoResolver::sameAs(): the tags field only enforces "array".
     *
     * @return list<string>
     */
    private function sameAs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $url): bool => is_string($url) && ProfileUrl::valid($url),
        ));
    }

    /**
     * @param  SchemaInput  $input
     * @return array<string, mixed>
     */
    private function base(array $input, string $type): array
    {
        $object = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $input['name'],
            'url' => $input['url'],
        ];

        if ($input['description'] !== '') {
            $object['description'] = $input['description'];
        }

        if ($input['image'] !== null && $input['image'] !== '') {
            $object['image'] = $input['image'];
        }

        return $object;
    }

    /**
     * hasPart lists the category's live children — the listing a
     * CollectionPage exists to describe. Filtered through scopeLive() (the
     * single source of frontend visibility, schedule included) so a draft or
     * expired child is never advertised, and eager-loads slugs because
     * pathFor() reads the loaded relation.
     *
     * The children are not otherwise loaded by the frontend, so this is the
     * one place a schema costs a query; it is spent only for categories.
     *
     * Capped at MAX_PARTS. A category with thousands of articles would
     * otherwise load every one of them on every render and inline them into
     * the page's HTML — the listing itself is paginated, and a crawler
     * discovers the rest by following it, so an unbounded hasPart buys
     * nothing for the weight it costs.
     *
     * @return list<array{'@type': string, name: string, url: string}>
     */
    private function parts(Page $page, string $locale): array
    {
        $children = $page->children()
            ->live()
            ->where('is_indexable', true)
            ->with('slugs')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::MAX_PARTS)
            ->get();

        $parts = [];

        foreach ($children as $child) {
            $name = (string) $child->getTranslation('title', $locale, false)
                ?: (string) $child->getTranslation('name', $locale, false);
            $path = $child->pathFor($locale);

            // No slug in this locale means the child has no URL here; a
            // hasPart entry pointing at the site root would be worse than
            // omitting it.
            if ($name === '' || $path === null) {
                continue;
            }

            // Through PageUrl, same as canonical/alternates/breadcrumbs and
            // the sitemap: a bare url($path) drops the locale prefix, so a
            // /uk category would advertise its children at their default-locale
            // addresses — 404s for any child that has no default-locale slug.
            $parts[] = [
                '@type' => 'WebPage',
                'name' => $name,
                'url' => url($this->url->build($path, $locale)),
            ];
        }

        return $parts;
    }
}
