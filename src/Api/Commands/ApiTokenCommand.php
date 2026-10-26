<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Tbtop\Cms\Admin\Commands\Concerns\InteractsWithAdminUsers;
use Tbtop\Cms\Admin\Pages\Support\PageLeafType;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Api\ApiWiring;
use Tbtop\Cms\Api\TokenScope;
use Tbtop\Cms\Page\Models\Page;

/**
 * Issues a pages-API token for a CMS admin, limited to the given categories
 * and the articles under them, and prints it once: Sanctum keeps only its hash.
 */
class ApiTokenCommand extends Command
{
    use InteractsWithAdminUsers;

    protected $signature = 'cms:api-token {user : The email of a CMS admin} {--parents= : Comma-separated ids of the categories the token may reach} {--name=cms-api : The token\'s name}';

    protected $description = 'Issue a pages-API token limited to the given categories.';

    public function handle(): int
    {
        if (! ApiWiring::isSanctumInstalled()) {
            $this->error(__('tbtop-cms::api.command.no_sanctum'));

            return self::FAILURE;
        }

        $email = (string) $this->argument('user');
        $user = $this->findUser($email);
        if (! $user instanceof Authenticatable) {
            $this->error(__('tbtop-cms::api.command.no_user', ['user' => $email]));

            return self::FAILURE;
        }
        if (! CurrentAdmin::allows($user)) {
            $this->error(__('tbtop-cms::api.command.not_admin', ['user' => $email]));

            return self::FAILURE;
        }
        if (! method_exists($user, 'createToken')) {
            $this->error(__('tbtop-cms::api.command.no_tokens', ['model' => $user::class]));

            return self::FAILURE;
        }

        $parentIds = $this->parentIds();
        if ($parentIds === null) {
            return self::FAILURE;
        }

        $scope = new TokenScope($parentIds, [PageLeafType::ARTICLE]);
        $token = $user->createToken((string) $this->option('name'), $scope->abilities());

        if (! ApiWiring::isEnabled()) {
            $this->warn(__('tbtop-cms::api.command.disabled'));
        }
        $this->info(__('tbtop-cms::api.command.issued', ['user' => $email, 'parents' => implode(', ', $parentIds)]));
        $this->line((string) $token->plainTextToken);

        return self::SUCCESS;
    }

    /** @return list<int>|null Null after naming the ids that are not categories. */
    private function parentIds(): ?array
    {
        $given = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('parents'))), static fn (string $id): bool => $id !== '')));
        if ($given === []) {
            $this->error(__('tbtop-cms::api.command.no_parents'));

            return null;
        }

        $found = Page::query()->where('type', 'category')
            ->whereIn('id', array_map('intval', array_filter($given, 'ctype_digit')))
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $unknown = array_values(array_filter($given, static fn (string $id): bool => ! ctype_digit($id) || ! in_array((int) $id, $found, true)));
        if ($unknown !== []) {
            $this->error(__('tbtop-cms::api.command.unknown_parents', ['ids' => implode(', ', $unknown)]));

            return null;
        }

        return array_map('intval', $given);
    }
}
