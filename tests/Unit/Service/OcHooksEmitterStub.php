<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests run without a Nextcloud server and without doctrine/dbal. nextcloud/ocp references a few
 * classes that are not part of the ocp package: the server-private interface OC\Hooks\Emitter (extended by
 * IRootFolder) and the doctrine constants used by IQueryBuilder. Declare minimal equivalents so that these
 * interfaces can be mocked.
 */

namespace OC\Hooks {
	if (!interface_exists(Emitter::class, false)) {
		interface Emitter {
			public function listen($scope, $method, callable $callback);

			public function removeListener($scope = null, $method = null, ?callable $callback = null);
		}
	}
}

namespace Doctrine\DBAL {
	if (!class_exists(ParameterType::class)) {
		final class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
			public const BOOLEAN = 5;
		}
	}
	if (!class_exists(ArrayParameterType::class)) {
		final class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class)) {
		final class Types {
			public const BOOLEAN = 'boolean';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const DATE_MUTABLE = 'date';
			public const TIME_MUTABLE = 'time';
		}
	}
}
