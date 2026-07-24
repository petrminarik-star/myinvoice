<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\WalletClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/imports/wallet/start
 *
 * Body: { include_bank_accounts?: bool, include_bank_transactions?: bool,
 *         incremental?: bool, dry_run?: bool }
 *
 * Vytvoří import_jobs řádek (source='wallet', status='queued') a spustí
 * detached background worker — stejný mechanismus jako iDoklad. Kromě UI
 * tlačítka ho průběžně spouští i cron-wallet-sync (inkrementálně), takže
 * párování plateb běží samo.
 */
final class StartWalletImportAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly WalletClient $wallet,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        if ($this->wallet->getToken($supplierId) === null) {
            return Json::error($response, 'no_credentials',
                'Wallet API token není nastaven. Nastav ho v Systém → Externí integrace → Wallet.', 400);
        }

        $this->jobs->reapStale($supplierId, 'wallet');
        foreach ($this->jobs->listForTenant($supplierId, 'wallet', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "Wallet import už běží (job #{$existing['id']}, status: {$existing['status']}).",
                    409,
                    ['existing_job_id' => $existing['id']],
                );
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $params = [
            'include_bank_accounts'     => $body['include_bank_accounts'] ?? true,
            'include_bank_transactions' => $body['include_bank_transactions'] ?? true,
            'incremental'               => !empty($body['incremental']),
            'dry_run'                   => !empty($body['dry_run']),
        ];

        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($supplierId, 'wallet', $params, $userId);
        $this->spawnWorker($jobId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.wallet_started', $userId, 'import_job', $jobId, $params,
            $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'job_id' => $jobId,
            'status' => 'queued',
            'params' => $params,
        ], 201);
    }

    private function spawnWorker(int $jobId): void
    {
        // Root z Bootstrap::rootDir() — viz stejná poznámka v StartIdokladImportAction.
        $rootDir = \MyInvoice\Bootstrap::rootDir();
        \MyInvoice\Service\BackgroundProcess::spawnPhp(
            $rootDir . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            \MyInvoice\Infrastructure\Config\RuntimePaths::log('import-worker.log'),
            $rootDir,
        );
    }
}
