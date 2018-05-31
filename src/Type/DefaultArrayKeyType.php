<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Traits\NonCallableTypeTrait;
use PHPStan\Type\Traits\NonIterableTypeTrait;
use PHPStan\Type\Traits\NonObjectTypeTrait;
use PHPStan\Type\Traits\NonOffsetAccessibleTypeTrait;

class DefaultArrayKeyType implements CompoundType
{

	use NonObjectTypeTrait;
	use NonIterableTypeTrait;
	use NonCallableTypeTrait;
	use NonOffsetAccessibleTypeTrait;

	/** @var UnionType */
	private $unionType;

	public function __construct()
	{
		$this->unionType = new UnionType([new IntegerType(), new StringType()]);
	}

	public function getUnionType(): UnionType
	{
		return $this->unionType;
	}

	public function isSubTypeOf(Type $otherType): TrinaryLogic
	{
		if ($otherType->isSuperTypeOf($this->getUnionType())->yes()) {
			return TrinaryLogic::createYes();
		}
		if ($otherType instanceof IntegerType) {
			return TrinaryLogic::createMaybe();
		}
		if ($otherType instanceof StringType) {
			return TrinaryLogic::createMaybe();
		}

		return $otherType->isSuperTypeOf($this->getUnionType());
	}

	public function getReferencedClasses(): array
	{
		return [];
	}

	public function accepts(Type $type): bool
	{
		return $this->unionType->accepts($type);
	}

	public function isSuperTypeOf(Type $type): TrinaryLogic
	{
		if ($type instanceof self) {
			return TrinaryLogic::createYes();
		}
		if ($type instanceof IntegerType) {
			return TrinaryLogic::createYes();
		}
		if ($type instanceof StringType) {
			return TrinaryLogic::createYes();
		}

		return $this->unionType->isSuperTypeOf($type);
	}

	public function describe(VerbosityLevel $level): string
	{
		return '(' . $this->unionType->describe($level) . ')';
	}

	public function toBoolean(): BooleanType
	{
		return $this->unionType->toBoolean();
	}

	public function toNumber(): Type
	{
		return $this->unionType->toNumber();
	}

	public function toInteger(): Type
	{
		return $this->unionType->toInteger();
	}

	public function toFloat(): Type
	{
		return $this->unionType->toFloat();
	}

	public function toString(): Type
	{
		return $this->unionType->toString();
	}

	public function toArray(): Type
	{
		return $this->unionType->toArray();
	}

	public static function __set_state(array $properties): Type
	{
		return new self();
	}

}
