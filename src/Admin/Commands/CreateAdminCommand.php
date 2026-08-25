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

        $this->info("Granted CMS admin access to {$email}.");

        return self::SUCCESS;
    }
}
