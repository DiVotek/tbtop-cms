<?php

declare(strict_types=1);

return [
    'title' => 'Pages',
    'tab_description' => ':tab — categories group content, pages stand alone, articles live under a category.',
    'tabs' => [
        'all' => 'All',
        'categories' => 'Categories',
        'pages' => 'Pages',
        'articles' => 'Articles',
        'deleted' => 'Deleted',
    ],
    'tabs_deleted_description' => 'Pages moved to the trash — restore them or delete permanently.',
    'columns' => [
        'title' => 'Title',
        'type' => 'Type',
        'parent' => 'Parent',
        'status' => 'Status',
        'seo' => 'SEO',
        'updated' => 'Updated',
        'updated_by' => 'Updated by',
        'deleted_at' => 'Deleted',
    ],
    'status' => [
        'published' => 'Published',
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'expired' => 'Expired',
    ],
    'seo_state' => [
        'fail' => 'Missing',
        'warn' => 'Needs work',
        'pass' => 'Good',
    ],
    // Badge tooltip lines: what this particular page is short of. The full
    // list of checks lives on the page's own SEO tab.
    'seo_state_reasons' => [
        'title' => 'No name and no title',
        'summary' => 'No summary',
        'not_indexable' => 'Excluded from indexing',
    ],
    'types' => [
        'page' => 'Page',
        'category' => 'Category',
        'article' => 'Article',
    ],
    'date_format' => 'M j, Y',
    'empty' => [
        'title' => 'Nothing here yet',
        'description' => 'Create your first page to get started.',
    ],
    'tabs_edit' => [
        'general' => 'General',
        'seo' => 'SEO',
        'add_block' => '+ Add block',
    ],
    'sections' => [
        'content' => 'Content',
        'settings' => 'Settings',
        'publication' => 'Publication',
        'structure' => 'Structure',
        'ordering' => 'Ordering',
        'social' => 'Social networks',
        'image' => 'Image',
        'schema' => 'Structured data',
        'source' => 'Source',
    ],
    'fields' => [
        'name' => 'Name',
        'name_hint' => 'Internal name shown in the admin lists and navigation tree — not shown on the site.',
        'title' => 'Title',
        'title_hint' => 'Browser-tab and SEO title — falls back to Name when left empty.',
        'slug' => 'Slug',
        'slug_edit_hint' => 'Changing the slug changes the page URL; an automatic redirect is created from the old address.',
        'heading' => 'Heading',
        'heading_hint' => 'The H1 shown on the page itself.',
        'summary' => 'Summary',
        'body' => 'Content',
        'type' => 'Type',
        'source_id' => 'Source ID',
        'source_label' => 'Source',
        'parent' => 'Parent',
        'status' => 'Status',
        'published_at' => 'Publish at',
        'published_at_hint' => 'Leave empty to publish immediately.',
        'unpublish_at' => 'Unpublish at',
        'unpublish_at_hint' => 'Leave empty to keep published indefinitely.',
        'sort_order' => 'Sort order',
        'is_indexable' => 'Indexable in search',
        // Hints are deliberately short: a field left empty shows the value it
        // will actually resolve to as its placeholder (see
        // PageForm::computedPlaceholders()). "Falls back to Title, then Name"
        // restated in words what the field already shows, and cost three
        // lines under every input.
        'seo_title' => 'Meta title',
        'seo_title_hint' => 'The clickable headline in search results.',
        'seo_description' => 'Meta description',
        'seo_description_hint' => 'The snippet under the headline in search results.',
        'seo_focus_keyword' => 'Focus keyword',
        'seo_focus_keyword_hint' => 'The query this page should answer. Never shown on the site.',
        // The OG fields carry no hint: their placeholders show what they
        // inherit from the SEO fields above, and the "Social networks"
        // heading already says what they are for.
        'og_title' => 'OG title',
        'og_description' => 'OG description',
        'og_image' => 'OG image',
        'seo_canonical' => 'Canonical URL override',
        'seo_canonical_hint' => 'Advanced use only.',
        'image' => 'Image',
        // The structured-data fields carry only what the page itself cannot
        // supply: name, description, image and dates are filled in
        // automatically (see PageSchema).
        'schema_type' => 'Page kind',
        'schema_type_hint' => 'What a search engine understands this page to be.',
        'schema_article_type' => 'Article kind',
        'schema_article_type_hint' => 'Only changes the label; the data published is the same.',
        'schema_author' => 'Author',
        'schema_author_hint' => 'Who wrote the text. Left empty, no author is published.',
        'schema_profile_name' => 'Name',
        'schema_profile_name_hint' => 'Required: without it the page stays an ordinary one.',
        'schema_profile_alternate_name' => 'Alternate name',
        'schema_profile_alternate_name_hint' => 'A handle, or another spelling of the name.',
        'schema_profile_description' => 'About the person',
        'schema_profile_description_hint' => 'Role or short bio. Left empty, the page description is used.',
        'schema_profile_image' => 'Photo',
        'schema_profile_image_hint' => 'Left empty, the page image is used.',
        'schema_profile_same_as' => 'Profile links',
        'schema_profile_same_as_hint' => 'Full links (https://…) to this person’s profiles.',
    ],
    'schema_type' => [
        'WebPage' => 'Ordinary page',
        'AboutPage' => 'About us',
        'ContactPage' => 'Contacts',
        'ProfilePage' => 'Person profile',
        'ItemPage' => 'Item page',
    ],
    'article_schema_type' => [
        'Article' => 'Article',
        'NewsArticle' => 'News article',
        'BlogPosting' => 'Blog post',
    ],
    'actions' => [
        'new' => 'New',
        'view_on_site' => 'View on site',
        'preview_link' => 'Preview link',
        'preview_link_title' => 'Draft preview link',
        'create' => 'Create',
        'create_title' => 'New page',
        'created' => 'Page created',
        'save' => 'Save',
        'saved' => 'Page saved',
        'delete' => 'Delete',
        'delete_title' => 'Delete page?',
        'delete_confirm' => 'The page will be moved to Deleted, where you can restore it later.',
        'deleted' => 'Page deleted',
        'restore' => 'Restore',
        'restored' => 'Page restored',
        'force_delete' => 'Delete permanently',
        'force_delete_title' => 'Delete permanently?',
        'force_delete_confirm' => 'This cannot be undone.',
        'force_deleted' => 'Page deleted permanently',
        'duplicate' => 'Duplicate',
    ],
    'duplicate' => [
        'name_suffix' => '(copy)',
        'notify' => 'Page duplicated',
    ],
    'blocks' => [
        'section_title' => 'Blocks',
        'type' => 'Block type',
        'active' => 'Active',
        'sort' => 'Order',
        'add' => 'Add :kind',
        'added' => 'Block added (inactive) and saved. Configure and activate it when ready.',
        'remove' => 'Remove block',
        'remove_title' => 'Remove block?',
        'remove_confirm' => 'This cannot be undone.',
        'removed' => 'Block removed',
    ],
    'starter_blocks' => [
        'section_title' => 'Starter blocks',
        'section_hint' => 'The block structure new pages under this category start with — which block kinds, in which order. Only the structure: each block still starts empty and is filled in on the page itself. New articles and new subcategories each get their own starter — a subcategory\'s own starter overrides this one for everything under it.',
        'tabs' => [
            'article' => 'New articles',
            'category' => 'New subcategories',
        ],
        'empty' => 'No blocks yet.',
        'move_up' => 'Move up',
        'move_down' => 'Move down',
        'reordered' => 'Starter block order saved',
        'added' => 'Block added to the starter',
        'apply' => [
            'article' => 'Apply to existing articles',
            'category' => 'Apply to existing subcategories',
            'title' => 'Apply starter blocks now?',
            // :count is substituted by trans_choice() before the confirm
            // dialog ever renders — it needs the number up front, not as a
            // live placeholder, so PageEditStarterBlocks resolves it at
            // form-build time from StarterBlockSync::affectedCount().
            'confirm_zero' => 'No existing pages belong to this starter yet — nothing to update.',
            'confirm' => 'This changes the block structure on :count existing page|This changes the block structure on :count existing pages that belong to this starter: blocks the starter still defines are left exactly as they are — their content on those pages is not touched. Removing a block from the starter deletes it (and its content) from those pages — this is the only destructive part. New starter blocks are added disabled, ready to fill in. This cannot be undone.',
            'applied' => 'Starter blocks applied to :count page|Starter blocks applied to :count pages',
            'empty' => 'Add at least one block to this starter before applying it.',
        ],
    ],
    'status_bar' => [
        'updated' => 'Updated',
        'created' => 'Created',
        'scheduled_for' => 'Scheduled for :datetime',
        'expired_at' => 'Expired :datetime',
    ],
    'preview' => [
        'field_label' => 'Link',
        'helper' => 'Link is valid for :hours hours.',
        'copy' => 'Copy',
        'close' => 'Close',
    ],
    'revisions' => [
        'title' => 'Revisions',
        'empty' => 'No revisions yet.',
        'current' => 'Current',
        'author_unknown' => '—',
        'kinds' => [
            'create' => 'Created',
            'save' => 'Saved',
            'restore' => 'Restored',
        ],
        'restore' => 'Restore',
        'restore_title' => 'Restore this revision?',
        'restore_confirm' => 'Any unsaved changes in this form will be lost. The page will be reverted to this revision\'s content.',
        'restored' => 'Page restored to the selected revision',
    ],
    'conflict' => [
        // On conflict the form resyncs to the winning record (fresh
        // lock_version); the message must say the edits were dropped.
        'message' => 'This page was changed by someone else while you were editing, so your unsaved edits were not applied — the form now shows the latest version. You can restore any earlier version from Revisions.',
    ],
    'validation' => [
        'parent_cycle' => 'A page cannot be placed under itself or one of its descendants.',
        'type_unregistered' => 'Page type [:type] is not registered.',
        'type_source_backed' => 'Pages of type :type are created by their source, not by hand.',
        'status_invalid' => 'Status [:status] is not a valid page status.',
        'delete_has_children' => 'This page has child pages — move or delete them first.',
        'delete_system' => 'System pages cannot be deleted.',
        'status_change_system' => 'The status of a system page cannot be changed.',
        'error_class_immutable' => 'An error page\'s class (4xx/5xx) cannot be changed after creation.',
        'restore_parent_trashed' => 'Restore the parent page first.',
        'unpublish_before_published' => 'Unpublish date must be after the publish date.',
    ],
    'serp_preview' => [
        'section_title' => 'Search preview',
        // The collapsed header. Both numbers ride along so an overflowing
        // title is visible without opening the card — :width/:length are
        // filled in by the block, same as the meters below.
        'summary' => ':width/:widthLimit px · :length/:lengthLimit',
        'expand' => 'Show the snippet',
        'collapse' => 'Hide the snippet',
        'empty_title' => 'No title yet',
        'empty_description' => 'No description yet — search engines will pick their own text from the page.',
        'device' => [
            'desktop' => 'Desktop',
            'mobile' => 'Mobile',
        ],
        // :width/:length/:limit are filled in by the preview block itself —
        // the numbers depend on the selected device and, for the title, on
        // text measured in the browser.
        'meter' => [
            'title_width' => 'Title: :width / :limit px · :length characters',
            'description_length' => 'Description: :length / :limit characters',
            'truncated' => 'Will be cut off',
        ],
    ],
    'og_preview' => [
        'section_title' => 'Social preview',
        'empty_title' => 'No title yet',
        'empty_description' => 'No description yet.',
        'no_image_hint' => 'No image resolved — set one here, on the page image, or as the site default.',
    ],
    'seo_checklist' => [
        'section_title' => 'SEO checklist',
        // Required = the page reads badly in a result list without it.
        // Optional = an improvement to a page already fit to publish.
        'groups' => [
            'required' => 'Required',
            'optional' => 'Worth doing',
        ],
        'status' => [
            'pass' => 'OK',
            'warn' => 'Check',
            'fail' => 'Missing',
        ],
        'labels' => [
            'title' => 'Meta title',
            'description' => 'Meta description',
            'og_image' => 'OG image',
            'indexable' => 'Indexable',
            'canonical' => 'Canonical URL',
            'hreflang' => 'Hreflang coverage',
            'focus_keyword' => 'Focus keyword',
            'focus_keyword_in_title' => 'Keyword in title',
            'focus_keyword_in_description' => 'Keyword in description',
            'focus_keyword_in_heading' => 'Keyword in heading (H1)',
            'focus_keyword_in_slug' => 'Keyword in slug',
            'duplicate_title' => 'Unique title',
            'duplicate_description' => 'Unique description',
        ],
        // Length is reported by the preview meters, which measure the title in
        // pixels — these say only whether the value exists.
        'title' => [
            'fail' => 'The meta title is empty.',
            'pass' => 'Set.',
        ],
        'description' => [
            'fail' => 'The meta description is empty.',
            'pass' => 'Set.',
        ],
        'og_image' => [
            'warn' => 'No OG image resolved — set one here, on the page image, or as the site default.',
            'pass' => 'An OG image is set.',
        ],
        'indexable' => [
            'warn' => 'This published page is set to noindex — make sure that is intentional.',
            'pass' => 'The page is indexable.',
        ],
        'canonical' => [
            'warn' => 'Canonical override points to a different host (:host) than this site.',
            'pass' => 'Canonical URL points to this site.',
        ],
        'hreflang' => [
            'warn' => 'Present in :present but missing in :missing — visitors in those locales won\'t see an alternate.',
            'pass' => 'Present in every active locale (or intentionally locale-specific).',
        ],
        'focus_keyword' => [
            'empty' => 'No focus keyword set — the checks below are skipped.',
        ],
        'focus_keyword_in_title' => [
            'pass' => 'Found in the meta title.',
            'fail' => 'Not found in the meta title.',
        ],
        'focus_keyword_in_description' => [
            'pass' => 'Found in the meta description.',
            'fail' => 'Not found in the meta description.',
        ],
        'focus_keyword_in_heading' => [
            'pass' => 'Found in the heading (H1).',
            'fail' => 'Not found in the heading (H1).',
        ],
        'focus_keyword_in_slug' => [
            'pass' => 'Found in the slug.',
            'fail' => 'Not found in the slug.',
        ],
        'duplicate_title' => [
            'warn' => 'Another page resolves to the same meta title in this locale.',
            'pass' => 'No other page shares this meta title in this locale.',
        ],
        'duplicate_description' => [
            'warn' => 'Another page resolves to the same meta description in this locale.',
            'pass' => 'No other page shares this meta description in this locale.',
        ],
    ],
];
