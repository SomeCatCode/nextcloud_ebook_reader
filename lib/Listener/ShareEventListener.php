<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCA\EbookReader\Service\ShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareAcceptedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\Events\ShareDeletedFromSelfEvent;
use Psr\Log\LoggerInterface;

/**
 * Keeps the app's share records in line when Nextcloud shares change outside the app (deleted in Files, left by the
 * recipient, accepted when share acceptance is enabled). Never throws into the sharing stack.
 * @template-implements IEventListener<Event>
 */
class ShareEventListener implements IEventListener {
	public function __construct(
		private ShareService $sharing,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		try {
			if ($event instanceof ShareDeletedEvent || $event instanceof ShareDeletedFromSelfEvent) {
				$this->sharing->onShareDeleted($event->getShare());
			} elseif ($event instanceof ShareAcceptedEvent) {
				$this->sharing->onShareAccepted($event->getShare());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Share event handling failed: ' . $e->getMessage(), ['app' => 'ebookreader', 'exception' => $e]);
		}
	}
}
