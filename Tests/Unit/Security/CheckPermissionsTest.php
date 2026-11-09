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

namespace BeechIt\FalSecuredownload\Tests\Unit\Security;

use BeechIt\FalSecuredownload\Events\AddCustomGroupsEvent;
use BeechIt\FalSecuredownload\Security\CheckPermissions;
use BeechIt\FalSecuredownload\Service\Utility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CheckPermissionsTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /**
     * Folder records by folder identifier, as Utility::getFolderRecord() would return them
     *
     * @var array<string, array{fe_groups: string}>
     */
    private array $folderRecords = [];

    /**
     * Custom groups added by a listener of AddCustomGroupsEvent
     *
     * @var list<int|string>
     */
    private array $customGroups = [];

    private ResourceStorage&Stub $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->createStub(ResourceStorage::class);
        $this->storage->method('getUid')->willReturn(1);
        $this->storage->method('isPublic')->willReturn(false);

        $utility = $this->createStub(Utility::class);
        $utility->method('getFolderRecord')->willReturnCallback(
            fn(FolderInterface $folder): array|false => $this->folderRecords[$folder->getIdentifier()] ?? false
        );
        GeneralUtility::setSingletonInstance(Utility::class, $utility);
    }

    private function createSubject(): CheckPermissions
    {
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            function (object $event): object {
                if ($event instanceof AddCustomGroupsEvent && $this->customGroups !== []) {
                    $event->setCustomUserGroups([$this->customGroups]);
                }
                return $event;
            }
        );
        return new CheckPermissions($eventDispatcher);
    }

    /**
     * Build a folder whose root line consists of the given identifiers (root first)
     */
    private function createFolder(string ...$identifiers): FolderInterface
    {
        $parent = null;
        $folder = null;
        foreach ($identifiers as $identifier) {
            $folder = $this->createStub(FolderInterface::class);
            $folder->method('getIdentifier')->willReturn($identifier);
            $folder->method('getHashedIdentifier')->willReturn(sha1($identifier));
            $folder->method('getStorage')->willReturn($this->storage);
            // the root folder is its own parent
            $folder->method('getParentFolder')->willReturn($parent ?? $folder);
            $parent = $folder;
        }
        self::assertNotNull($folder);
        return $folder;
    }

    private function createFile(FolderInterface $parentFolder, string $feGroups = '', ?ResourceStorage $storage = null): FileInterface
    {
        $file = $this->createStub(FileInterface::class);
        $file->method('getStorage')->willReturn($storage ?? $this->storage);
        $file->method('getParentFolder')->willReturn($parentFolder);
        $file->method('getProperty')->willReturnMap([['fe_groups', $feGroups]]);
        return $file;
    }

    public static function matchFeGroupsWithFeUserDataProvider(): iterable
    {
        yield 'no groups set, anonymous' => ['', false, true];
        yield 'no groups set, logged in' => ['', [1], true];
        yield 'group set, anonymous' => ['1', false, false];
        yield 'any login (-2), anonymous' => ['-2', false, false];
        yield 'any login (-2), logged in without groups' => ['-2', [], true];
        yield 'any login (-2), logged in with groups' => ['-2', [5], true];
        yield 'any login (-2) within list, logged in' => ['3,-2', [5], true];
        yield 'single group, member' => ['3', [3], true];
        yield 'single group, not a member' => ['3', [4], false];
        yield 'several groups, member of one' => ['1,2,3', [7, 2], true];
        yield 'several groups, member of none' => ['1,2,3', [4, 5, 6], false];
        yield 'several groups, logged in without groups' => ['1,2,3', [], false];
        yield 'user groups as strings' => ['1,2,3', ['3'], true];
        yield 'spaces in group list, member' => [' 1 , 2 ,3 ', [2], true];
        yield 'spaces in group list, not a member' => [' 1 , 2 ,3 ', [4], false];
        yield 'logged in, groups not an array' => ['1', true, false];
    }

    /**
     * @param bool|array<int|string> $userFeGroups
     */
    #[Test]
    #[DataProvider('matchFeGroupsWithFeUserDataProvider')]
    public function matchFeGroupsWithFeUser(string $groups, bool|array $userFeGroups, bool $expected): void
    {
        self::assertSame($expected, $this->createSubject()->matchFeGroupsWithFeUser($groups, $userFeGroups));
    }

    #[Test]
    public function fileInPublicStorageIsAlwaysAccessible(): void
    {
        $publicStorage = $this->createStub(ResourceStorage::class);
        $publicStorage->method('isPublic')->willReturn(true);
        $this->folderRecords = ['/' => ['fe_groups' => '1']];
        $file = $this->createFile($this->createFolder('/'), '1', $publicStorage);

        self::assertTrue($this->createSubject()->checkFileAccess($file, false));
        self::assertTrue($this->createSubject()->checkFileAccess($file, [2]));
    }

    public static function rootFolderRequiresAnyLoginDataProvider(): iterable
    {
        yield 'anonymous' => [false, false];
        yield 'logged in without groups' => [[], true];
        yield 'logged in with groups' => [[1, 2], true];
    }

    /**
     * @param bool|array<int> $userFeGroups
     */
    #[Test]
    #[DataProvider('rootFolderRequiresAnyLoginDataProvider')]
    public function rootFolderWithAnyLoginRestrictsSubFolders(bool|array $userFeGroups, bool $expected): void
    {
        $this->folderRecords = ['/' => ['fe_groups' => '-2']];
        $folder = $this->createFolder('/', '/public/', '/public/sub/');

        self::assertSame($expected, $this->createSubject()->checkFolderRootLineAccess($folder, $userFeGroups));
        self::assertSame($expected, $this->createSubject()->checkFileAccess($this->createFile($folder), $userFeGroups));
    }

    public static function subFolderStricterThanRootDataProvider(): iterable
    {
        yield 'anonymous' => [false, false];
        yield 'logged in, not a member' => [[2], false];
        yield 'logged in, member' => [[3], true];
        yield 'logged in, member of one of several groups' => [[2, 3], true];
    }

    /**
     * @param bool|array<int> $userFeGroups
     */
    #[Test]
    #[DataProvider('subFolderStricterThanRootDataProvider')]
    public function subFolderCanBeStricterThanRootFolder(bool|array $userFeGroups, bool $expected): void
    {
        $this->folderRecords = [
            '/' => ['fe_groups' => '-2'],
            '/members/' => ['fe_groups' => '3'],
        ];
        $folder = $this->createFolder('/', '/members/', '/members/docs/');

        self::assertSame($expected, $this->createSubject()->checkFolderRootLineAccess($folder, $userFeGroups));
        self::assertSame($expected, $this->createSubject()->checkFileAccess($this->createFile($folder), $userFeGroups));
    }

    #[Test]
    public function siblingFolderOfStricterSubFolderKeepsRootPermissions(): void
    {
        $this->folderRecords = [
            '/' => ['fe_groups' => '-2'],
            '/members/' => ['fe_groups' => '3'],
        ];
        $folder = $this->createFolder('/', '/other/');

        self::assertTrue($this->createSubject()->checkFolderRootLineAccess($folder, [2]));
        self::assertFalse($this->createSubject()->checkFolderRootLineAccess($folder, false));
    }

    #[Test]
    public function folderRecordWithoutGroupsDoesNotRestrictAccess(): void
    {
        $this->folderRecords = ['/' => ['fe_groups' => '']];
        $folder = $this->createFolder('/', '/docs/');

        self::assertTrue($this->createSubject()->checkFolderRootLineAccess($folder, false));
    }

    public static function fileGroupsDataProvider(): iterable
    {
        yield 'anonymous' => [false, false];
        yield 'logged in, not a member' => [[2], false];
        yield 'logged in, member' => [[4], true];
    }

    /**
     * @param bool|array<int> $userFeGroups
     */
    #[Test]
    #[DataProvider('fileGroupsDataProvider')]
    public function fileGroupsRestrictAccessInsideAccessibleFolder(bool|array $userFeGroups, bool $expected): void
    {
        $this->folderRecords = ['/' => ['fe_groups' => '-2']];
        $file = $this->createFile($this->createFolder('/', '/docs/'), '4,5');

        self::assertSame($expected, $this->createSubject()->checkFileAccess($file, $userFeGroups));
    }

    #[Test]
    public function fileGroupsDoNotGrantAccessToRestrictedFolder(): void
    {
        $this->folderRecords = ['/members/' => ['fe_groups' => '3']];
        $file = $this->createFile($this->createFolder('/', '/members/'), '4');

        self::assertFalse($this->createSubject()->checkFileAccess($file, [4]));
        self::assertTrue($this->createSubject()->checkFileAccess($file, [3, 4]));
    }

    /**
     * Documents existing behaviour: without any folder record in the root line
     * (and without file groups) a file in a non-public storage is accessible
     * for everybody, including anonymous visitors. Restricting a whole storage
     * therefore needs a record on its root folder, e.g. with "-2" (any login).
     */
    #[Test]
    public function withoutFolderRecordInRootLineEverybodyHasAccess(): void
    {
        $this->folderRecords = [];
        $folder = $this->createFolder('/', '/docs/', '/docs/sub/');

        self::assertTrue($this->createSubject()->checkFolderRootLineAccess($folder, false));
        self::assertTrue($this->createSubject()->checkFileAccess($this->createFile($folder), false));
        self::assertTrue($this->createSubject()->checkFileAccess($this->createFile($folder), [1]));
    }

    #[Test]
    public function customGroupsFromEventGrantAccessForAnonymousUser(): void
    {
        $this->folderRecords = ['/' => ['fe_groups' => '7']];
        $this->customGroups = [7];
        $file = $this->createFile($this->createFolder('/', '/docs/'));

        self::assertTrue($this->createSubject()->checkFileAccess($file, false));
    }

    #[Test]
    public function customGroupsFromEventAreMergedWithUserGroups(): void
    {
        $this->folderRecords = ['/' => ['fe_groups' => '7']];
        $this->customGroups = [7];
        $file = $this->createFile($this->createFolder('/', '/docs/'));

        self::assertTrue($this->createSubject()->checkFileAccess($file, [1]));
    }

    public static function getPermissionsDataProvider(): iterable
    {
        yield 'no folder record' => [[], '', ''];
        yield 'groups of root folder' => [['/' => ['fe_groups' => '1,2']], '', '1,2'];
        yield 'nearest folder record wins' => [['/' => ['fe_groups' => '1,2'], '/docs/' => ['fe_groups' => '3']], '', '3'];
        yield 'empty nearest folder record wins' => [['/' => ['fe_groups' => '1'], '/docs/' => ['fe_groups' => '']], '', ''];
        yield 'spaces in folder groups' => [['/' => ['fe_groups' => ' 1 , 2 ']], '', '1,2'];
        yield 'file groups restrict folder groups' => [['/' => ['fe_groups' => '1,2,3']], '2,4', '2'];
        yield 'file groups without folder record' => [[], '4', ''];
    }

    /**
     * @param array<string, array{fe_groups: string}> $folderRecords
     */
    #[Test]
    #[DataProvider('getPermissionsDataProvider')]
    public function getPermissionsReturnsGroupsOfNearestFolderRecord(array $folderRecords, string $fileGroups, string $expected): void
    {
        $this->folderRecords = $folderRecords;
        $file = $this->createFile($this->createFolder('/', '/docs/', '/docs/sub/'), $fileGroups);

        self::assertSame($expected, $this->createSubject()->getPermissions($file));
    }
}
