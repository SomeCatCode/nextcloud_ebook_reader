<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\EbookReader\Tests\Unit\Service;

use OCA\EbookReader\Service\ConvertException;
use OCA\EbookReader\Service\ConvertService;
use OCA\EbookReader\Service\ImageOptimizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageOptimizerTest extends TestCase {
	public function testNormaliseDefaultsAndAllowlist(): void {
		$this->assertSame(['maxHeight' => 0, 'jpegQuality' => 85, 'pngToJpeg' => false], ImageOptimizer::normalise([]));
		$this->assertSame(['maxHeight' => 2560, 'jpegQuality' => 85, 'pngToJpeg' => true], ImageOptimizer::normalise(['maxHeight' => 2560, 'pngToJpeg' => true]));
		$this->assertSame(['maxHeight' => 1920, 'jpegQuality' => 70, 'pngToJpeg' => false], ImageOptimizer::normalise(['maxHeight' => '1920', 'jpegQuality' => 70]));
	}

	/** @param array<string, mixed> $raw */
	#[DataProvider('invalidOptions')]
	public function testNormaliseRejectsInvalidValues(array $raw): void {
		try {
			ImageOptimizer::normalise($raw);
			$this->fail('expected a 400');
		} catch (ConvertException $e) {
			$this->assertSame(400, $e->getStatus());
		}
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalidOptions(): array {
		return [
			'height not in allowlist' => [['maxHeight' => 1000]],
			'negative height' => [['maxHeight' => -1]],
			'height text' => [['maxHeight' => 'big']],
			'quality too low' => [['maxHeight' => 1920, 'jpegQuality' => 50]],
			'quality too high' => [['maxHeight' => 1920, 'jpegQuality' => 100]],
			'png flag not a bool' => [['maxHeight' => 1920, 'pngToJpeg' => [1]]],
		];
	}

	public function testIsActive(): void {
		$this->assertFalse(ImageOptimizer::isActive(ImageOptimizer::normalise([])));
		$this->assertTrue(ImageOptimizer::isActive(ImageOptimizer::normalise(['maxHeight' => 1920])));
		$this->assertTrue(ImageOptimizer::isActive(ImageOptimizer::normalise(['pngToJpeg' => true])));
	}

	public function testTargetSizeKeepsAspectRatioAndNeverUpscales(): void {
		$this->assertSame([1500, 2000], ImageOptimizer::targetSize(3000, 4000, 2000));
		$this->assertSame([2400, 2560], ImageOptimizer::targetSize(2625, 2800, 2560));
		$this->assertSame([1440, 1920], ImageOptimizer::targetSize(1800, 2400, 1920));
		// already small enough (also exactly at the limit): untouched
		$this->assertNull(ImageOptimizer::targetSize(1920, 2560, 2560));
		$this->assertNull(ImageOptimizer::targetSize(1200, 1920, 1920));
		$this->assertNull(ImageOptimizer::targetSize(800, 1200, 1920));
		// optimization off or invalid dimensions
		$this->assertNull(ImageOptimizer::targetSize(3000, 4000, 0));
		$this->assertNull(ImageOptimizer::targetSize(0, 4000, 1920));
		// a very narrow strip never gets a zero width
		$this->assertSame([1, 1920], ImageOptimizer::targetSize(1, 100000, 1920));
	}

	public function testKeepOriginalUnlessSmaller(): void {
		$this->assertTrue(ImageOptimizer::keepOriginal(1000, 1000));
		$this->assertTrue(ImageOptimizer::keepOriginal(1000, 1200));
		$this->assertTrue(ImageOptimizer::keepOriginal(1000, 0));
		$this->assertFalse(ImageOptimizer::keepOriginal(1000, 999));
	}

	public function testPageNamesKeepNaturalOrderAndMapExtensions(): void {
		$this->assertSame('0001.jpg', ImageOptimizer::pageName(0, 52, 'jpg'));
		$this->assertSame('0001.jpg', ImageOptimizer::pageName(0, 52, 'JPEG'));
		$this->assertSame('0052.png', ImageOptimizer::pageName(51, 52, 'png'));
		$this->assertSame('00010.jpg', ImageOptimizer::pageName(9, 12345, 'jpg'));
		$names = [];
		foreach ([0 => 'png', 1 => 'jpg', 9 => 'png', 10 => 'jpg'] as $i => $ext) {
			$names[] = ImageOptimizer::pageName($i, 11, $ext);
		}
		$sorted = $names;
		usort($sorted, 'strnatcasecmp');
		$this->assertSame($names, $sorted);
	}

	public function testFitsMemory(): void {
		$this->assertTrue(ImageOptimizer::fitsMemory(10000, 10000, 0, -1));
		$this->assertTrue(ImageOptimizer::fitsMemory(2000, 3000, 20_000_000, 512 * 1024 * 1024));
		$this->assertFalse(ImageOptimizer::fitsMemory(8000, 8000, 20_000_000, 256 * 1024 * 1024));
		$this->assertFalse(ImageOptimizer::fitsMemory(1000, 1000, 120_000_000, 128 * 1024 * 1024));
	}

	public function testPngAlphaDetectionFromTheHeader(): void {
		$header = static fn (int $type): string => "\x89PNG\r\n\x1A\n" . "\0\0\0\rIHDR" . "\0\0\0\x10\0\0\0\x10\x08" . chr($type) . "\0\0\0" . "\0\0\0\0" . "\0\0\0\0IDAT";
		$this->assertFalse(ImageOptimizer::pngHasAlpha($header(2)));
		$this->assertFalse(ImageOptimizer::pngHasAlpha($header(0)));
		$this->assertTrue(ImageOptimizer::pngHasAlpha($header(6)));
		$this->assertTrue(ImageOptimizer::pngHasAlpha($header(4)));
		// palette image with a tRNS chunk before the data
		$trns = substr($header(3), 0, -8) . "\0\0\0\1tRNS\0\0\0\0\0\0\0\0IDAT";
		$this->assertTrue(ImageOptimizer::pngHasAlpha($trns));
		$this->assertTrue(ImageOptimizer::pngHasAlpha('short'));
	}

	public function testSampleIndices(): void {
		$this->assertSame([], ImageOptimizer::sampleIndices(0, 16));
		$this->assertSame([0, 1, 2], ImageOptimizer::sampleIndices(3, 16));
		$idx = ImageOptimizer::sampleIndices(400, 16);
		$this->assertCount(16, $idx);
		$this->assertSame(0, $idx[0]);
		$this->assertSame(399, $idx[15]);
	}

	public function testExtrapolateWithoutChangesIsNeutral(): void {
		$res = ImageOptimizer::extrapolate(100, 5000, [['bytes' => 50, 'changes' => false, 'newBytes' => null]]);
		$this->assertSame(['pages' => 100, 'oversizedPages' => 0, 'currentBytes' => 5000, 'estimatedBytes' => 5000, 'exact' => false], $res);
		$this->assertSame(0, ImageOptimizer::extrapolate(10, 100, [])['oversizedPages']);
	}

	public function testExtrapolateScalesTheMeasuredRatio(): void {
		// half of the sampled pages (and bytes) are oversized and shrink to 40 percent
		$samples = [
			['bytes' => 1000, 'changes' => true, 'newBytes' => 400],
			['bytes' => 1000, 'changes' => false, 'newBytes' => null],
			['bytes' => 1000, 'changes' => true, 'newBytes' => null],
			['bytes' => 1000, 'changes' => false, 'newBytes' => null],
		];
		$res = ImageOptimizer::extrapolate(200, 1_000_000, $samples);
		$this->assertSame(100, $res['oversizedPages']);
		// 1 - 0.5 * (1 - 0.4) = 0.7
		$this->assertSame(700_000, $res['estimatedBytes']);
		$this->assertFalse($res['exact']);
	}

	public function testExtrapolateExactAndNeverGrows(): void {
		$res = ImageOptimizer::extrapolate(2, 2000, [
			['bytes' => 1000, 'changes' => true, 'newBytes' => 1000],
			['bytes' => 1000, 'changes' => true, 'newBytes' => 1500],
		], true);
		$this->assertSame(2, $res['oversizedPages']);
		$this->assertSame(2000, $res['estimatedBytes']);
		$this->assertTrue($res['exact']);
		// nothing measured: the default ratio applies
		$res = ImageOptimizer::extrapolate(1, 1000, [['bytes' => 1000, 'changes' => true, 'newBytes' => null]], true);
		$this->assertSame(600, $res['estimatedBytes']);
	}

	public function testConvertNamingAndTargets(): void {
		$this->assertSame('cbz', ConvertService::optimizeTarget('cbr'));
		$this->assertSame('cb7', ConvertService::optimizeTarget('cb7'));
		$this->assertSame('Comic (optimized).cbz', ConvertService::targetName('Comic.cbz', 'cbz', 'cbz'));
		$this->assertSame('Comic.cbt', ConvertService::targetName('Comic.cbz', 'cbz', 'cbt'));
		$this->assertSame('Comic.cbz', ConvertService::targetName('Comic.cbr', 'cbr', 'cbz'));
	}

	private function needGd(): void {
		if (!ImageOptimizer::available() || !function_exists('imagepng')) {
			$this->markTestSkipped('GD with JPEG support is not installed');
		}
	}

	private function image(int $w, int $h, string $type, bool $alpha = false): string {
		$im = imagecreatetruecolor($w, $h);
		// noisy content so that the files are not trivially small
		for ($i = 0; $i < 200; $i++) {
			imagefilledrectangle($im, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, $w - 1), random_int(0, $h - 1), (int)imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
		}
		if ($alpha) {
			imagesavealpha($im, true);
			imagealphablending($im, false);
			imagefilledrectangle($im, 0, 0, 10, 10, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
		}
		ob_start();
		$type === 'png' ? imagepng($im, null, 0) : imagejpeg($im, null, 98);
		return (string)ob_get_clean();
	}

	public function testOversizedJpegIsScaledAndSmaller(): void {
		$this->needGd();
		$data = $this->image(1500, 2000, 'jpg');
		$res = (new ImageOptimizer())->optimizePage($data, ImageOptimizer::normalise(['maxHeight' => 1920]));
		$this->assertTrue($res['changed']);
		$this->assertSame('jpg', $res['ext']);
		$this->assertLessThan(strlen($data), strlen($res['data']));
		$size = getimagesizefromstring($res['data']);
		$this->assertSame([1440, 1920], [$size[0], $size[1]]);
	}

	public function testSmallPagesAreCopiedByteForByte(): void {
		$this->needGd();
		$data = $this->image(800, 1200, 'jpg');
		$res = (new ImageOptimizer())->optimizePage($data, ImageOptimizer::normalise(['maxHeight' => 1920]));
		$this->assertFalse($res['changed']);
		$this->assertSame($data, $res['data']);
		$this->assertNull($res['ext']);
		// unsupported content is passed through as well
		$this->assertFalse((new ImageOptimizer())->optimizePage('GIF89a-not-really-an-image', ImageOptimizer::normalise(['maxHeight' => 1920]))['changed']);
	}

	public function testPngStaysPngUnlessAskedAndAlphaStaysPng(): void {
		$this->needGd();
		$opt = new ImageOptimizer();
		$png = $this->image(1200, 2400, 'png');
		$res = $opt->optimizePage($png, ImageOptimizer::normalise(['maxHeight' => 1920]));
		$this->assertTrue($res['changed']);
		$this->assertSame('png', $res['ext']);

		$res = $opt->optimizePage($png, ImageOptimizer::normalise(['maxHeight' => 1920, 'pngToJpeg' => true]));
		$this->assertSame('jpg', $res['ext']);
		$this->assertSame('image/jpeg', getimagesizefromstring($res['data'])['mime']);

		// PNG within the limits is only converted when asked for
		$small = $this->image(600, 800, 'png');
		$this->assertFalse($opt->optimizePage($small, ImageOptimizer::normalise(['maxHeight' => 1920]))['changed']);
		$this->assertSame('jpg', $opt->optimizePage($small, ImageOptimizer::normalise(['pngToJpeg' => true]))['ext']);

		$alpha = $this->image(600, 800, 'png', true);
		$res = $opt->optimizePage($alpha, ImageOptimizer::normalise(['pngToJpeg' => true]));
		$this->assertFalse($res['changed']);
	}

	public function testReencodedResultThatIsNotSmallerIsDiscarded(): void {
		$this->needGd();
		// a tiny, heavily compressed JPEG gets bigger when it is re-encoded at quality 95
		$im = imagecreatetruecolor(100, 100);
		ob_start();
		imagejpeg($im, null, 1);
		$data = (string)ob_get_clean();
		$res = (new ImageOptimizer())->optimizePage($data, ['maxHeight' => 0, 'jpegQuality' => 95, 'pngToJpeg' => true]);
		$this->assertFalse($res['changed']);
		$this->assertSame($data, $res['data']);
	}
}
