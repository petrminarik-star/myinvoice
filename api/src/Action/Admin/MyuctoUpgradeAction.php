<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Upgrade\MyuctoUpgradeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin endpointy pro přechod na MyÚčto.cz:
 *   GET  /api/admin/myucto-upgrade/status     — stav (verze, prostředí, průběh, záloha)
 *   GET  /api/admin/myucto-upgrade/preflight  — zvládne to tahle instalace sama?
 *   POST /api/admin/myucto-upgrade/refresh    — fresh fetch verze z GitHub Releases
 *   POST /api/admin/myucto-upgrade/trigger    — spustit přechod (vyžaduje potvrzení zálohy)
 *   POST /api/admin/myucto-upgrade/cancel     — zrušit zaseknutý flag
 *
 * Záměrně oddělené od {@see UpdateAction}: aktualizace MyInvoice a přechod na
 * nástupce mají jiný dopad i jinou cestu zpátky, a míchat je do jednoho stavu
 * by znamenalo, že běžící jedno vypadá jako běžící druhé.
 */
final class MyuctoUpgradeAction
{
    public function __construct(
        private readonly MyuctoUpgradeService $upgrade,
        private readonly ActivityLogger $logger,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;

        return Json::ok($response, $this->upgrade->getStatus());
    }

    /**
     * Preflight je samostatný endpoint (ne součást `status`), protože zapisuje
     * probe soubory kvůli kontrole práv — nemá běžet při každém pollu.
     */
    public function preflight(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $target = $request->getQueryParams()['target_version'] ?? null;

        return Json::ok($response, $this->upgrade->preflight(
            is_string($target) && $target !== '' ? $target : null
        ));
    }

    public function refresh(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;

        return Json::ok($response, $this->upgrade->refreshLatestVersion());
    }

    public function trigger(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $body = (array) ($request->getParsedBody() ?? []);

        $target = isset($body['target_version']) ? (string) $body['target_version'] : null;
        $backupConfirmed = filter_var(
            $body['backup_confirmed'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $result = $this->upgrade->trigger($target, (string) ($user['email'] ?? 'unknown'), $backupConfirmed);

        $this->logger->log(
            'system.myucto_upgrade.trigger',
            (int) ($user['id'] ?? 0),
            null,
            null,
            [
                'environment'      => $result['environment'] ?? null,
                'target_version'   => $result['target_version'] ?? null,
                'status'           => $result['status'] ?? null,
                'backup_confirmed' => $backupConfirmed,
            ]
        );

        return Json::ok($response, $result);
    }

    public function cancel(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) return $err;
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $result = $this->upgrade->cancel();
        $this->logger->log('system.myucto_upgrade.cancel', (int) ($user['id'] ?? 0), null, null, [
            'cleared' => $result['cleared'] ?? false,
        ]);

        return Json::ok($response, $result);
    }

    private function isAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!is_array($user) || ($user['role'] ?? '') !== 'admin') {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);

            return false;
        }
        $err = null;

        return true;
    }
}
