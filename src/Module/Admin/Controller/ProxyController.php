<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Module\Parser\Config\HttpConfig;
use App\Module\Admin\Service\IdentityPoolStats;
use App\Module\Admin\Service\ProxyStatsService;
use App\Shared\Entity\Proxy;
use App\Shared\Repository\ProxyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/proxies')]
final class ProxyController extends AbstractController
{
    #[Route('/', name: 'proxy_list')]
    public function list(
        ProxyRepository $repo,
        HttpConfig $httpConfig,
        ProxyStatsService $statsService,
        IdentityPoolStats $identityPoolStats,
    ): Response {
        $adminProxies = $repo->findAll();
        $envProxies = array_map(
            static fn(string $address): array => [
                'address' => self::maskProxyCredentials($address),
                'source' => 'env',
                'type' => 'static',
                'proxyId' => md5($address),
                'proxyHost' => self::extractProxyHost($address),
            ],
            $httpConfig->proxies,
        );

        // Карта proxyId для admin-прокси (для доступа к статистике в шаблоне)
        $adminProxyIds = [];
        $allForStats = $envProxies;

        $maskedAddresses = [];
        $adminProxyHosts = [];
        foreach ($adminProxies as $proxy) {
            $adminProxyIds[$proxy->getId()] = md5($proxy->getAddress());
            $maskedAddresses[$proxy->getId()] = self::maskProxyCredentials($proxy->getAddress());
            $adminProxyHosts[$proxy->getId()] = self::extractProxyHost($proxy->getAddress());
            $allForStats[] = ['address' => $proxy->getAddress(), 'source' => 'admin', 'type' => $proxy->getType()];
        }

        $stats = $statsService->collectStats($allForStats);

        // Группировка identity по host:port прокси для отображения в таблице
        $identityStats = $identityPoolStats->getStats();
        $identitiesByProxyHost = [];
        foreach ($identityStats['identities'] as $identity) {
            $identitiesByProxyHost[$identity['proxy_host']][] = $identity;
        }

        return $this->render('proxy/list.html.twig', [
            'adminProxies' => $adminProxies,
            'envProxies' => $envProxies,
            'proxyEnabled' => $httpConfig->proxyEnabled,
            'stats' => $stats,
            'adminProxyIds' => $adminProxyIds,
            'maskedAddresses' => $maskedAddresses,
            'adminProxyHosts' => $adminProxyHosts,
            'identitiesByProxyHost' => $identitiesByProxyHost,
        ]);
    }

    #[Route('/add', name: 'proxy_add', methods: ['POST'])]
    public function add(Request $request, ProxyRepository $repo): Response
    {
        $raw = $request->request->getString('proxies');
        // Поддержка обоих форматов: по одному на строку И через запятую (как в .env)
        $raw = str_replace(',', "\n", $raw);
        $lines = array_filter(array_map('trim', explode("\n", $raw)));

        $added = 0;
        $skipped = 0;

        foreach ($lines as $address) {
            $existing = $repo->findByAddress($address);
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $type = $request->request->getString('type', 'static');
            if (!in_array($type, ['static', 'rotating'], true)) {
                $type = 'static';
            }
            $proxy = new Proxy($address, 'admin', $type);

            $rotationUrl = trim($request->request->getString('rotation_url', ''));
            if ($rotationUrl !== '') {
                $proxy->setRotationUrl($rotationUrl);
            }

            $repo->save($proxy);
            $added++;
        }

        if ($added > 0) {
            $message = sprintf('Добавлено прокси: %d', $added);
            if ($skipped > 0) {
                $message .= sprintf('. Пропущено (дубли): %d', $skipped);
            }
            $this->addFlash('success', $message);
        } elseif ($skipped > 0) {
            $this->addFlash('warning', sprintf('Все %d прокси уже существуют', $skipped));
        } else {
            $this->addFlash('error', 'Не указано ни одного прокси');
        }

        return $this->redirectToRoute('proxy_list');
    }

    #[Route('/{id}/toggle', name: 'proxy_toggle', methods: ['POST'])]
    public function toggle(int $id, ProxyRepository $repo): Response
    {
        $proxy = $repo->find($id);

        if ($proxy === null) {
            $this->addFlash('error', 'Прокси не найден');
            return $this->redirectToRoute('proxy_list');
        }

        $proxy->setEnabled(!$proxy->isEnabled());
        $repo->save($proxy);

        $status = $proxy->isEnabled() ? 'включён' : 'отключён';
        $this->addFlash('success', sprintf('Прокси %s', $status));

        return $this->redirectToRoute('proxy_list');
    }

    #[Route('/{id}/delete', name: 'proxy_delete', methods: ['POST'])]
    public function delete(int $id, ProxyRepository $repo): Response
    {
        $proxy = $repo->find($id);

        if ($proxy === null) {
            $this->addFlash('error', 'Прокси не найден');
            return $this->redirectToRoute('proxy_list');
        }

        $repo->remove($proxy);
        $this->addFlash('success', 'Прокси удалён');

        return $this->redirectToRoute('proxy_list');
    }

    #[Route('/{id}/rotation-url', name: 'proxy_edit_rotation_url', methods: ['POST'])]
    public function editRotationUrl(int $id, Request $request, ProxyRepository $repo): Response
    {
        $proxy = $repo->find($id);

        if ($proxy === null) {
            $this->addFlash('error', 'Прокси не найден');
            return $this->redirectToRoute('proxy_list');
        }

        $rotationUrl = trim($request->request->getString('rotation_url', ''));
        $proxy->setRotationUrl($rotationUrl !== '' ? $rotationUrl : null);
        $repo->save($proxy);

        $this->addFlash('success', $rotationUrl !== '' ? 'URL ротации сохранён' : 'URL ротации удалён');

        return $this->redirectToRoute('proxy_list');
    }

    /**
     * Маскирует логин и пароль в адресе прокси для безопасного отображения в UI.
     * http://user:pass@host:port → http://***:***@host:port
     */
    private static function maskProxyCredentials(string $address): string
    {
        return preg_replace('#://[^@]+@#', '://***:***@', $address);
    }

    /**
     * Извлекает host:port из URL прокси для сопоставления с identity.
     */
    private static function extractProxyHost(string $address): string
    {
        $parsed = parse_url($address);
        if ($parsed === false || !isset($parsed['host'])) {
            // Формат host:port@user:pass (legacy)
            $parts = explode('@', $address);
            return $parts[0];
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return $host . $port;
    }
}
