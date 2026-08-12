<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Tbtop\Cms\Frontend\Seo\RobotsTxt;

class RobotsController
{
    public function __construct(
        private readonly RobotsTxt $robots,
    ) {}

    public function __invoke(): Response
    {
        return response()->make($this->robots->render(), 200, ['Content-Type' => 'text/plain']);
    }
}
