<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller\Request\Trait;

trait PaginationTrait
{
    public readonly int $page;
    public readonly int $limit;

    private function initPagination(int $page = 1, int $limit = 50): void
    {
        $this->page = max(1, $page);
        $this->limit = min(max(1, $limit), 200);
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
