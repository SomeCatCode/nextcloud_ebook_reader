<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Metadata;

use OCA\EbookReader\Listener\FileEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A small in-memory file tree behind OCP\Files\File / Folder mocks, for tests that follow a sidecar through real
 * operations. Like Nextcloud it raises the node events inside the operation (created, written, renamed, copied,
 * deleted) and hands them to the file event listener, if one is attached.
 *
 * Paths are absolute ("/u/files/Books/a.epub"). Folders are entries with the content null.
 */
final class MemoryFiles {
	public const ROOT = '/u/files';

	/** @var array<string, ?string> path => content (null = folder) */
	public array $nodes = [];
	/** @var array<string, int> */
	private array $ids = [];
	/** @var array<string, int> */
	private array $mtimes = [];
	private int $nextId = 100;
	private int $clock = 1000;
	public ?FileEventListener $listener = null;
	/** @var list<string> paths that can not be written or deleted (read-only share) */
	public array $readOnly = [];
	private IUser $owner;
	/** @var list<string> log of the operations: "delete /path", "move /a -> /b", ... */
	public array $log = [];

	public function __construct(
		private TestCase $test,
	) {
		$this->owner = (new MockBuilder($test, IUser::class))->getMock();
		$this->owner->method('getUID')->willReturn('u');
		$this->nodes['/u'] = null;
		$this->nodes[self::ROOT] = null;
	}

	public function addFolder(string $path): void {
		$parts = explode('/', trim($path, '/'));
		$cur = '';
		foreach ($parts as $p) {
			$cur .= '/' . $p;
			$this->nodes[$cur] ??= null;
		}
	}

	public function addFile(string $path, string $content = 'x'): void {
		$this->addFolder(dirname($path));
		$this->nodes[$path] = $content;
	}

	public function exists(string $path): bool {
		return array_key_exists($path, $this->nodes);
	}

	/** @return list<string> paths below a folder (or the whole tree), sorted */
	public function paths(string $under = self::ROOT): array {
		$out = [];
		foreach (array_keys($this->nodes) as $p) {
			if ($p !== $under && str_starts_with($p, $under . '/')) {
				$out[] = $p;
			}
		}
		sort($out);
		return $out;
	}

	public function node(string $path): Node {
		return $this->nodes[$path] === null ? $this->folder($path) : $this->file($path);
	}

	public function file(string $path): File {
		return $this->makeFile($path);
	}

	public function folder(string $path): Folder {
		return $this->makeFolder($path);
	}

	private function id(string $path): int {
		return $this->ids[$path] ??= $this->nextId++;
	}

	private function dispatch(object $event): void {
		$this->listener?->handle($event);
	}

	private function makeFile(string $path): File {
		$st = new \stdClass();
		$st->path = $path;
		/** @var File&\PHPUnit\Framework\MockObject\MockObject $f */
		$f = (new MockBuilder($this->test, File::class))->getMock();
		$f->method('getPath')->willReturnCallback(static fn (): string => $st->path);
		$f->method('getName')->willReturnCallback(static fn (): string => basename($st->path));
		$f->method('getId')->willReturnCallback(fn (): int => $this->id($st->path));
		$f->method('getOwner')->willReturn($this->owner);
		$f->method('getParent')->willReturnCallback(fn (): Folder => $this->makeFolder(dirname($st->path)));
		$f->method('getMimeType')->willReturn('application/octet-stream');
		$f->method('getContent')->willReturnCallback(fn (): string => (string)($this->nodes[$st->path] ?? ''));
		$f->method('getSize')->willReturnCallback(fn (): int => strlen((string)($this->nodes[$st->path] ?? '')));
		$f->method('getEtag')->willReturnCallback(fn (): string => md5((string)($this->nodes[$st->path] ?? '')));
		$f->method('getMTime')->willReturnCallback(fn (): int => $this->mtimes[$st->path] ?? 1000);
		$f->method('isReadable')->willReturn(true);
		$f->method('isUpdateable')->willReturnCallback(fn (): bool => !in_array($st->path, $this->readOnly, true));
		$f->method('isDeletable')->willReturnCallback(fn (): bool => !in_array($st->path, $this->readOnly, true));
		$f->method('putContent')->willReturnCallback(function (string $data) use ($st, $f): void {
			$this->nodes[$st->path] = $data;
			$this->mtimes[$st->path] = ++$this->clock;
			$this->log[] = 'write ' . $st->path;
			$this->dispatch(new NodeWrittenEvent($f));
		});
		$f->method('delete')->willReturnCallback(function () use ($st, $f): void {
			$snapshot = $this->makeFile($st->path);
			unset($this->nodes[$st->path]);
			$this->log[] = 'delete ' . $st->path;
			$this->dispatch(new NodeDeletedEvent($snapshot));
		});
		$f->method('move')->willReturnCallback(function (string $target) use ($st, $f): Node {
			if (array_key_exists($target, $this->nodes)) {
				throw new \RuntimeException('target exists: ' . $target);
			}
			$snapshot = $this->makeFile($st->path);
			$this->nodes[$target] = $this->nodes[$st->path];
			$this->mtimes[$target] = $this->mtimes[$st->path] ?? 1000;
			$this->ids[$target] = $this->id($st->path);
			unset($this->nodes[$st->path]);
			$this->log[] = 'move ' . $st->path . ' -> ' . $target;
			$st->path = $target;
			$this->dispatch(new NodeRenamedEvent($snapshot, $f));
			return $f;
		});
		$f->method('copy')->willReturnCallback(function (string $target) use ($st, $f): Node {
			if (array_key_exists($target, $this->nodes)) {
				throw new \RuntimeException('target exists: ' . $target);
			}
			$this->nodes[$target] = $this->nodes[$st->path];
			$this->log[] = 'copy ' . $st->path . ' -> ' . $target;
			$copy = $this->makeFile($target);
			$this->dispatch(new NodeCreatedEvent($copy));
			$this->dispatch(new NodeCopiedEvent($f, $copy));
			return $copy;
		});
		return $f;
	}

