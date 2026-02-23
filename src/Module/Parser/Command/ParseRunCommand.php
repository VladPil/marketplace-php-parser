<?php

declare(strict_types=1);

namespace App\Module\Parser\Command;

use App\Module\Parser\Config\ParserConfig;
use App\Module\Parser\Worker\ParseWorker;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'parser:run', description: 'Запуск воркеров парсинга')]
final class ParseRunCommand extends Command
{
    public function __construct(
        private readonly ParseWorker $parseWorker,
        private readonly ParserConfig $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Запуск движка парсинга...');

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL | SWOOLE_HOOK_NATIVE_CURL);

        Coroutine\run(function () use ($output): void {
            $healthServer = new Server('0.0.0.0', $this->config->healthPort);
            $healthServer->handle('/', function ($request, $response): void {
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode(['status' => 'ok']));
            });

            Coroutine::create(function () use ($healthServer): void {
                $healthServer->start();
            });

            for ($i = 0; $i < $this->config->workers; $i++) {
                Coroutine::create(function () use ($i): void {
                    echo sprintf("[Воркер #%d] Запуск\n", $i + 1);
                    $this->parseWorker->run();
                });
            }

            $output->writeln(sprintf('Парсер запущен: %d воркеров, порт %d', $this->config->workers, $this->config->healthPort));
        });

        return Command::SUCCESS;
    }
}
