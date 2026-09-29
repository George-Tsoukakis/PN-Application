<?php
/**
 * PHPStan extension: types the `global $wpdb` variable as wpdb.
 *
 * PHPStan types a variable imported with `global` as mixed, which switches
 * off every check on $wpdb calls (argument counts, prepare() placeholders,
 * return types). WordPress always binds $wpdb to a wpdb instance.
 *
 * @package PlanDose
 */

declare( strict_types = 1 );

namespace PlanDose\PhpQa;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class GlobalWpdbTypeExtension implements ExpressionTypeResolverExtension {

	public function getType( Expr $expr, Scope $scope ): ?Type {
		if ( $expr instanceof Expr\Variable && 'wpdb' === $expr->name ) {
			return new ObjectType( 'wpdb' );
		}
		return null;
	}
}
