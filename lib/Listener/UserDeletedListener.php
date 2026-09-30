<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Owner: W1
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
	public function handle(Event $event): void {
	}
}
