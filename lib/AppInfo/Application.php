<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\AppInfo;

use OCA\EbookReader\Capabilities;
use OCA\EbookReader\Dashboard\ContinueReadingWidget;
use OCA\EbookReader\Listener\CspListener;
use OCA\EbookReader\Listener\FileEventListener;
use OCA\EbookReader\Listener\LoadFilesScriptsListener;
use OCA\EbookReader\Listener\LoadViewerListener;
use OCA\EbookReader\Listener\ShareEventListener;
use OCA\EbookReader\Listener\UserDeletedListener;
use OCA\EbookReader\Preview\EbookCoverProvider;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Share\Events\ShareAcceptedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ebookreader';

	/** MIME types handled by the app (see Migration\RegisterMimeTypes), keyed by file extension. */
	public const MIME_TYPES = [
		'epub' => 'application/epub+zip',
		'mobi' => 'application/x-mobipocket-ebook',
		'azw3' => 'application/vnd.amazon.mobi8-ebook',
		'fb2' => 'application/x-fictionbook+xml',
		'fbz' => 'application/x-zip-compressed-fb2',
		'cbz' => 'application/comicbook+zip',
		'cbr' => 'application/comicbook+rar',
		'cb7' => 'application/x-cb7',
		'cbt' => 'application/x-cbt',
	];

	public const PREVIEW_MIME_REGEX = '/^application\/(epub\+zip|x-mobipocket-ebook|vnd\.amazon\.mobi8-ebook|x-fictionbook\+xml|x-zip-compressed-fb2|vnd\.comicbook\+zip|vnd\.comicbook-rar|x-cb7|x-cbt)$/';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerDashboardWidget(ContinueReadingWidget::class);
		$context->registerPreviewProvider(EbookCoverProvider::class, EbookCoverProvider::MIME_REGEX);

		$context->registerEventListener(NodeCreatedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileEventListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
		$context->registerEventListener(LoadViewer::class, LoadViewerListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
		// sharing: keep the app's share records in line with Nextcloud shares
		$context->registerEventListener(ShareDeletedEvent::class, ShareEventListener::class);
		$context->registerEventListener(ShareDeletedFromSelfEvent::class, ShareEventListener::class);
		$context->registerEventListener(ShareAcceptedEvent::class, ShareEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
