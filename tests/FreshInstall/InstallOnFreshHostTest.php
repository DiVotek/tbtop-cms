<?php

declare(strict_types=1);

it('installs cleanly on a host that has never run a migration', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);
});
