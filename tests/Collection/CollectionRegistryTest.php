<?php

declare(strict_types=1);

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Tests\Fixtures\ReviewsCollection;

/** @param  list<mixed>  $fields */
function collectionNamed(string $key, array $fields = []): Collection
{
    return new class($key, $fields) extends Collection
    {
        /** @param  list<mixed>  $fieldList */
        public function __construct(private string $k, private array $fieldList) {}

        public function key(): string
        {
            return $this->k;
        }

        public function label(): string
        {
            return ucfirst($this->k);
        }

        public function fields(S $s): array
        {
            return $this->fieldList;
        }
    };
}

/** @param  list<class-string>  $classes */
function bootRegistryWith(array $classes): CollectionRegistry
{
    config(['tbtop-cms.collections' => $classes]);
    $registry = new CollectionRegistry;
    $registry->all();

    return $registry;
}

it('always has the built-in offices and socials, then the configured ones', function (): void {
    expect(array_keys(bootRegistryWith([ReviewsCollection::class])->all()))->toBe(['offices', 'socials', 'review']);
});

it('takes collections from extend()', function (): void {
    CollectionRegistry::extend(static fn (): array => [ReviewsCollection::class]);

    expect((new CollectionRegistry)->has('review'))->toBeTrue();
});

it('rejects a class that is not a collection', function (): void {
    bootRegistryWith([stdClass::class]);
})->throws(InvalidArgumentException::class, 'must extend');

it('rejects a key twice', function (): void {
    bootRegistryWith([ReviewsCollection::class, ReviewsCollection::class]);
})->throws(InvalidArgumentException::class, 'Collection [review] is already registered.');

it('rejects a malformed key', function (): void {
    $class = get_class(collectionNamed('Bad Key'));
    app()->bind($class, fn () => collectionNamed('Bad Key'));

    bootRegistryWith([$class]);
})->throws(InvalidArgumentException::class, 'must match');

it('rejects a field name the entry form owns', function (): void {
    $class = get_class(collectionNamed('x'));
    app()->bind($class, fn () => collectionNamed('things', [(new S)->text('status')]));

    bootRegistryWith([$class]);
})->throws(InvalidArgumentException::class, 'the field name "status" is reserved');

it('rejects a collection field inside a collection', function (): void {
    $class = get_class(collectionNamed('x'));
    app()->bind($class, fn () => collectionNamed('things', [(new S)->collection('more')->of('review')]));

    bootRegistryWith([$class]);
})->throws(InvalidArgumentException::class, 'cannot hold another collection field');

it('rejects a collection field nested in a repeater', function (): void {
    $class = get_class(collectionNamed('x'));
    app()->bind($class, fn () => collectionNamed('things', [
        (new S)->text('name'),
        (new S)->repeater('rows')->fields([(new S)->collection('more')->of('review')]),
    ]));

    bootRegistryWith([$class]);
})->throws(InvalidArgumentException::class, 'cannot hold another collection field');

it('rejects a title field the form does not have', function (): void {
    $class = get_class(collectionNamed('x'));
    app()->bind($class, fn () => new class extends Collection
    {
        public function key(): string
        {
            return 'things';
        }

        public function label(): string
        {
            return 'Things';
        }

        public function fields(S $s): array
        {
            return [$s->text('name')];
        }

        public function titleField(): string
        {
            return 'nmae';
        }
    });

    bootRegistryWith([$class]);
})->throws(InvalidArgumentException::class, 'title field "nmae" is not a field of its form');

it('rejects a related field that is not a pages field', function (): void {
    $class = get_class(collectionNamed('x'));
    app()->bind($class, fn () => new class extends Collection
    {
        public function key(): string
        {
            return 'things';
        }

        public function label(): string
        {
            return 'Things';
        }

        public function fields(S $s): array
        {
            return [$s->text('name')];
        }

        public function relatedField(): ?string
        {
            return 'name';
        }
    });

    bootRegistryWith([$class]);
})->throws(InvalidArgumentException::class, 'related field "name" is not a pages field of its form');

it('rejects a key that differs from another only by - and _', function (): void {
    $dash = new class extends Collection
    {
        public function key(): string
        {
            return 'team-a';
        }

        public function label(): string
        {
            return 'Team A';
        }

        public function fields(S $s): array
        {
            return [$s->text('name')];
        }
    };
    $underscore = new class extends Collection
    {
        public function key(): string
        {
            return 'team_a';
        }

        public function label(): string
        {
            return 'Team A';
        }

        public function fields(S $s): array
        {
            return [$s->text('name')];
        }
    };

    bootRegistryWith([$dash::class, $underscore::class]);
})->throws(InvalidArgumentException::class, 'Collection [team_a] clashes with [team-a]');
