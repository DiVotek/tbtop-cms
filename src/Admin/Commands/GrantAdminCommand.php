<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Commands\Concerns\InteractsWithAdminUsers;

class GrantAdminCommand extends Command
{
    use InteractsWithAdminUsers;

    protected $signature = 'cms:admin:grant {email}';

    protected $description = 'Grant CMS admin access to an existing user.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = $this->findUser($email);

        if (! $user instanceof Model) {
            $this->error("No user found for {$email}.");

            return self::FAILURE;
        }

        $this->grantAccess($user);
        $this->info("Granted CMS admin access to {$email}.");

        return self::SUCCESS;
    }
}
