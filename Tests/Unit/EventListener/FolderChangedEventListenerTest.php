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

use BeechIt\FalSecuredownload\EventListener\FolderChangedEventListener;
use BeechIt\FalSecuredownload\Service\Utility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Resource\Event\AfterFolderMovedEvent;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FolderChangedEventListenerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private Utility&MockObject $utility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->utility = $this->createMock(Utility::class);
        GeneralUtility::setSingletonInstance(Utility::class, $this->utility);
    }

    private function createFolder(string $identifier, int $storageUid = 1): Folder
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn($storageUid);

        $folder = $this->createStub(Folder::class);
        $folder->method('getIdentifier')->willReturn($identifier);
        $folder->method('getHashedIdentifier')->willReturn(sha1($identifier));
        $folder->method('getCombinedIdentifier')->willReturn($storageUid . ':' . $identifier);
        $folder->method('getStorage')->willReturn($storage);
        return $folder;
    }

    #[Test]
    public function postFolderMoveUpdatesFolderRecord(): void
    {
        $folder = $this->createFolder('/old/');
        $targetFolder = $this->createFolder('/target/old/', 2);

        $this->utility->expects(self::once())->method('updateFolderRecord')->with(
            1,
            sha1('/old/'),
            '/old/',
            [
                'storage' => 2,
                'folder_hash' => sha1('/target/old/'),
                'folder' => '/target/old/',
            ]
        );

        (new FolderChangedEventListener())->postFolderMove(
            new AfterFolderMovedEvent($folder, $this->createFolder('/target/', 2), $targetFolder)
        );
    }

    #[Test]
    public function postFolderMoveSkipsUpdateWithoutTargetFolder(): void
    {
        $this->utility->expects(self::never())->method('updateFolderRecord');

        (new FolderChangedEventListener())->postFolderMove(
            new AfterFolderMovedEvent($this->createFolder('/old/'), $this->createFolder('/target/'), null)
        );
    }

    #[Test]
    public function postFolderMoveSkipsUpdateForTargetFolderOtherThanFolder(): void
    {
        $this->utility->expects(self::never())->method('updateFolderRecord');

        (new FolderChangedEventListener())->postFolderMove(
            new AfterFolderMovedEvent(
                $this->createFolder('/old/'),
                $this->createFolder('/target/'),
                $this->createStub(FolderInterface::class)
            )
        );
    }
}
