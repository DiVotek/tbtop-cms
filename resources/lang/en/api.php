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
        'title_missing' => 'The page has no title in ":locale", which the site shows.',
    ],
    'validation' => [
        'html_tags' => 'The HTML may use only :allowed; found: :tags.',
    ],
    'command' => [
        'no_sanctum' => 'The pages API needs laravel/sanctum: run composer require laravel/sanctum and publish its migration.',
        'no_user' => 'No user found for :user.',
        'not_admin' => ':user has no CMS admin access. Grant it with php artisan cms:admin:grant first.',
        'no_tokens' => ':model cannot hold API tokens. Add the Laravel\\Sanctum\\HasApiTokens trait to it.',
        'no_parents' => 'Name the categories the token may reach with --parents=<id,id>.',
        'unknown_parents' => 'These ids are not categories: :ids.',
        'disabled' => 'The pages API is off. Set TBTOP_CMS_API=true (tbtop-cms.api.enabled) for the token to work.',
        'issued' => 'Token for :user, limited to categories :parents. It is shown only once:',
    ],
];
