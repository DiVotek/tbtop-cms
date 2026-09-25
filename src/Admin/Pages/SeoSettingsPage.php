<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Admin\Pages\Support\PageFormOptions;
use Tbtop\Cms\Frontend\Seo\GtmContainerId;
use Tbtop\Cms\Frontend\Seo\ProfileUrl;
use Tbtop\Cms\Frontend\Seo\RobotsTxt;
use Tbtop\Cms\Frontend\Seo\SeoTemplates;
use Tbtop\Cms\Frontend\Seo\SiteIdentity;
use Tbtop\Cms\Frontend\Seo\VerificationToken;
use Tbtop\Cms\Page\PageTypeRegistry;

class SeoSettingsPage extends SettingsPageBase
{
    public static function path(): string
    {
        return 'seo-settings';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.seo_settings'),
            'order' => 30,
            'icon' => 'search',
        ];
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::settings.seo.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::settings.seo.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => $this->title()],
        ];
    }

    /**
     * seo_title_suffix stays mapped (and functional under the hood in
     * SeoResolver — see its title()/titleWithSuffix()) but no longer has a
     * field below: seo_title_template replaces it in the UI, and both a
     * legacy-suffix site and one that never opens this page again must keep
     * rendering titles exactly as before. Never migrated/deleted.
     *
     * seo_identity_name/seo_identity_logo stay mapped the same way: a site's
     * name/logo now come from the site settings page by default (see
     * SeoResolver::identity()'s fallback to site.name/site.logo), so the
     * fields below it were dropped from the Identity tab — but a value saved
     * here before that change, or written by a script, must keep resolving.
     *
     * @return array<string, string>
     */
    protected function map(): array
    {
        return [
            ...self::typeTemplateMap(),
            'seo_title_suffix' => 'seo.title_suffix',
            'seo_title_template' => 'seo.title_template',
            'seo_title_separator' => 'seo.title_separator',
            'seo_description_template' => 'seo.description_template',
            'seo_default_description' => 'seo.default_description',
            'seo_default_og_image' => 'seo.default_og_image',
            'seo_indexing_enabled' => 'seo.indexing_enabled',
            'seo_robots_extra' => 'seo.robots_extra',
            'seo_sitemap_per_chunk' => 'seo.sitemap_per_chunk',
            'seo_gtm_container_id' => 'seo.gtm_container_id',
            'seo_verification_google' => 'seo.verification.google',
            'seo_verification_bing' => 'seo.verification.bing',
            'seo_identity_type' => 'seo.identity.type',
            'seo_identity_name' => 'seo.identity.name',
            'seo_identity_logo' => 'seo.identity.logo',
            'seo_identity_same_as' => 'seo.identity.same_as',
            'seo_identity_legal_name' => 'seo.identity.legal_name',
            'seo_identity_telephone' => 'seo.identity.telephone',
            'seo_identity_email' => 'seo.identity.email',
            'seo_identity_street' => 'seo.identity.address.street',
            'seo_identity_locality' => 'seo.identity.address.locality',
            'seo_identity_region' => 'seo.identity.address.region',
            'seo_identity_postal_code' => 'seo.identity.address.postal_code',
            'seo_identity_country' => 'seo.identity.address.country',
            'seo_identity_subtype' => 'seo.identity.subtype',
            'seo_identity_subtype_custom' => 'seo.identity.subtype_custom',
            'seo_identity_opening_hours' => 'seo.identity.opening_hours',
            'seo_identity_latitude' => 'seo.identity.geo.latitude',
            'seo_identity_longitude' => 'seo.identity.geo.longitude',
            'seo_twitter_card' => 'seo.twitter_card',
            'seo_not_found_enabled' => 'seo.not_found.enabled',
            'seo_not_found_retention_days' => 'seo.not_found.retention_days',
        ];
    }

    /** Mirrors SeoResolver's fallbacks for the two selects. */
    #[\Override]
    protected function defaults(): array
    {
        return ['seo_twitter_card' => 'auto', 'seo_identity_type' => 'none', 'seo_identity_subtype' => SiteIdentity::SUBTYPES[0]];
    }

    /**
     * Per-type template fields, keyed exactly as SeoTemplates reads them.
     *
     * Built from the page-type registry rather than listed by hand, so a
     * host that registers its own page type gets template fields for it
     * without touching this package.
     *
     * @return array<string, string>
     */
    private static function typeTemplateMap(): array
    {
        $map = [];

        foreach (app(PageTypeRegistry::class)->keys() as $type) {
            foreach (['title', 'description'] as $slot) {
                $map[self::typeField($type, $slot)] = SeoTemplates::key($type, $slot);
            }
        }

        return $map;
    }

    private static function typeField(string $type, string $slot): string
    {
        return "seo_type_{$type}_{$slot}";
    }

    /**
     * A title and a description template per page type. Left blank, a type
     * falls back to the site-wide templates on the previous tab — which is
     * why none of these carry a placeholder suggesting a shape: an empty
     * field here means "same as the site", not "empty title".
     *
     * The tab label supplies the heading now, so only the explanatory line
     * survives from the old subsection header.
     *
     * @return list<Field|Node|TextBlock>
     */
    private function typeTemplateFields(S $s): array
    {
        $fields = [
            $s->displayText(__('tbtop-cms::settings.seo.helpers.type_templates'))->variant('muted'),
        ];

        foreach (app(PageTypeRegistry::class)->all() as $type) {
            $label = PageFormOptions::typeLabelFor($type->key(), $type->label());

            $fields[] = $s->text(self::typeField($type->key(), 'title'))
                ->label(__('tbtop-cms::settings.seo.fields.type_title_template', ['type' => $label]))
                ->translatable()
                ->rules('nullable|max:255');
            $fields[] = $s->text(self::typeField($type->key(), 'description'))
                ->label(__('tbtop-cms::settings.seo.fields.type_description_template', ['type' => $label]))
                ->translatable()
                ->rules('nullable|max:500');
        }

        return $fields;
    }

    /**
     * One tab per topic instead of one 2600px column.
     *
     * The page had grown to 22 fields in a single scroll — four screens, with
     * the first nine sitting under no heading at all because they were
     * whatever did not fit the themed groups below them. Tabs let each topic
     * be a screenful, and the active one survives a save: settings pages
     * return a notify-only effect (see SettingsPageBase::save()), so nothing
     * remounts and the tab state stays put.
     *
     * Indexing comes first because it is the master switch — robots.txt and
     * the sitemap both follow it, so they share its tab rather than sitting
     * three screens apart as before.
     *
     * RuleWalker descends into tab bodies (Node::nestedChildren()), so
     * validation and the submitted payload are unaffected by the regrouping.
     *
     * @return list<Field|Node|TextBlock>
     */
    protected function fields(S $s): array
    {
        return [
            $s->tabs([
                ['label' => __('tbtop-cms::settings.seo.tabs.indexing'), 'children' => $this->indexingFields($s)],
                ['label' => __('tbtop-cms::settings.seo.tabs.titles'), 'children' => $this->titleFields($s)],
                ['label' => __('tbtop-cms::settings.seo.tabs.type_templates'), 'children' => $this->typeTemplateFields($s)],
                ['label' => __('tbtop-cms::settings.seo.tabs.social'), 'children' => $this->socialFields($s)],
                ['label' => __('tbtop-cms::settings.seo.tabs.identity'), 'children' => $this->identityFields($s)],
                ['label' => __('tbtop-cms::settings.seo.tabs.integrations'), 'children' => $this->integrationsFields($s)],
                ['label' => __('tbtop-cms::settings.seo.tabs.not_found'), 'children' => $this->notFoundFields($s)],
            ]),
        ];
    }

    /**
     * The master switch and the two documents that answer to it. Grouped so
     * the consequence of turning indexing off — robots.txt disallowing
     * everything, the sitemap going away — is visible in the same view as the
     * switch itself.
     *
     * @return list<Field|Node|TextBlock>
     */
    private function indexingFields(S $s): array
    {
        return [
            $s->boolean('seo_indexing_enabled')->label(__('tbtop-cms::settings.seo.fields.indexing_enabled'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.indexing_enabled')),
            // Capped at the spec's own 50 000 ceiling; the useful value is far
            // below it — see the helper text and SitemapEntryBuilder.
            $s->number('seo_sitemap_per_chunk')->label(__('tbtop-cms::settings.seo.fields.sitemap_per_chunk'))
                ->placeholder((string) config('tbtop-cms.seo.sitemap.per_chunk', 1000))
                ->helperText(__('tbtop-cms::settings.seo.helpers.sitemap_per_chunk'))
                ->rules('nullable|integer|min:1|max:50000'),
            // The owned-directive check lives in normalizeForm(), not here —
            // see guardRobotsExtra().
            $s->textarea('seo_robots_extra')->label(__('tbtop-cms::settings.seo.fields.robots_extra'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.robots_extra'))
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.robots_extra'))
                ->rules(['nullable', 'string', 'max:4000']),
        ];
    }

    /** @return list<Field|Node|TextBlock> */
    private function titleFields(S $s): array
    {
        return [
            $s->text('seo_title_template')->label(__('tbtop-cms::settings.seo.fields.title_template'))
                ->translatable()
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.title_template'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.title_template'))
                ->rules('nullable|max:255'),
            $s->text('seo_description_template')->label(__('tbtop-cms::settings.seo.fields.description_template'))
                ->translatable()
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.description_template'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.description_template'))
                ->rules('nullable|max:500'),
            $s->text('seo_title_separator')->label(__('tbtop-cms::settings.seo.fields.title_separator'))
                ->placeholder('—')
                ->helperText(__('tbtop-cms::settings.seo.helpers.title_separator'))
                ->rules('nullable|max:10'),
            $s->textarea('seo_default_description')->label(__('tbtop-cms::settings.seo.fields.default_description'))
                ->translatable()
                ->helperText(__('tbtop-cms::settings.seo.helpers.default_description'))
                ->rules('nullable|max:500'),
        ];
    }

    /** @return list<Field|Node|TextBlock> */
    private function socialFields(S $s): array
    {
        return [
            $s->media('seo_default_og_image')->label(__('tbtop-cms::settings.seo.fields.default_og_image'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.default_og_image'))
                ->variant('preview'),
            $s->select('seo_twitter_card')->label(__('tbtop-cms::settings.seo.fields.twitter_card'))
                ->options([
                    ['value' => 'auto', 'label' => __('tbtop-cms::settings.seo.twitter_card.auto')],
                    ['value' => 'summary', 'label' => __('tbtop-cms::settings.seo.twitter_card.summary')],
                    ['value' => 'summary_large_image', 'label' => __('tbtop-cms::settings.seo.twitter_card.summary_large_image')],
                ])
                ->helperText(__('tbtop-cms::settings.seo.helpers.twitter_card'))
                ->rules('nullable|in:auto,summary,summary_large_image'),
        ];
    }

    /**
     * The organisation and local-business sections are hidden, not removed,
     * for the types they do not apply to: the client still submits hidden
     * values, so their rules must tolerate a stale value — see onlyFor().
     *
     * @return list<Field|Node|TextBlock>
     */
    private function identityFields(S $s): array
    {
        $types = array_map(
            static fn (string $type): array => ['value' => $type, 'label' => __("tbtop-cms::settings.seo.identity_type.{$type}")],
            SiteIdentity::TYPES,
        );

        return [
            $s->select('seo_identity_type')->label(__('tbtop-cms::settings.seo.fields.identity_type'))
                ->options($types)
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_type'))
                ->rules('nullable|in:' . implode(',', SiteIdentity::TYPES)),
            $s->tags('seo_identity_same_as')->label(__('tbtop-cms::settings.seo.fields.identity_same_as'))
                ->rules('nullable|array')->helperText(__('tbtop-cms::settings.seo.helpers.identity_same_as')),
            $s->section([
                'title' => __('tbtop-cms::settings.seo.sections.identity_organization'),
                'hiddenIf' => Cond::notIn('seo_identity_type', SiteIdentity::ORGANISATION_TYPES),
            ], $this->organisationFields($s)),
            $s->section([
                'title' => __('tbtop-cms::settings.seo.sections.identity_local_business'),
                'hiddenIf' => Cond::neq('seo_identity_type', 'local_business'),
            ], $this->localBusinessFields($s)),
        ];
    }

    /**
     * Street and locality are required for a local business in the default
     * locale only: RuleWalker applies a translatable field's own rules to
     * the default content locale and `nullable` to the rest, and the
     * frontend falls back to the default locale for an untranslated part.
     *
     * @return list<Field|Node|TextBlock>
     */
    private function organisationFields(S $s): array
    {
        $organisation = SiteIdentity::ORGANISATION_TYPES;
        $requiredForLocalBusiness = self::onlyFor($organisation, 'nullable|required_if:seo_identity_type,local_business|max:255');

        return [
            $s->text('seo_identity_legal_name')->label(__('tbtop-cms::settings.seo.fields.identity_legal_name'))
                ->rules(self::onlyFor($organisation, 'nullable|string|max:255')),
            $s->text('seo_identity_telephone')->label(__('tbtop-cms::settings.seo.fields.identity_telephone'))
                ->placeholder('+380 44 123 4567')
                ->rules(self::onlyFor($organisation, 'nullable|string|max:50')),
            $s->text('seo_identity_email')->label(__('tbtop-cms::settings.seo.fields.identity_email'))
                ->rules(self::onlyFor($organisation, 'nullable|email|max:255')),
            $s->displayText(__('tbtop-cms::settings.seo.helpers.identity_address'))->variant('muted'),
            $s->text('seo_identity_street')->label(__('tbtop-cms::settings.seo.fields.identity_street'))
                ->translatable()
                ->rules($requiredForLocalBusiness),
            $s->text('seo_identity_locality')->label(__('tbtop-cms::settings.seo.fields.identity_locality'))
                ->translatable()
                ->rules($requiredForLocalBusiness),
            $s->text('seo_identity_region')->label(__('tbtop-cms::settings.seo.fields.identity_region'))
                ->translatable()
                ->rules(self::onlyFor($organisation, 'nullable|max:255')),
            $s->text('seo_identity_postal_code')->label(__('tbtop-cms::settings.seo.fields.identity_postal_code'))
                ->rules(self::onlyFor($organisation, 'nullable|string|max:20')),
            $s->text('seo_identity_country')->label(__('tbtop-cms::settings.seo.fields.identity_country'))
                ->placeholder('UA')
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_country'))
                ->rules(self::onlyFor($organisation, ['nullable', 'regex:/^[A-Za-z]{2}$/'])),
        ];
    }

    /**
     * The custom subtype is excluded a second time, unless "other" is the
     * chosen subtype, for the same stale-value reason as onlyFor().
     *
     * @return list<Field|Node|TextBlock>
     */
    private function localBusinessFields(S $s): array
    {
        $localBusiness = ['local_business'];

        $subtypes = array_map(
            static fn (string $subtype): array => ['value' => $subtype, 'label' => $subtype],
            SiteIdentity::SUBTYPES,
        );
        $days = array_map(
            static fn (string $day): array => ['value' => $day, 'label' => __('tbtop-cms::settings.seo.days.' . strtolower($day))],
            SiteIdentity::DAYS,
        );

        return [
            $s->select('seo_identity_subtype')->label(__('tbtop-cms::settings.seo.fields.identity_subtype'))
                ->options([...$subtypes, ['value' => SiteIdentity::CUSTOM_SUBTYPE, 'label' => __('tbtop-cms::settings.seo.identity_subtype_other')]])
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_subtype'))
                ->rules(self::onlyFor($localBusiness, 'nullable|in:' . implode(',', [...SiteIdentity::SUBTYPES, SiteIdentity::CUSTOM_SUBTYPE]))),
            $s->text('seo_identity_subtype_custom')->label(__('tbtop-cms::settings.seo.fields.identity_subtype_custom'))
                ->placeholder('Bakery')
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_subtype_custom'))
                ->hiddenIf(Cond::neq('seo_identity_subtype', SiteIdentity::CUSTOM_SUBTYPE))
                ->rules(self::onlyFor($localBusiness, [
                    'exclude_unless:seo_identity_subtype,' . SiteIdentity::CUSTOM_SUBTYPE,
                    'required_if:seo_identity_subtype,' . SiteIdentity::CUSTOM_SUBTYPE,
                    'nullable',
                    'regex:' . SiteIdentity::SUBTYPE_PATTERN,
                ])),
            $s->repeater('seo_identity_opening_hours')->label(__('tbtop-cms::settings.seo.fields.identity_opening_hours'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_opening_hours'))
                // Excluding the repeater key excludes its row keys too.
                ->rules(self::onlyFor($localBusiness, 'nullable|array'))
                ->fields([
                    $s->select('days')->label(__('tbtop-cms::settings.seo.fields.identity_days'))
                        ->multiple()
                        ->options($days)
                        ->rules('required|in:' . implode(',', SiteIdentity::DAYS)),
                    $s->time('opens')->label(__('tbtop-cms::settings.seo.fields.identity_opens'))
                        ->rules('required|date_format:H:i'),
                    $s->time('closes')->label(__('tbtop-cms::settings.seo.fields.identity_closes'))
                        ->rules('required|date_format:H:i'),
                ]),
            $s->number('seo_identity_latitude')->label(__('tbtop-cms::settings.seo.fields.identity_latitude'))
                ->step('any')
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_geo'))
                ->rules(self::onlyFor($localBusiness, 'nullable|numeric|between:-90,90')),
            $s->number('seo_identity_longitude')->label(__('tbtop-cms::settings.seo.fields.identity_longitude'))
                ->step('any')
                ->rules(self::onlyFor($localBusiness, 'nullable|numeric|between:-180,180')),
        ];
    }

    /**
     * Prefixes $rules with an exclusion unless the identity type is one of
     * $types, so a value left in a section hidden for the current type is
     * neither validated nor saved (the stored value stays as it was).
     * Exclusion must come first: Laravel checks it only after each rule has
     * run, so a rule ahead of it would already have failed on the stale value.
     *
     * @param  list<string>  $types
     * @param  string|list<string>  $rules  Pipe-joined, or a list when a rule (a regex) may contain a pipe.
     * @return list<string>
     */
    private static function onlyFor(array $types, string | array $rules): array
    {
        return [
            'exclude_unless:seo_identity_type,' . implode(',', $types),
            ...(is_string($rules) ? explode('|', $rules) : $rules),
        ];
    }

    /** @return list<Field|Node|TextBlock> */
    private function integrationsFields(S $s): array
    {
        return [
            $s->text('seo_gtm_container_id')->label(__('tbtop-cms::settings.seo.fields.gtm_container_id'))
                ->placeholder('GTM-XXXXXXX')
                ->helperText(__('tbtop-cms::settings.seo.helpers.gtm_container_id'))
                ->rules('nullable|max:2000'),
            $s->text('seo_verification_google')->label(__('tbtop-cms::settings.seo.fields.verification_google'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.verification_google'))
                ->rules('nullable|max:2000'),
            $s->text('seo_verification_bing')->label(__('tbtop-cms::settings.seo.fields.verification_bing'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.verification_bing'))
                ->rules('nullable|max:2000'),
        ];
    }

    /** @return list<Field|Node|TextBlock> */
    private function notFoundFields(S $s): array
    {
        return [
            $s->boolean('seo_not_found_enabled')->label(__('tbtop-cms::settings.seo.fields.not_found_enabled'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.not_found_enabled')),
            $s->number('seo_not_found_retention_days')->label(__('tbtop-cms::settings.seo.fields.not_found_retention_days'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.not_found_retention_days'))
                ->rules('nullable|integer|min:1'),
        ];
    }

    /**
     * Rejects lines that would fight the directives the CMS writes itself.
     *
     * Thrown from the save path rather than declared as a field rule: the DSL
     * collects rules as strings (CollectsRules::appendRules runs array_unique
     * over them), so a closure rule cannot be expressed there, and the check
     * reads the same OWNED_DIRECTIVES list the renderer uses — a regex spelled
     * out here would be a second copy free to drift.
     *
     * Rejecting rather than stripping: RobotsTxt drops these lines when it
     * renders either way, and an editor who wrote one deserves to be told why
     * it will not appear instead of finding it missing later.
     *
     * @param  array<string, mixed>  $form
     */
    private static function guardRobotsExtra(array $form): void
    {
        $value = $form['seo_robots_extra'] ?? null;

        if (! is_string($value)) {
            return;
        }

        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (RobotsTxt::claimsOwnedDirective($line)) {
                throw ValidationException::withMessages([
                    'seo_robots_extra' => __('tbtop-cms::settings.seo.validation.robots_owned', ['line' => trim($line)]),
                ]);
            }
        }
    }

    /**
     * Same save-path guard shape as guardRobotsExtra (a closure rule cannot
     * live in the DSL's string rules). Rejecting rather than storing: an
     * input that extract() could not turn into a valid token — a meta tag
     * for the OTHER verification method, or plain garbage — would be stored
     * verbatim and shown back as if it were configured, while the renderer
     * silently drops it; the admin deserves the error at save time instead.
     */
    private static function guardVerification(VerificationToken $method, string $field, string $raw): string
    {
        $token = $method->extract($raw);

        if ($token !== '' && ! $method->valid($token)) {
            throw ValidationException::withMessages([
                $field => __('tbtop-cms::settings.seo.validation.verification_unrecognized'),
            ]);
        }

        return $token;
    }

    /**
     * Both cleanups also happen on read (GtmContainerId::fromSettings()/
     * VerificationToken::fromSettings() extract and validate, sameAs()
     * filters), but normalizing here as well means a re-opened settings page
     * shows the cleaned value — the bare "GTM-…" id or verification token
     * instead of the pasted snippet, and only the profile links that will
     * actually be published (the tags field can't express a per-element url
     * rule, so non-URL entries are dropped rather than rejected).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    #[\Override]
    protected function normalizeForm(array $form): array
    {
        self::guardRobotsExtra($form);

        if (isset($form['seo_gtm_container_id']) && is_string($form['seo_gtm_container_id'])) {
            $form['seo_gtm_container_id'] = GtmContainerId::extract($form['seo_gtm_container_id']);
        }

        if (isset($form['seo_verification_google']) && is_string($form['seo_verification_google'])) {
            $form['seo_verification_google'] = self::guardVerification(VerificationToken::google(), 'seo_verification_google', $form['seo_verification_google']);
        }

        if (isset($form['seo_verification_bing']) && is_string($form['seo_verification_bing'])) {
            $form['seo_verification_bing'] = self::guardVerification(VerificationToken::bing(), 'seo_verification_bing', $form['seo_verification_bing']);
        }

        if (isset($form['seo_identity_country']) && is_string($form['seo_identity_country'])) {
            $form['seo_identity_country'] = strtoupper($form['seo_identity_country']);
        }

        if (isset($form['seo_identity_same_as']) && is_array($form['seo_identity_same_as'])) {
            $form['seo_identity_same_as'] = array_values(array_filter(
                $form['seo_identity_same_as'],
                static fn (mixed $url): bool => is_string($url) && ProfileUrl::valid($url),
            ));
        }

        return $form;
    }
}
