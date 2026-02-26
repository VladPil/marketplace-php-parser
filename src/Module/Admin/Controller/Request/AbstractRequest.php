<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Request;

use Symfony\Component\HttpFoundation\Request;

abstract class AbstractRequest
{
    abstract public static function fromRequest(Request $request): static;
}
