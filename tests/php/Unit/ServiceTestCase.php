<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

abstract class ServiceTestCase extends TestCase {
	protected function withoutConstructor(string $className): object {
		return (new ReflectionClass($className))->newInstanceWithoutConstructor();
	}

	protected function invoke(object $object, string $method, mixed ...$arguments): mixed {
		$reflection = new ReflectionMethod($object, $method);
		$reflection->setAccessible(true);
		return $reflection->invoke($object, ...$arguments);
	}

	protected function setProperty(object $object, string $property, mixed $value): void {
		$reflection = new ReflectionProperty($object, $property);
		$reflection->setValue($object, $value);
	}
}
