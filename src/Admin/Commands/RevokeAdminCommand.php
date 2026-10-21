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

        // AdminAccess::revoke() already refuses to delete an owner row by
        // construction; detect it here so the operator sees an explicit
        // error instead of a false "revoked" success message.
        if ($this->isOwner($user)) {
            $this->error("{$email} is the protected owner and cannot be revoked.");

            return self::FAILURE;
        }

        // A CmsAdmin user has no profile row by design; its own warning below
        // covers it. Anyone else without a row has nothing to revoke.
        if (! $this->overridesProfileAccess($user) && ! $this->hasProfile($user)) {
            $this->warn("{$email} has no CMS admin access; nothing to revoke.");

            return self::SUCCESS;
        }

        $this->revokeAccess($user);

        if ($this->overridesProfileAccess($user)) {
            $this->warn("{$email} implements CmsAdmin; access is controlled by canAccessCmsAdmin(), not this profile row.");
        }

        $this->info("Revoked CMS admin access from {$email}.");

        return self::SUCCESS;
    }
}
