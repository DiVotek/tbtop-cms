<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tbtop\Cms\Admin\Commands\Concerns\InteractsWithAdminUsers;

class CreateAdminCommand extends Command
{
    use InteractsWithAdminUsers;

    protected $signature = 'cms:admin:create {email} {--name=} {--password=}';

    protected $description = 'Create or reuse a user and grant CMS admin access.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = $this->findUser($email);

        if (! $user instanceof Model) {
            // Same rule as the admin Users form (UserForm::assertValidEmail).
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('The email must be a valid email address.');

                return self::FAILURE;
            }

            $providedPassword = $this->option('password');

            if (is_string($providedPassword) && $providedPassword !== '' && strlen($providedPassword) < 8) {
                $this->error('The password must be at least 8 characters.');

                return self::FAILURE;
            }

            $password = (string) ($providedPassword ?: Str::password());
            $model = $this->adminModel();
            $user = (new $model)->forceFill([
                'email' => $email,
                'name' => (string) ($this->option('name') ?: $email),
                'password' => Hash::make($password),
            ]);
            $user->getConnection()->transaction(function () use ($user): void {
                $user->save();
                $this->grantAccess($user);
            });

            if (! $providedPassword) {
                $this->info("Generated password: {$password}");
            }
        } else {
            $this->grantAccess($user);
        }

        if ($this->overridesProfileAccess($user)) {
            $this->warn("{$email} implements CmsAdmin; access is controlled by canAccessCmsAdmin(), not this profile row.");
        }

        $this->info("Granted CMS admin access to {$email}.");

        return self::SUCCESS;
    }
}
