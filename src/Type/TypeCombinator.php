<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;

class TypeCombinator
{

	public static function addNull(Type $type): Type
	{
		return self::union($type, new NullType());
	}

	public static function remove(Type $fromType, Type $typeToRemove): Type
	{
		if ($typeToRemove instanceof UnionType) {
			foreach ($typeToRemove->getTypes() as $unionTypeToRemove) {
				$fromType = self::remove($fromType, $unionTypeToRemove);
			}
			return $fromType;
		}

		if ($fromType instanceof UnionType) {
			$innerTypes = [];
			foreach ($fromType->getTypes() as $innerType) {
				$innerTypes[] = self::remove($innerType, $typeToRemove);
			}

			return self::union(...$innerTypes);
		}

		if ($fromType instanceof BooleanType && $fromType->isSuperTypeOf(new BooleanType())->yes()) {
			if ($typeToRemove instanceof ConstantBooleanType) {
				return new ConstantBooleanType(!$typeToRemove->getValue());
			}
		} elseif ($fromType instanceof IterableType) {
			$traversableType = new ObjectType(\Traversable::class);
			$arrayType = (new ArrayType(new MixedType(), new MixedType()));
			if ($arrayType->isSuperTypeOf($typeToRemove)->yes()) {
				return $traversableType;
			}
			if ($traversableType->isSuperTypeOf($typeToRemove)->yes()) {
				return $arrayType;
			}
		} elseif ($fromType instanceof DefaultArrayKeyType) {
			if ($typeToRemove instanceof ConstantIntegerType || $typeToRemove instanceof ConstantStringType) {
				return $fromType;
			}
			return self::remove($fromType->getUnionType(), $typeToRemove);
		}

		if ($typeToRemove->isSuperTypeOf($fromType)->yes()) {
			return new NeverType();
		}

		return $fromType;
	}

	public static function removeNull(Type $type): Type
	{
		return self::remove($type, new NullType());
	}

	public static function containsNull(Type $type): bool
	{
		if ($type instanceof UnionType) {
			foreach ($type->getTypes() as $innerType) {
				if ($innerType instanceof NullType) {
					return true;
				}
			}

			return false;
		}

		return $type instanceof NullType;
	}

	public static function union(Type ...$types): Type
	{
		//var_dump(implode(', ', array_map(function (Type $type): string {
		//	return $type->describe(VerbosityLevel::value());
		//}, $types)));
		// transform A | (B | C) to A | B | C
		for ($i = 0; $i < count($types); $i++) {
			if (!($types[$i] instanceof UnionType)) {
				continue;
			}

			array_splice($types, $i, 1, $types[$i]->getTypes());
		}

		// join arrays
		for ($i = 0; $i < count($types); $i++) {
			for ($j = $i + 1; $j < count($types); $j++) {
				if ($types[$i] instanceof ArrayType && $types[$j] instanceof ArrayType) {
					$types[$i] = $types[$i]->unionWith($types[$j]);
					array_splice($types, $j, 1);
					continue 2;
				}
			}
		}

		// transform A | (B | C) to A | B | C (again!)
		for ($i = 0; $i < count($types); $i++) {
			if (!($types[$i] instanceof UnionType)) {
				continue;
			}

			array_splice($types, $i, 1, $types[$i]->getTypes());
		}

		// simplify true | false to bool
		// simplify string[] | int[] to (string|int)[]
		for ($i = 0; $i < count($types); $i++) {
			for ($j = $i + 1; $j < count($types); $j++) {
				if ($types[$i] instanceof ConstantBooleanType && $types[$j] instanceof ConstantBooleanType && $types[$i]->getValue() !== $types[$j]->getValue()) {
					$types[$i] = new BooleanType();
					array_splice($types, $j, 1);
					continue 2;
				}

				if ($types[$i] instanceof IterableType && $types[$j] instanceof IterableType) {
					$types[$i] = new IterableType(
						self::union($types[$i]->getIterableKeyType(), $types[$j]->getIterableKeyType()),
						self::union($types[$i]->getIterableValueType(), $types[$j]->getIterableValueType())
					);
					array_splice($types, $j, 1);
					continue 2;
				}
			}
		}

		// transform A | A to A
		// transform A | never to A
		// transform true | bool to bool
		for ($i = 0; $i < count($types); $i++) {
			for ($j = $i + 1; $j < count($types); $j++) {
				if ($types[$j]->isSuperTypeOf($types[$i])->yes()) {
					array_splice($types, $i--, 1);
					continue 2;

				}

				if ($types[$i]->isSuperTypeOf($types[$j])->yes()) {
					array_splice($types, $j--, 1);
					continue 1;
				}
			}
		}

		if (count($types) === 0) {
			return new NeverType();

		} elseif (count($types) === 1) {
			return $types[0];
		}

		return new UnionType($types);
	}

	public static function intersect(Type ...$types): Type
	{
		// transform A & (B | C) to (A & B) | (A & C)
		foreach ($types as $i => $type) {
			if ($type instanceof UnionType) {
				$topLevelUnionSubTypes = [];
				foreach ($type->getTypes() as $innerUnionSubType) {
					$topLevelUnionSubTypes[] = self::intersect(
						$innerUnionSubType,
						...array_slice($types, 0, $i),
						...array_slice($types, $i + 1)
					);
				}

				return self::union(...$topLevelUnionSubTypes);
			}
		}

		// transform A & (B & C) to A & B & C
		foreach ($types as $i => &$type) {
			if (!($type instanceof IntersectionType)) {
				continue;
			}

			array_splice($types, $i, 1, $type->getTypes());
		}

		// transform IntegerType & ConstantIntegerType to ConstantIntegerType
		// transform Child & Parent to Child
		// transform Object & ~null to Object
		// transform A & A to A
		// transform int[] & string to never
		// transform callable & int to never
		// transform A & ~A to never
		// transform int & string to never
		for ($i = 0; $i < count($types); $i++) {
			for ($j = $i + 1; $j < count($types); $j++) {
				$isSuperTypeA = $types[$j]->isSuperTypeOf($types[$i]);
				if ($isSuperTypeA->no()) {
					return new NeverType();

				} elseif ($isSuperTypeA->yes()) {
					array_splice($types, $j--, 1);
					continue;
				}

				$isSuperTypeB = $types[$i]->isSuperTypeOf($types[$j]);
				if ($isSuperTypeB->maybe()) {
					continue;

				}

				if ($isSuperTypeB->yes()) {
					array_splice($types, $i--, 1);
					continue 2;
				}
			}
		}

		if (count($types) === 1) {
			return $types[0];

		}

		return new IntersectionType($types);
	}

}
