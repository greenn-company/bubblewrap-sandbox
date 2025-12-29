<?php

namespace SecureRun\Tests;

if (class_exists('\PHPUnit\Framework\TestCase')) {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        /**
         * Compat helper for PHPUnit 5.
         *
         * @param string $exception
         * @return void
         */
        protected function expectExceptionCompat($exception)
        {
            $this->expectException($exception);
        }
    }
} else {
    abstract class TestCase extends \PHPUnit_Framework_TestCase
    {
        /**
         * Compat helper for PHPUnit 5.
         *
         * @param string $exception
         * @return void
         */
        protected function expectExceptionCompat($exception)
        {
            $this->setExpectedException($exception);
        }
    }
}
