<?php

declare(strict_types=1);

/*
 *  Copyright notice
 *
 *  (c) 2014 Frans Saris <frans@beech.it>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 */

namespace BeechIt\FalSecuredownload\EventListener;

use BeechIt\FalSecuredownload\Hooks\AbstractBeButtons;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Adds the FolderPermission button to the doc header of the file list
 *
 * EventListener is registered in Services.yaml
 *
 * @noinspection PhpUnused
 */
class ModifyButtonBarEventListener extends AbstractBeButtons
{
    /**
     * Module the button belongs to. EXT:filelist registers the file list under this
     * identifier since v13; the former name file_FilelistList is kept as an alias by
     * the core, so requests through the old name resolve to this identifier as well.
     */
    private const FILE_LIST_MODULE = 'media_management';

    public function __construct(
        ?ResourceFactory $resourceFactory,
        private readonly ComponentFactory $componentFactory
    ) {
        parent::__construct($resourceFactory);
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();

        // ModifyButtonBarEvent is dispatched in EVERY backend module, but this button
        // only ever belongs into the file list. Without the guard the listener picks up
        // the "id" parameter of page based modules - where it is a page uid, not a
        // combined file identifier - and hands it to
        // ResourceFactory::retrieveFileOrFolderObject(), which resolves a plain integer
        // as a FILE uid. Best case that silently looks up an unrelated file, worst case
        // it aborts the whole module with "A file needs to reside in a Storage"
        // (RuntimeException 1381570997) when that file's storage cannot be resolved.
        $module = $request->getAttribute('module');
        if (!$module instanceof ModuleInterface || $module->getIdentifier() !== self::FILE_LIST_MODULE) {
            return;
        }

        // Read both sources like FileListController does, so the button survives a POST.
        $parsedBody = $request->getParsedBody();
        $identifier = (is_array($parsedBody) ? ($parsedBody['id'] ?? null) : null)
            ?? $request->getQueryParams()['id']
            ?? null;
        if (!is_scalar($identifier) || (string)$identifier === '') {
            return;
        }
        $identifier = (string)$identifier;

        // Second line of defence, independent of the module: a bare integer is never a
        // folder but is accepted by retrieveFileOrFolderObject() as a file uid.
        if (MathUtility::canBeInterpretedAsInteger($identifier)) {
            return;
        }

        $buttons = $event->getButtons();
        foreach ($this->generateButtons($identifier) as $buttonInfo) {
            $button = $this->componentFactory->createLinkButton();
            $button->setIcon($buttonInfo['icon']);
            $button->setTitle($buttonInfo['title']);
            $button->setHref($buttonInfo['url']);
            $buttons[ButtonBar::BUTTON_POSITION_LEFT][1][] = $button;
        }
        $event->setButtons($buttons);
    }

    /**
     * Create button
     */
    protected function createLink(string $title, string $shortTitle, Icon $icon, UriInterface $url, bool $addReturnUrl = true): array
    {
        return [
            'title' => $title,
            'icon' => $icon,
            'url' => $url . ($addReturnUrl ? '&returnUrl=' . rawurlencode((string)$_SERVER['REQUEST_URI']) : ''),
        ];
    }
}
