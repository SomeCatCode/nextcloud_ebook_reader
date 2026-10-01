<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * doctrine/dbal is not installed for the unit tests; nextcloud/ocp's IQueryBuilder only needs these constants.
 */

namespace Doctrine\DBAL {
	if (!class_exists(ParameterType::class, false)) {
		final class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
		}
	}
	if (!class_exists(ArrayParameterType::class, false)) {
		final class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class, false)) {
		final class Types {
			public const BOOLEAN = 'boolean';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const DATE_MUTABLE = 'date';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const TIME_MUTABLE = 'time';
		}
	}
}

namespace Doctrine\DBAL\Query\Expression {
	if (!class_exists(ExpressionBuilder::class, false)) {
		final class ExpressionBuilder {
			public const EQ = '=';
			public const NEQ = '<>';
			public const LT = '<';
			public const LTE = '<=';
			public const GT = '>';
			public const GTE = '>=';
		}
	}
}
