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
            $password = (string) ($this->option('password') ?: Str::password());
            $model = $this->adminModel();
            $user = (new $model)->forceFill([
                'email' => $email,
                'name' => (string) ($this->option('name') ?: $email),
                'password' => Hash::make($password),
            ]);
            $user->save();

            if (! $this->option('password')) {
                $this->info("Generated password: {$password}");
            }
        }

        $this->grantAccess($user);
        $this->info("Granted CMS admin access to {$email}.");

        return self::SUCCESS;
    }
}
