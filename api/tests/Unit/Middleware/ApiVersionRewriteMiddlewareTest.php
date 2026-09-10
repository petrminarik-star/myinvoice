<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\ApiVersionRewriteMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiVersionRewriteMiddlewareTest extends TestCase
{
    public function testInternalAuthEndpointsAreNotPublishedUnderV1(): void
    {
        foreach ([
            '/api/v1/auth/webauthn/credentials',
            '/api/v1/auth/mfa/step-up/totp',
            '/api/v1/auth/session/status',
        ] as $path) {
            $response = (new ApiVersionRewriteMiddleware())->process(
                (new ServerRequestFactory())->createServerRequest('GET', $path),
                $this->handler(),
            );
            self::assertSame(404, $response->getStatusCode(), $path);
        }
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