	private function makeFolder(string $path): Folder {
		$st = new \stdClass();
		$st->path = $path;
		/** @var Folder&\PHPUnit\Framework\MockObject\MockObject $d */
		$d = (new MockBuilder($this->test, Folder::class))->getMock();
		$d->method('getPath')->willReturnCallback(static fn (): string => $st->path);
		$d->method('getName')->willReturnCallback(static fn (): string => basename($st->path));
		$d->method('getId')->willReturnCallback(fn (): int => $this->id($st->path));
		$d->method('getOwner')->willReturn($this->owner);
		$d->method('getParent')->willReturnCallback(fn (): Folder => $this->makeFolder(dirname($st->path)));
		$d->method('isCreatable')->willReturnCallback(fn (): bool => !in_array($st->path, $this->readOnly, true));
		$d->method('isReadable')->willReturn(true);
		$d->method('isDeletable')->willReturnCallback(fn (): bool => !in_array($st->path, $this->readOnly, true));
		$d->method('nodeExists')->willReturnCallback(fn (string $name): bool => array_key_exists($st->path . '/' . $name, $this->nodes));
		$d->method('get')->willReturnCallback(function (string $name) use ($st): Node {
			$p = $st->path . '/' . $name;
			if (!array_key_exists($p, $this->nodes)) {
				throw new \OCP\Files\NotFoundException($p);
			}
			return $this->node($p);
		});
		$d->method('getDirectoryListing')->willReturnCallback(function () use ($st): array {
			$out = [];
			foreach (array_keys($this->nodes) as $p) {
				if (dirname($p) === $st->path && $p !== $st->path) {
					$out[] = $this->node($p);
				}
			}
			return $out;
		});
		$d->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($st): File {
			$p = $st->path . '/' . $name;
			if (array_key_exists($p, $this->nodes)) {
				throw new \RuntimeException('exists: ' . $p);
			}
			$this->nodes[$p] = (string)$content;
			$this->mtimes[$p] = ++$this->clock;
			$this->log[] = 'create ' . $p;
			$file = $this->makeFile($p);
			$this->dispatch(new NodeCreatedEvent($file));
			return $file;
		});
		$d->method('newFolder')->willReturnCallback(function (string $name) use ($st): Folder {
			$p = $st->path . '/' . $name;
			if (array_key_exists($p, $this->nodes)) {
				throw new \RuntimeException('exists: ' . $p);
			}
			$this->nodes[$p] = null;
			$this->log[] = 'mkdir ' . $p;
			$folder = $this->makeFolder($p);
			$this->dispatch(new NodeCreatedEvent($folder));
			return $folder;
		});
		$d->method('delete')->willReturnCallback(function () use ($st): void {
			$snapshot = $this->makeFolder($st->path);
			foreach (array_keys($this->nodes) as $p) {
				if ($p === $st->path || str_starts_with($p, $st->path . '/')) {
					unset($this->nodes[$p]);
				}
			}
			$this->log[] = 'rmdir ' . $st->path;
			$this->dispatch(new NodeDeletedEvent($snapshot));
		});
		$d->method('move')->willReturnCallback(function (string $target) use ($st, $d): Node {
			$snapshot = $this->makeFolder($st->path);
			$moved = [];
			foreach ($this->nodes as $p => $content) {
				if ($p === $st->path || str_starts_with($p, $st->path . '/')) {
					$moved[$target . substr($p, strlen($st->path))] = $content;
					unset($this->nodes[$p]);
				}
			}
			$this->nodes += $moved;
			$this->log[] = 'move ' . $st->path . ' -> ' . $target;
			$st->path = $target;
			$this->dispatch(new NodeRenamedEvent($snapshot, $d));
			return $d;
		});
		return $d;
	}
}
