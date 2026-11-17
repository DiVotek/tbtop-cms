<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_found' => 'No such page.',
        'out_of_scope' => 'This token may not reach this page.',
        'not_admin' => 'The token\'s user has no access to the CMS.',
        'type_unknown' => 'No such page type.',
        'already_published' => 'The page is already published.',
        'delete_published' => 'Only a draft can be deleted through the API. Unpublish the page in the admin first.',
        'name_missing' => 'The page has no name in ":locale", which the site shows.',
        'field_denied' => 'This token may not write these fields.',
    ],
    'validation' => [
        'html_tags' => 'The HTML may use only :allowed; found: :tags.',
        'html_attributes' => 'The HTML may carry no attributes but a link\'s href; found: :attributes.',
        'html_links' => 'A link may point only to an http, https, mailto or tel URL or a path on the site; found: :links.',
        'html_head' => 'The HTML must be body content only: no title, meta, style or other head elements.',
        'unknown_key' => 'The API does not accept :key.',
        'system_slug' => 'A system page keeps its fixed address; its slug cannot be changed.',
    ],
    'command' => [
        'no_sanctum' => 'The pages API needs laravel/sanctum: run composer require laravel/sanctum and publish its migration.',
        'no_user' => 'No user found for :user.',
        'not_admin' => ':user has no CMS admin access. Grant it with php artisan cms:admin:grant first.',
        'no_tokens' => ':model cannot hold API tokens. Add the Laravel\\Sanctum\\HasApiTokens trait to it.',
        'unknown_parents' => 'These ids are not categories: :ids.',
        'disabled' => 'The pages API is off. Set TBTOP_CMS_API=true (tbtop-cms.api.enabled) for the token to work.',
        'no_scope' => 'Name the pages the token may reach with --pages, --trees or --parents.',
        'issued' => 'Token for :user: :scope',
        'shown_once' => 'It is shown only once:',
        'unknown_ids' => 'These ids in --:option are not valid pages: :ids.',
        'unknown_values' => 'Unknown value in --:option: :values. Allowed: :allowed.',
        'create_needs_text' => 'Creating needs the text field group (the page name).',
        'create_needs_type' => 'Creating needs the type field group: the article type has required fields.',
        'system_destructive' => 'Pages :ids are system pages: publishing or deleting them is refused with 409.',
        'scope_pages' => 'pages :value',
        'scope_trees' => 'trees :value',
        'scope_parents' => 'categories :value',
        'scope_types' => 'types :value',
        'scope_actions' => 'actions :value',
        'scope_fields' => 'fields :value',
        'all_fields' => 'all',
    ],
];
