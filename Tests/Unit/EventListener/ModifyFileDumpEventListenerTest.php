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

namespace BeechIt\FalSecuredownload\Tests\Unit\EventListener;

use BeechIt\FalSecuredownload\Context\UserAspect;
use BeechIt\FalSecuredownload\EventListener\ModifyFileDumpEventListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ModifyFileDumpEventListenerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new Context();
        GeneralUtility::setSingletonInstance(Context::class, $this->context);
    }

    private function isLoggedIn(): bool
    {
        $subject = new class (
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(LinkService::class)
        ) extends ModifyFileDumpEventListener {
            public function callIsLoggedIn(): bool
            {
                return $this->isLoggedIn();
            }
        };
        $event = new ModifyFileDumpEvent(
            $this->createStub(FileInterface::class),
            $this->createStub(ServerRequestInterface::class)
        );
        (new \ReflectionProperty(ModifyFileDumpEventListener::class, 'event'))->setValue($subject, $event);
        return $subject->callIsLoggedIn();
    }

    #[Test]
    public function userIsNotLoggedInWithoutUserAspect(): void
    {
        self::assertFalse($this->isLoggedIn());
    }

    #[Test]
    public function userIsNotLoggedInWithPseudoUserInAspect(): void
    {
        $this->context->setAspect('beechit.user', new UserAspect());

        self::assertFalse($this->isLoggedIn());
    }

    #[Test]
    public function userIsLoggedInWithAuthenticatedFrontendUser(): void
    {
        $frontendUser = $this->createStub(FrontendUserAuthentication::class);
        $frontendUser->user = ['uid' => 5];
        $this->context->setAspect('beechit.user', new UserAspect($frontendUser));

        self::assertTrue($this->isLoggedIn());
    }

    #[Test]
    public function userIsNotLoggedInWithAnonymousFrontendUser(): void
    {
        $frontendUser = $this->createStub(FrontendUserAuthentication::class);
        $frontendUser->user = null;
        $this->context->setAspect('beechit.user', new UserAspect($frontendUser));

        self::assertFalse($this->isLoggedIn());
    }

    public static function getHttpRangeDataProvider(): iterable
    {
        yield 'no range header' => [null, [0, 99]];
        yield 'only dash' => ['-', [0, 99]];
        yield 'full range' => ['bytes=0-99', [0, 99]];
        yield 'start and end' => ['bytes=10-19', [10, 19]];
        yield 'end beyond file size' => ['bytes=10-500', [10, 99]];
        yield 'open end' => ['bytes=90-', [90, 99]];
        yield 'suffix length' => ['bytes=-10', [90, 99]];
        yield 'suffix length larger than file' => ['bytes=-200', []];
        yield 'start after end' => ['bytes=20-10', []];
        yield 'start beyond file size' => ['bytes=100-', []];
        yield 'neither start nor end' => ['bytes=-', []];
        yield 'invalid unit' => ['items=0-10', []];
        yield 'several ranges' => ['bytes=0-10,20-30', []];
    }

    /**
     * @param array{}|array{int, int} $expected
     */
    #[Test]
    #[DataProvider('getHttpRangeDataProvider')]
    public function getHttpRange(?string $rangeHeader, array $expected): void
    {
        unset($_SERVER['HTTP_RANGE']);
        if ($rangeHeader !== null) {
            $_SERVER['HTTP_RANGE'] = $rangeHeader;
        }
        $subject = new class (
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(LinkService::class)
        ) extends ModifyFileDumpEventListener {
            /**
             * @return array{}|array{int, int}
             */
            public function callGetHttpRange(int $fileSize): array
            {
                return $this->getHttpRange($fileSize);
            }
        };

        self::assertSame($expected, $subject->callGetHttpRange(100));
    }
}
