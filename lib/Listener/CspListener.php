<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Listener;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Relaxes the CSP only on pages that host the reader (the app itself and Files with the Viewer).
 *
 * The reader (foliate-js) renders book sections in sandboxed blob: iframes and loads images,
 * fonts and styles of the book as blob: URLs. CBR support runs libarchive.js as a wasm worker
 * started from a blob: URL. See docs/SECURITY-READER.md.
 *
 * @template-implements IEventListener<Event>
 */
class CspListener implements IEventListener {
	private const PATH_PREFIXES = ['/apps/ebookreader', '/apps/files', '/apps/viewer', '/f/'];

	public function __construct(
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}
		if (!$this->isRelevantRequest()) {
			return;
		}

		$policy = new ContentSecurityPolicy();
		// Section documents and the sandboxed iframes are blob: URLs.
		$policy->addAllowedFrameDomain('blob:');
		$policy->addAllowedWorkerSrcDomain('blob:');
		// Book resources referenced from sections (inherited by blob: documents).
		$policy->addAllowedImageDomain('blob:');
		$policy->addAllowedMediaDomain('blob:');
		$policy->addAllowedFontDomain('blob:');
		$policy->addAllowedFontDomain('data:');
		$policy->addAllowedStyleDomain('blob:');
		// reader-core re-reads blob: sections to inject the per-section CSP.
		$policy->addAllowedConnectDomain('blob:');
		// libarchive.js (CBR) is WebAssembly.
		$policy->allowEvalWasm();

		$event->addPolicy($policy);
	}

	private function isRelevantRequest(): bool {
		try {
			$path = $this->request->getPathInfo();
		} catch (\Throwable) {
			return false;
		}
		if (!is_string($path)) {
			return false;
		}
		foreach (self::PATH_PREFIXES as $prefix) {
			if (str_starts_with($path, $prefix)) {
				return true;
			}
		}
		return false;
	}
}
