<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Settings\SiteContacts;

/**
 * The site identity node's type rules and its organisation details, read
 * from `seo.identity.*` — telephone and email from Site settings → Contacts. One place for "which schema.org type is this site"
 * so the standalone identity node and the Article author/publisher never
 * disagree about the entity they share an `@id` with.
 *
 * Every stored value is re-checked here at emit time, not only on save in
 * SeoSettingsPage — settings can be written by scripts (same rule as
 * SeoResolver::sameAs()), and a malformed value must be dropped rather than
 * published as structured data.
 */
final class SiteIdentity
{
    /** Identity types that are schema.org Organizations (logo, publisher, organisation details). */
    public const array ORGANISATION_TYPES = ['organization', 'online_store', 'local_business'];

    public const array TYPES = ['none', 'person', ...self::ORGANISATION_TYPES];

    /** The curated LocalBusiness subtypes offered in the admin; the first is the fallback. */
    public const array SUBTYPES = [
        'LocalBusiness',
        'AutoRepair',
        'ElectronicsStore',
        'Store',
        'ProfessionalService',
        'HomeAndConstructionBusiness',
        'MedicalBusiness',
        'Dentist',
        'LegalService',
        'FinancialService',
        'BeautySalon',
        'Restaurant',
        'CafeOrCoffeeShop',
        'LodgingBusiness',
        'SportsActivityLocation',
    ];

    /** The admin's subtype option that switches to the free-text custom subtype. */
    public const string CUSTOM_SUBTYPE = 'other';

    public const string SUBTYPE_PATTERN = '/^[A-Z][A-Za-z]+$/';

    public const array DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const string TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    private readonly SiteContacts $contacts;

    public function __construct(private readonly Settings $settings, ?SiteContacts $contacts = null)
    {
        $this->contacts = $contacts ?? new SiteContacts($settings);
    }

    public function type(): string
    {
        return (string) $this->settings->get('seo.identity.type', 'none');
    }

    /** Stable across locales: url('/') carries no locale prefix. */
    public static function id(): string
    {
        return url('/') . '#identity';
    }

    public function isOrganisation(): bool
    {
        return in_array($this->type(), self::ORGANISATION_TYPES, true);
    }

    /**
     * The schema.org @type of a stored identity type, before a LocalBusiness
     * subtype is chosen (see schemaType()). Null for `none`. An unrecognised
     * stored type keeps the pre-existing behaviour of the identity node
     * (anything but `person` was an Organization).
     */
    public static function schemaTypeFor(string $type): ?string
    {
        return match ($type) {
            'none' => null,
            'person' => 'Person',
            'online_store' => 'OnlineStore',
            'local_business' => self::SUBTYPES[0],
            default => 'Organization',
        };
    }

    /** schemaTypeFor() the stored type, narrowed to the chosen subtype for a local business. */
    public function schemaType(): ?string
    {
        $type = $this->type();

        return $type === 'local_business' ? $this->subtype() : self::schemaTypeFor($type);
    }

    /**
     * The organisation details beyond name/url/logo/sameAs, in node order,
     * with empty values omitted. Empty for `none`/`person`.
     *
     * @return array<string, mixed>
     */
    public function properties(string $locale, string $defaultLocale): array
    {
        if (! $this->isOrganisation()) {
            return [];
        }

        $properties = array_filter([
            'legalName' => $this->string('seo.identity.legal_name'),
            // Site settings → Contacts is the only source; the first entry is the main one.
            'telephone' => $this->contacts->phones()[0] ?? '',
            'email' => $this->contacts->emails()[0] ?? '',
        ], static fn (string $value): bool => $value !== '');

        $address = $this->address($locale, $defaultLocale);

        if ($address !== null) {
            $properties['address'] = $address;
        }

        if ($this->type() !== 'local_business') {
            return $properties;
        }

        $geo = $this->geo();

        if ($geo !== null) {
            $properties['geo'] = $geo;
        }

        $hours = $this->openingHours();

        if ($hours !== []) {
            $properties['openingHoursSpecification'] = $hours;
        }

        return $properties;
    }

    /**
     * A curated key, or the custom one when "other" was chosen — anything
     * that is not a plausible schema.org type name falls back to
     * LocalBusiness rather than publishing an invented @type.
     */
    private function subtype(): string
    {
        $subtype = $this->string('seo.identity.subtype');

        if ($subtype === self::CUSTOM_SUBTYPE) {
            $subtype = $this->string('seo.identity.subtype_custom');
        }

        if (in_array($subtype, self::SUBTYPES, true) || preg_match(self::SUBTYPE_PATTERN, $subtype) === 1) {
            return $subtype;
        }

        return self::SUBTYPES[0];
    }

    /**
     * Emitted only when street or locality is set. The translatable parts
     * fall back to the default locale's value, so a locale nobody translated
     * the address into still publishes the business's address.
     *
     * @return array<string, string>|null
     */
    private function address(string $locale, string $defaultLocale): ?array
    {
        $translated = static fn (mixed $raw): string => LocalizedSeo::valueWithFallback($raw, $locale, $defaultLocale);

        $street = $translated($this->settings->get('seo.identity.address.street'));
        $locality = $translated($this->settings->get('seo.identity.address.locality'));

        if ($street === '' && $locality === '') {
            return null;
        }

        $country = strtoupper($this->string('seo.identity.address.country'));

        return ['@type' => 'PostalAddress', ...array_filter([
            'streetAddress' => $street,
            'addressLocality' => $locality,
            'addressRegion' => $translated($this->settings->get('seo.identity.address.region')),
            'postalCode' => $this->string('seo.identity.address.postal_code'),
            'addressCountry' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : '',
        ], static fn (string $value): bool => $value !== '')];
    }

    /**
     * Both coordinates or nothing, cast to float so a stored numeric string
     * is not published as a JSON string.
     *
     * @return array{'@type': string, latitude: float, longitude: float}|null
     */
    private function geo(): ?array
    {
        $latitude = $this->settings->get('seo.identity.geo.latitude');
        $longitude = $this->settings->get('seo.identity.geo.longitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return ['@type' => 'GeoCoordinates', 'latitude' => $latitude, 'longitude' => $longitude];
    }

    /**
     * One OpeningHoursSpecification per stored row, days as schema.org day
     * URLs. Unknown days are dropped; a row left with no day, or with a time
     * that is not HH:MM, is dropped whole.
     *
     * @return list<array{'@type': string, dayOfWeek: list<string>, opens: string, closes: string}>
     */
    private function openingHours(): array
    {
        $rows = $this->settings->get('seo.identity.opening_hours', []);

        if (! is_array($rows)) {
            return [];
        }

        $specs = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $days = is_array($row['days'] ?? null) ? $row['days'] : [];
            $days = array_values(array_filter(self::DAYS, static fn (string $day): bool => in_array($day, $days, true)));
            $opens = is_string($row['opens'] ?? null) ? $row['opens'] : '';
            $closes = is_string($row['closes'] ?? null) ? $row['closes'] : '';

            if ($days === [] || preg_match(self::TIME_PATTERN, $opens) !== 1 || preg_match(self::TIME_PATTERN, $closes) !== 1) {
                continue;
            }

            $specs[] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => array_map(static fn (string $day): string => 'https://schema.org/' . $day, $days),
                'opens' => $opens,
                'closes' => $closes,
            ];
        }

        return $specs;
    }

    private function string(string $key): string
    {
        $value = $this->settings->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
