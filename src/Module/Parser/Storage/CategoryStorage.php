<?php

declare(strict_types=1);

namespace App\Module\Parser\Storage;

use App\Shared\Contract\CategoryStorageInterface;
use App\Shared\DTO\CategoryData;
use App\Shared\Infrastructure\WithPgConnectionTrait;

final class CategoryStorage implements CategoryStorageInterface
{
    use WithPgConnectionTrait;

    public function __construct(
        private readonly PgConnectionPool $pool,
    ) {}

    /** @param CategoryData[] $categories */
    public function upsertCategories(array $categories, ?string $taskId = null): array
    {
        return $this->withConnection(function (\PDO $pdo) use ($categories, $taskId): array {
            $ids = [];

            // Первый проход: вставляем/обновляем без parent_id
            $insertStmt = $pdo->prepare(
                'INSERT INTO categories (external_id, marketplace, name, depth, path, parse_task_id)
                VALUES (:external_id, :marketplace, :name, :depth, :path, :parse_task_id)
                ON CONFLICT (marketplace, external_id) DO UPDATE SET
                    name = EXCLUDED.name,
                    depth = EXCLUDED.depth,
                    path = EXCLUDED.path,
                    parse_task_id = EXCLUDED.parse_task_id
                RETURNING id'
            );

            foreach ($categories as $category) {
                $insertStmt->execute([
                    'external_id' => $category->external_id,
                    'marketplace' => $category->marketplace,
                    'name' => $category->name,
                    'depth' => $category->depth,
                    'path' => $category->path,
                    'parse_task_id' => $taskId,
                ]);
                $ids[] = (int) $insertStmt->fetchColumn();
            }

            // Второй проход: обновляем parent_id через external_id → internal id
            $updateStmt = $pdo->prepare(
                'UPDATE categories SET parent_id = (
                    SELECT id FROM categories AS p
                    WHERE p.external_id = :parent_external_id AND p.marketplace = :marketplace
                )
                WHERE external_id = :external_id AND marketplace = :marketplace'
            );

            foreach ($categories as $category) {
                if ($category->parent_external_id !== null) {
                    $updateStmt->execute([
                        'parent_external_id' => $category->parent_external_id,
                        'marketplace' => $category->marketplace,
                        'external_id' => $category->external_id,
                    ]);
                }
            }

            return $ids;
        });
    }
}
