<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Commands\Concerns\InteractsWithAdminUsers;

class RevokeAdminCommand extends Command
{
    use InteractsWithAdminUsers;

    protected $signature = 'cms:admin:revoke {email}';

    protected $description = 'Revoke CMS admin access from a user.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = $this->findUser($email);

        if (! $user instanceof Model) {
            $this->error("No user found for {$email}.");

            return self::FAILURE;
        }

        $this->revokeAccess($user);

        if ($this->overridesProfileAccess($user)) {
            $this->warn("{$email} implements CmsAdmin; access is controlled by canAccessCmsAdmin(), not this profile row.");
        }

        $this->info("Revoked CMS admin access from {$email}.");

        return self::SUCCESS;
    }
}
