<?php

namespace SecureRun\Tests;

use SecureRun\RunOptions;
use InvalidArgumentException;

class RunOptionsTest extends TestCase
{
    public function testGetValidKeysReturnsAllValidKeys()
    {
        $keys = RunOptions::getValidKeys();

        $this->assertIsArray($keys);
        $this->assertNotEmpty($keys);
        $this->assertContains(RunOptions::UNSECURE_ENV_ACCESS, $keys);
    }

    public function testGetDefaultsReturnsAllDefaults()
    {
        $defaults = RunOptions::getDefaults();

        $this->assertIsArray($defaults);
        $this->assertArrayHasKey(RunOptions::UNSECURE_ENV_ACCESS, $defaults);
        $this->assertFalse($defaults[RunOptions::UNSECURE_ENV_ACCESS]);
    }

    public function testIsValidKeyReturnsTrueForValidKey()
    {
        $this->assertTrue(RunOptions::isValidKey(RunOptions::UNSECURE_ENV_ACCESS));
    }

    public function testIsValidKeyReturnsFalseForInvalidKey()
    {
        $this->assertFalse(RunOptions::isValidKey('invalid_key'));
        $this->assertFalse(RunOptions::isValidKey('unsecure_env_acces')); // typo
        $this->assertFalse(RunOptions::isValidKey(''));
    }

    public function testValidateAndNormalizeAcceptsEmptyArray()
    {
        $result = RunOptions::validateAndNormalize(array());

        $this->assertIsArray($result);
        $this->assertEquals(RunOptions::getDefaults(), $result);
    }

    public function testValidateAndNormalizeAcceptsValidOptions()
    {
        $options = array(
            RunOptions::UNSECURE_ENV_ACCESS => true,
        );

        $result = RunOptions::validateAndNormalize($options);

        $this->assertTrue($result[RunOptions::UNSECURE_ENV_ACCESS]);
    }

    public function testValidateAndNormalizeMergesWithDefaults()
    {
        $options = array(
            RunOptions::UNSECURE_ENV_ACCESS => true,
        );

        $result = RunOptions::validateAndNormalize($options);

        // Should have all default keys
        $defaults = RunOptions::getDefaults();
        foreach ($defaults as $key => $value) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testValidateAndNormalizeRejectsInvalidKey()
    {
        $this->expectExceptionCompat(InvalidArgumentException::class);

        RunOptions::validateAndNormalize(array(
            'invalid_key' => true,
        ));
    }

    public function testValidateAndNormalizeRejectsNonStringKey()
    {
        $this->expectExceptionCompat(InvalidArgumentException::class);

        RunOptions::validateAndNormalize(array(
            123 => true,
        ));
    }

    public function testValidateAndNormalizeRejectsNonBooleanForUnsecureEnvAccess()
    {
        $this->expectExceptionCompat(InvalidArgumentException::class);

        RunOptions::validateAndNormalize(array(
            RunOptions::UNSECURE_ENV_ACCESS => 1, // truthy but not boolean
        ));
    }

    public function testValidateAndNormalizeRejectsStringTrueForUnsecureEnvAccess()
    {
        $this->expectExceptionCompat(InvalidArgumentException::class);

        RunOptions::validateAndNormalize(array(
            RunOptions::UNSECURE_ENV_ACCESS => 'true', // string, not boolean
        ));
    }

    public function testValidateAndNormalizeAcceptsBooleanFalse()
    {
        $result = RunOptions::validateAndNormalize(array(
            RunOptions::UNSECURE_ENV_ACCESS => false,
        ));

        $this->assertFalse($result[RunOptions::UNSECURE_ENV_ACCESS]);
    }

    public function testGetReturnsDefaultWhenKeyNotSet()
    {
        $value = RunOptions::get(array(), RunOptions::UNSECURE_ENV_ACCESS);

        $defaults = RunOptions::getDefaults();
        $this->assertEquals($defaults[RunOptions::UNSECURE_ENV_ACCESS], $value);
    }

    public function testGetReturnsValueWhenKeyIsSet()
    {
        $options = array(
            RunOptions::UNSECURE_ENV_ACCESS => true,
        );

        $value = RunOptions::get($options, RunOptions::UNSECURE_ENV_ACCESS);

        $this->assertTrue($value);
    }

    public function testGetThrowsForInvalidKey()
    {
        $this->expectExceptionCompat(InvalidArgumentException::class);

        RunOptions::get(array(), 'invalid_key');
    }

    public function testConstantValue()
    {
        $this->assertEquals('unsecure_env_access', RunOptions::UNSECURE_ENV_ACCESS);
    }
}


