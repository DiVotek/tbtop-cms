<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

use Illuminate\Http\Exceptions\HttpResponseException;
use Tbtop\Cms\Api\Fields\ApiField;
use Tbtop\Cms\Api\Fields\CommonFields;
use Tbtop\Cms\Api\Fields\PageFields;

/**
 * Holds a write body to the field groups of the token. Only keys the API
 * knows are judged: an unknown key stays a validation error, and a token
 * with no group writes every field.
 */
final class FieldGroupGate
{
    /** A body key outside the token's groups is a 403 listing the paths, before anything is validated or written. */
    public static function enforce(TokenScope $scope, mixed $input, PageFields $fields): void
    {
        if ($scope->fieldGroups === [] || ! is_array($input)) {
            return;
        }

        $denied = self::denied($scope, $input, $fields);
        if ($denied !== []) {
            throw new HttpResponseException(response()->json(['message' => __('tbtop-cms::api.errors.field_denied'), 'fields' => $denied], 403));
        }
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function denied(TokenScope $scope, array $input, PageFields $fields): array
    {
        $denied = [];
        $deny = static function (string $path, FieldGroup $group) use ($scope, &$denied): void {
            if (! $scope->allowsGroup($group)) {
                $denied[] = $path;
            }
        };

        foreach (is_array($input['translations'] ?? null) ? $input['translations'] : [] as $locale => $texts) {
            foreach (is_array($texts) ? $texts : [] as $key => $value) {
                $path = "translations.{$locale}.{$key}";
                if ($key === 'seo') {
                    foreach (is_array($value) ? $value : [] as $seoKey => $_) {
                        if (in_array($seoKey, CommonFields::seoKeys(), true)) {
                            $deny("{$path}.{$seoKey}", FieldGroup::Seo);
                        }
                    }
                } elseif (is_string($key) && ($group = FieldGroup::ofTextKey($key)) instanceof FieldGroup) {
                    $deny($path, $group);
                }
            }
        }

        if (array_key_exists('og_image', $input)) {
            $deny('og_image', FieldGroup::Seo);
        }
        if (array_key_exists('image', $input)) {
            $deny('image', FieldGroup::Media);
        }

        if (array_key_exists('fields', $input)) {
            $known = array_map(static fn (ApiField $field): string => $field->key, $fields->typeFields->all());
            if (! is_array($input['fields'])) {
                $deny('fields', FieldGroup::Type);
            }
            foreach (is_array($input['fields']) ? $input['fields'] : [] as $key => $_) {
                if (in_array($key, $known, true)) {
                    $deny("fields.{$key}", FieldGroup::Type);
                }
            }
        }

        return $denied;
    }
}
