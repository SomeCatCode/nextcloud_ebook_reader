<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCA\EbookReader\AppInfo\Application;
use OCA\Viewer\Event\LoadViewer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the Viewer handler script whenever the Viewer app is loaded.
 *
 * @template-implements IEventListener<Event>
 */
class LoadViewerListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadViewer)) {
			return;
		}
		Util::addScript(Application::APP_ID, Application::APP_ID . '-viewer');
	}
}
