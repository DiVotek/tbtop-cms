<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

/**
 * Create/edit form for host-app users from UsersPage. UI mirror of
 * cms:admin:create's assumptions (forceFill + Hash::make, name defaults to
 * email) — see CreateAdminCommand for the CLI original this parallels.
 */
class UserForm
{
    private const int MIN_PASSWORD_LENGTH = 8;

    /** @return list<Field> */
    public static function createFields(S $s): array
    {
        return [
            $s->text('email')->label(__('tbtop-cms::users.fields.email'))->required()->rules('email'),
            $s->text('name')->label(__('tbtop-cms::users.fields.name'))
                ->helperText(__('tbtop-cms::users.helpers.name_defaults_to_email')),
            $s->password('password')->label(__('tbtop-cms::users.fields.password'))->required(),
        ];
    }

    /** @return list<Field> */
    public static function editFields(S $s): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::users.fields.name'))->required(),
            $s->text('email')->label(__('tbtop-cms::users.fields.email'))->required()->rules('email'),
            $s->password('password')->label(__('tbtop-cms::users.fields.password'))
                ->helperText(__('tbtop-cms::users.helpers.password_blank_keeps')),
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  class-string<Model>  $model
     */
    public static function create(array $form, string $model): Model
    {
        $email = trim((string) ($form['email'] ?? ''));
        $name = trim((string) ($form['name'] ?? ''));
        $password = (string) ($form['password'] ?? '');

        self::assertValidEmail($email);
        self::assertEmailAvailable($model, $email, null, 'email_exists');
        self::assertPasswordLength($password);

        $user = (new $model)->forceFill([
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'password' => Hash::make($password),
        ]);
        $user->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  class-string<Model>  $model
     */
    public static function update(array $form, string $model, int | string $id): Model
    {
        $user = $model::query()->findOrFail($id);

        $email = trim((string) ($form['email'] ?? ''));
        $name = trim((string) ($form['name'] ?? ''));
        $password = (string) ($form['password'] ?? '');

        // The form marks name required, but the server must enforce it too —
        // a direct action request bypasses the client-side check. Create has
        // no such assert on purpose: there a blank name falls back to email.
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('tbtop-cms::users.validation.name_required')]);
        }

        self::assertValidEmail($email);
        self::assertEmailAvailable($model, $email, $id, 'email_taken');

        $attributes = ['name' => $name, 'email' => $email];

        // Blank password on edit means "leave it unchanged" — CreateAdminCommand's
        // safe-with-hashed-cast assumption applies here too (see UserForm docblock).
        if ($password !== '') {
            self::assertPasswordLength($password);
            $attributes['password'] = Hash::make($password);
        }

        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, mixed>
     */
    public static function loadRow(string $model, int | string $id): array
    {
        $user = $model::query()->findOrFail($id);

        return [
            'name' => (string) $user->getAttribute('name'),
            'email' => (string) $user->getAttribute('email'),
            'password' => '',
        ];
    }

    private static function assertValidEmail(string $email): void
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('tbtop-cms::users.validation.email_invalid')]);
        }
    }

    /** @param class-string<Model> $model */
    private static function assertEmailAvailable(string $model, string $email, int | string | null $excludingId, string $message): void
    {
        $query = $model::query()->where('email', $email);

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => __('tbtop-cms::users.validation.' . $message)]);
        }
    }

    private static function assertPasswordLength(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw ValidationException::withMessages(['password' => __('tbtop-cms::users.validation.password_too_short')]);
        }
    }
}
