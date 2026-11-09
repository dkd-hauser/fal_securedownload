<?php

declare(strict_types=1);

/*
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 */

namespace BeechIt\FalSecuredownload\Tests\Unit\Controller;

use BeechIt\FalSecuredownload\Context\UserAspect;
use BeechIt\FalSecuredownload\Controller\FileTreeStateController;
use BeechIt\FalSecuredownload\Service\LeafStateService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FileTreeStateControllerTest extends UnitTestCase
{
    private function createRequest(): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['folder' => '1:/docs/', 'open' => '1']);
        $request->method('getQueryParams')->willReturn([]);
        return $request;
    }

    #[Test]
    public function saveLeafStateSavesStateForFrontendUser(): void
    {
        $frontendUser = $this->createStub(FrontendUserAuthentication::class);
        $context = new Context();
        $context->setAspect('beechit.user', new UserAspect($frontendUser));

        $leafStateService = $this->createMock(LeafStateService::class);
        $leafStateService->expects(self::once())->method('saveLeafStateForUser')->with($frontendUser, '1:/docs/', true);

        $response = (new FileTreeStateController($context, $leafStateService))->saveLeafState($this->createRequest());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function saveLeafStateIsForbiddenWithoutFrontendUser(): void
    {
        $context = new Context();
        $context->setAspect('beechit.user', new UserAspect());

        $leafStateService = $this->createMock(LeafStateService::class);
        $leafStateService->expects(self::never())->method('saveLeafStateForUser');

        $response = (new FileTreeStateController($context, $leafStateService))->saveLeafState($this->createRequest());

        self::assertSame(403, $response->getStatusCode());
    }
}
