<?php

namespace ApiGen\Tests\Reflections\ReflectionClass;


class ParentsTest extends TestCase
{

	public function testGetParentClass()
	{
		$this->assertInstanceOf(
			'ApiGen\Reflection\ReflectionClass', $this->reflectionClass->getParentClass()
		);
	}


	public function testGetParentClassName()
	{
		$this->assertSame('Project\ParentClass', $this->reflectionClass->getParentClassName());
	}


	public function testGetParentClasses()
	{
		$this->assertCount(1, $this->reflectionClass->getParentClasses());
	}


	public function testGetParentClassNameList()
	{
		$this->assertSame(['Project\ParentClass'], $this->reflectionClass->getParentClassNameList());
	}


	public function testGetDirectSubClasses()
	{
		$this->assertCount(1, $this->reflectionClassOfParent->getDirectSubClasses());
	}


	public function testIndirectSubClasses()
	{
		$this->assertCount(0, $this->reflectionClassOfParent->getIndirectSubClasses());
	}

}
