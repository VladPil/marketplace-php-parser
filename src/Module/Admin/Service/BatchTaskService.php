<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use App\Module\Admin\Service\FileParser\FileParserRegistry;
use App\Module\Admin\Service\FileParser\ParsedItem;
use App\Shared\Entity\ParseTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Сервис массовой загрузки и создания задач парсинга из файла.
 */
final class BatchTaskService
{
    private const int BATCH_FLUSH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedisQueueService $queueService,
        private readonly FileParserRegistry $parserRegistry,
    ) {}

    /**
     * Валидирует файл и возвращает результат парсинга без создания задач.
     *
     * @return array{items: array<array<string, mixed>>, valid: int, invalid: int, total: int, error: string|null, hints: string[]}
     */
    public function validateFile(UploadedFile $file): array
    {
        // Проверяем сам файл
        $uploadError = $this->parserRegistry->validateUpload($file);

        if ($uploadError !== null) {
            return [
                'items' => [],
                'valid' => 0,
                'invalid' => 0,
                'total' => 0,
                'error' => $uploadError,
                'hints' => [],
            ];
        }

        // Парсим содержимое
        $result = $this->parserRegistry->parseFile($file);

        if ($result['error'] !== null) {
            return [
                'items' => array_map(
                    static fn(ParsedItem $item) => $item->toArray(),
                    array_slice($result['items'], 0, 50),
                ),
                'valid' => $result['valid'],
                'invalid' => $result['invalid'],
                'total' => $result['total'],
                'error' => $result['error'],
                'hints' => [],
            ];
        }

        $hints = $this->generateHints($result['items'], $file->getClientOriginalExtension());

        return [
            'items' => array_map(
                static fn(ParsedItem $item) => $item->toArray(),
                array_slice($result['items'], 0, 50), // Отдаём максимум 50 для предпросмотра
            ),
            'valid' => $result['valid'],
            'invalid' => $result['invalid'],
            'total' => $result['total'],
            'error' => null,
            'hints' => $hints,
        ];
    }

    /**
     * Создаёт задачи парсинга из файла.
     *
     * @return array{created: int, skipped: int, errors: array<array{line: int, error: string}>, batch_id: string|null}
     */
    public function createTasksFromFile(UploadedFile $file, string $type, string $marketplace, bool $collectProductData = false): array
    {
        $uploadError = $this->parserRegistry->validateUpload($file);

        if ($uploadError !== null) {
            return ['created' => 0, 'skipped' => 0, 'errors' => [['line' => 0, 'error' => $uploadError]], 'batch_id' => null];
        }

        $result = $this->parserRegistry->parseFile($file);

        if ($result['error'] !== null) {
            return ['created' => 0, 'skipped' => 0, 'errors' => [['line' => 0, 'error' => $result['error']]], 'batch_id' => null];
        }

        $batchId = Uuid::v4()->toRfc4122();
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($result['items'] as $index => $item) {
            if (!$item->isValid()) {
                $skipped++;

                if ($item->error !== null) {
                    $errors[] = ['line' => $item->lineNumber, 'error' => $item->error];
                }

                continue;
            }

            $params = [];

            if ($item->externalId !== null) {
                $params['external_id'] = $item->externalId;
            }

            if ($item->slug !== null) {
                $params['slug'] = $item->slug;
            }

            $task = new ParseTask();
            $task->setType($type);
            $task->setParams($params);
            $task->setMarketplace($marketplace);
            $task->setBatchId($batchId);
            $this->em->persist($task);

            $shouldPublish = true;

            if ($collectProductData && $type === 'reviews') {
                $childParams = [
                    'external_id' => $params['external_id'] ?? 0,
                    'slug' => $params['slug'] ?? '',
                    'skip_reviews' => true,
                ];

                $child = new ParseTask();
                $child->setType('product');
                $child->setParams($childParams);
                $child->setMarketplace($marketplace);
                $child->setBatchId($batchId);
                $child->setParentTaskId($task->getId());

                $this->em->persist($child);
            $this->queueService->publishTask([
                    'id' => $child->getId(),
                    'type' => 'product',
                    'params' => array_merge($childParams, ['marketplace' => $marketplace]),
                    'marketplace' => $marketplace,
                ]);

                $shouldPublish = false;
            }

            if ($shouldPublish) {
                $this->queueService->publishTask([
                    'id' => $task->getId(),
                    'type' => $type,
                    'params' => array_merge($params, ['marketplace' => $marketplace]),
                    'marketplace' => $marketplace,
                ]);
            }

            $created++;

            // Батчевый flush для экономии памяти
            if (($index + 1) % self::BATCH_FLUSH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        if ($created > 0) {
            $this->em->flush();
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'batch_id' => $created > 0 ? $batchId : null];
    }

    /**
     * Формирует подсказки по формату файла.
     *
     * @param ParsedItem[] $items
     * @return string[]
     */
    private function generateHints(array $items, string $extension): array
    {
        $hints = [];
        $invalidItems = array_filter($items, static fn(ParsedItem $item) => !$item->isValid());
        $validItems = array_filter($items, static fn(ParsedItem $item) => $item->isValid());

        if (count($items) === 0) {
            $hints[] = 'Файл пуст или не содержит данных.';

            return $hints;
        }

        if (count($invalidItems) === count($items)) {
            $hints[] = 'Ни одна запись не распознана. Убедитесь, что файл содержит ID товаров маркетплейса (числа от 5 цифр), URL или slug.';
        }

        if (count($invalidItems) > 0 && count($validItems) > 0) {
            $hints[] = sprintf(
                '%d из %d записей не распознаны и будут пропущены при создании задач.',
                count($invalidItems),
                count($items),
            );
        }

        $duplicates = array_filter($invalidItems, static fn(ParsedItem $item) => str_starts_with($item->error ?? '', 'Дубликат'));

        if (count($duplicates) > 0) {
            $hints[] = sprintf('Найдено %d дубликатов — они будут пропущены.', count($duplicates));
        }

        // Подсказки по формату
        $extension = strtolower($extension);

        if ($extension === 'csv') {
            $hints[] = 'CSV: каждая строка — один товар. Первый столбец: URL маркетплейса, external_id или slug.';
        } elseif ($extension === 'json') {
            $hints[] = 'JSON: массив значений [123, "slug-123", "https://..."] или объект {"items": [...]}.';
        } elseif ($extension === 'txt') {
            $hints[] = 'TXT: одно значение на строку — URL маркетплейса, external_id или slug.';
        }

        return $hints;
    }
}
