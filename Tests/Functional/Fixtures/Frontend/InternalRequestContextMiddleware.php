<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Functional\Fixtures\Frontend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * The testing framework's json_response middlewares expect every frontend
 * request to carry a "typo3.testing.context" attribute, which only requests
 * sent through executeFrontendSubRequest() have.
 */
final class InternalRequestContextMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('typo3.testing.context') === null) {
            $request = $request->withAttribute('typo3.testing.context', new InternalRequestContext());
        }
        return $handler->handle($request);
    }
}
