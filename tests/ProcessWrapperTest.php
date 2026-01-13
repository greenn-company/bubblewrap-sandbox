<?php

namespace SecureRun\Tests;

use SecureRun\ProcessWrapper;
use Symfony\Component\Process\Process;
use RuntimeException;

class ProcessWrapperTest extends TestCase
{
    protected function makeProcess()
    {
        return new Process(array(PHP_BINARY, '-r', 'echo "test output";'), null, null, null, 5);
    }

    public function testConstructorStoresProcess()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        $this->assertSame($process, $wrapper->getProcess());
    }

    public function testGetEnvThrowsWhenNotEnabled()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, array('TEST' => 'value'), false);

        $this->expectExceptionCompat(RuntimeException::class);
        $wrapper->getEnv();
    }

    public function testGetEnvReturnsEnvWhenEnabled()
    {
        $env = array('TEST_VAR' => 'test_value', 'HOME' => '/tmp');
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, $env, true);

        $result = $wrapper->getEnv();

        $this->assertEquals($env, $result);
    }

    public function testGetEnvReturnsEmptyArrayWhenEnvIsNullAndEnabled()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, true);

        $result = $wrapper->getEnv();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testIsEnvAccessEnabledReturnsTrueWhenEnabled()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, true);

        $this->assertTrue($wrapper->isEnvAccessEnabled());
    }

    public function testIsEnvAccessEnabledReturnsFalseWhenDisabled()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        $this->assertFalse($wrapper->isEnvAccessEnabled());
    }

    public function testWrapperDoesNotStoreEnvWhenNotEnabled()
    {
        $env = array('SECRET' => 'value');
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, $env, false);

        // Env should not be stored when not enabled
        $this->assertFalse($wrapper->isEnvAccessEnabled());
        $this->expectExceptionCompat(RuntimeException::class);
        $wrapper->getEnv();
    }

    public function testMagicCallDelegatesToProcess()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        // Test that wrapper delegates method calls to Process
        $this->assertInstanceOf(Process::class, $wrapper->getProcess());
        $this->assertEquals(5, $wrapper->getTimeout());

        // Run the process to test getOutput
        $process->run();
        $this->assertEquals('test output', trim($wrapper->getOutput()));
    }

    public function testMagicCallThrowsForNonExistentMethod()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        $this->expectExceptionCompat(\BadMethodCallException::class);
        $wrapper->nonExistentMethod();
    }

    public function testMagicGetDelegatesToProcess()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        // Access public property via magic get
        $timeout = $wrapper->getTimeout();
        $this->assertEquals(5, $timeout);
    }

    public function testMagicGetPreventsAccessToInternalProperties()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        // Should not be able to access internal properties from outside
        try {
            $val = $wrapper->process;
            $this->fail('Should have thrown exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot access protected property', $e->getMessage());
        }
    }

    public function testMagicSetPreventsModificationOfInternalProperties()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        $this->expectExceptionCompat(RuntimeException::class);
        $wrapper->env = array('test' => 'value');
    }

    public function testWrapperCanBeUsedAsProcess()
    {
        $process = $this->makeProcess();
        $wrapper = new ProcessWrapper($process, null, false);

        // Should be able to use wrapper like Process
        $process->run();
        $this->assertEquals('test output', trim($wrapper->getOutput()));
        $this->assertTrue($wrapper->isSuccessful());
    }

    public function testEnvIsIsolatedBetweenInstances()
    {
        $env1 = array('VAR1' => 'value1');
        $env2 = array('VAR2' => 'value2');

        $process1 = $this->makeProcess();
        $wrapper1 = new ProcessWrapper($process1, $env1, true);

        $process2 = $this->makeProcess();
        $wrapper2 = new ProcessWrapper($process2, $env2, true);

        $this->assertEquals($env1, $wrapper1->getEnv());
        $this->assertEquals($env2, $wrapper2->getEnv());
        $this->assertNotEquals($wrapper1->getEnv(), $wrapper2->getEnv());
    }
}


