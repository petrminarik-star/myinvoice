<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\WalletClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET    /api/admin/imports/wallet/credentials — stav (configured/not) + kurzor
 * PUT    /api/admin/imports/wallet/credentials — uloží token + test
 * DELETE /api/admin/imports/wallet/credentials — odebere token
 *
 * Body PUT: { token: string }
 *
 * Token (osobní Bearer z web.budgetbakers.com/settings/rest-api) se nikdy
 * nevrací ven (write-only). Po uložení se rovnou testuje GET /accounts —
 * 409 (Wallet po prvním tokenu synchronizuje) se hlásí jako sync_in_progress,
 * ne jako chyba.
 */
final class WalletCredentialsAction
{
    public function __construct(
        private readonly WalletClient $wallet,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, [
            'configured' => $this->wallet->getToken($supplierId) !== null,
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $token = trim((string) ($body['token'] ?? ''));
        if ($token === '') {
            return Json::error($response, 'validation_failed', 'Token je povinný.', 400);
        }
        if (strlen($token) > 1500) {
            return Json::error($response, 'validation_failed', 'Token přesahuje délkový limit.', 400);
        }

        // Test PŘED uložením — neplatný token se neukládá.
        $test = $this->wallet->testConnection($supplierId, $token);
        if (!$test['ok']) {
            return Json::ok($response, [
                'saved'      => false,
                'test_ok'    => false,
                'test_error' => $test['error'] ?? null,
            ]);
        }
        $this->wallet->setToken($supplierId, $token);

        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.wallet_credentials_set', $userId, 'supplier', $supplierId, [
            'token_prefix' => substr($token, 0, 6) . '…',
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'saved'            => true,
            'test_ok'          => true,
            'accounts'         => $test['accounts'],
            'sync_in_progress' => !empty($test['sync_in_progress']),
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $this->wallet->setToken($supplierId, '');
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.wallet_credentials_removed', $userId, 'supplier', $supplierId, null,
            $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, ['ok' => true]);
    }
}
