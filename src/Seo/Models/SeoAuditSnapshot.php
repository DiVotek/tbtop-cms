<?php

declare(strict_types=1);

namespace Tbtop\Cms\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $captured_on
 * @property int $pages_scanned
 * @property int $pages_with_issues
 * @property int $fail_count
 * @property int $warn_count
 * @property array<string, int> $issue_counts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SeoAuditSnapshot extends Model
{
    protected $table = 'tbtop_seo_audit_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'captured_on', 'pages_scanned', 'pages_with_issues', 'fail_count', 'warn_count', 'issue_counts',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'captured_on' => 'date',
        'pages_scanned' => 'integer',
        'pages_with_issues' => 'integer',
        'fail_count' => 'integer',
        'warn_count' => 'integer',
        'issue_counts' => 'array',
    ];
}
