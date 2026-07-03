<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('creates an auto redirect when a page is reparented', function (): void {
    $root = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root']]);
    $mid = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Mid']]);

    $mid->update(['parent_id' => $root->id]);

    $redirect = Redirect::query()->where('from_path', 'mid')->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->to_path)->toBe('root/mid')
        ->and($redirect->locale)->toBe('en')
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->is_auto)->toBeTrue();
});

it('cascades auto redirects to descendants on reparent', function (): void {
    $root = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root']]);
    $mid = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Mid']]);
    Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $mid->id,
        'name' => ['en' => 'Post'],
    ]);

    $mid->update(['parent_id' => $root->id]);

    expect(Redirect::query()->where('from_path', 'mid')->where('to_path', 'root/mid')->exists())->toBeTrue()
        ->and(Redirect::query()->where('from_path', 'mid/post')->where('to_path', 'root/mid/post')->exists())->toBeTrue();
});

it('removes a redirect shadowed by a newly created page', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'promo', 'to_path' => 'sale', 'status_code' => 301]);

    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Promo']]);

    expect(Redirect::query()->where('from_path', 'promo')->exists())->toBeFalse();
});

it('breaks the cycle when a page moves back to its old path', function (): void {
    $root = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root']]);
    $child = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'parent_id' => $root->id,
        'name' => ['en' => 'Child'],
    ]);

    $child->update(['parent_id' => null]);
    $child->update(['parent_id' => $root->id]);

    expect(Redirect::query()->where('from_path', 'child')->where('to_path', 'root/child')->exists())->toBeTrue()
        ->and(Redirect::query()->where('from_path', 'root/child')->exists())->toBeFalse();
});

it('collapses the redirect chain when a page is reparented twice', function (): void {
    $root1 = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root1']]);
    $root2 = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Root2']]);
    $child = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Child']]);

    $child->update(['parent_id' => $root1->id]);
    $child->update(['parent_id' => $root2->id]);

    expect(Redirect::query()->where('from_path', 'child')->where('to_path', 'root2/child')->exists())->toBeTrue()
        ->and(Redirect::query()->where('from_path', 'root1/child')->exists())->toBeTrue()
        ->and(Redirect::query()->where('to_path', 'root1/child')->exists())->toBeFalse();
});
