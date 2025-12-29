<?php

namespace SecureRun\Tests;

use SecureRun\BubblewrapSandboxRunner;
use SecureRun\Exceptions\BubblewrapUnavailableException;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class BubblewrapSandboxTest extends TestCase
{
    /**
     * Simple test double that exposes internal helpers.
     */
    protected function makeExposedSandbox()
    {
        return new class(
            PHP_BINARY,
            BubblewrapSandboxRunner::defaultBaseArgs(),
            BubblewrapSandboxRunner::defaultReadOnlyBinds(),
            BubblewrapSandboxRunner::defaultWritableBinds(),
            function () {
                // Skip binary validation in tests; handled by parent when needed.
            }
        ) extends BubblewrapSandboxRunner {
            public function normalizePublic(array $binds)
            {
                return $this->normalizeBinds($binds);
            }

            public static function binaryExistsInPathPublic($binary)
            {
                return parent::binaryExistsInPath($binary);
            }

            public function buildCommandPublic(array $command, array $extraBinds = array())
            {
                return $this->buildCommand($command, $extraBinds);
            }
        };
    }

    /**
     * @return BubblewrapSandboxRunner
     */
    protected function makeSandbox()
    {
        $binary = PHP_BINARY; // ensure executable exists for tests

        return new BubblewrapSandboxRunner(
            $binary,
            BubblewrapSandboxRunner::defaultBaseArgs(),
            BubblewrapSandboxRunner::defaultReadOnlyBinds(),
            BubblewrapSandboxRunner::defaultWritableBinds()
        );
    }

    public function testBuildCommandIncludesBaseAndBinds()
    {
        $sandbox = $this->makeSandbox();

        $command = array('echo', 'hello');
        $extraBinds = array(
            array('from' => '/tmp/in.txt', 'to' => '/tmp/in.txt', 'read_only' => true),
            array('from' => '/tmp/out', 'to' => '/tmp/out', 'read_only' => false),
        );

        $result = $sandbox->buildCommand($command, $extraBinds);

        $this->assertSame($command[0], $result[count($result) - 2]);
        $this->assertSame($command[1], $result[count($result) - 1]);
        $this->assertContains('--unshare-all', $result);
        $this->assertContains('--ro-bind', $result);
        $this->assertContains('/tmp/out', $result);
    }

    public function testEmptyCommandThrows()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->buildCommand(array());
    }

    public function testThrowsWhenBubblewrapIsMissing()
    {
        $sandbox = new BubblewrapSandboxRunner(
            'non-existent-bwrap-binary',
            BubblewrapSandboxRunner::defaultBaseArgs(),
            BubblewrapSandboxRunner::defaultReadOnlyBinds(),
            BubblewrapSandboxRunner::defaultWritableBinds()
        );

        $this->expectExceptionCompat(BubblewrapUnavailableException::class);
        $sandbox->buildCommand(array('echo', 'test'));
    }

    public function testPathBinaryMustBeExecutableEvenWhenNameExistsInPath()
    {
        $localDir = sys_get_temp_dir() . '/bwrap_guard_local_' . uniqid();
        $pathDir = sys_get_temp_dir() . '/bwrap_guard_path_' . uniqid();
        if (!mkdir($localDir)) {
            $this->fail('Failed to create local temp directory');
        }
        if (!mkdir($pathDir)) {
            $this->fail('Failed to create PATH temp directory');
        }

        $localBinary = $localDir . '/bwrap';
        if (file_put_contents($localBinary, "#!/bin/sh\necho local") === false) {
            $this->fail('Failed to write local binary');
        }
        if (!chmod($localBinary, 0644)) { // intentionally not executable
            $this->fail('Failed to set permissions on local binary');
        }

        $pathBinary = $pathDir . '/bwrap';
        if (file_put_contents($pathBinary, "#!/bin/sh\necho path") === false) {
            $this->fail('Failed to write PATH binary');
        }
        if (!chmod($pathBinary, 0755)) {
            $this->fail('Failed to set permissions on PATH binary');
        }

        $originalPath = getenv('PATH');
        try {
            putenv('PATH=' . $pathDir . PATH_SEPARATOR . $originalPath);

            $sandbox = new BubblewrapSandboxRunner(
                $localBinary,
                BubblewrapSandboxRunner::defaultBaseArgs(),
                BubblewrapSandboxRunner::defaultReadOnlyBinds(),
                BubblewrapSandboxRunner::defaultWritableBinds()
            );

            $this->expectExceptionCompat(BubblewrapUnavailableException::class);

            $sandbox->buildCommand(array('echo', 'test'));
        } finally {
            putenv('PATH=' . $originalPath);
            if (file_exists($localBinary)) {
                unlink($localBinary);
            }
            if (file_exists($pathBinary)) {
                unlink($pathBinary);
            }
            if (is_dir($localDir)) {
                rmdir($localDir);
            }
            if (is_dir($pathDir)) {
                rmdir($pathDir);
            }
        }
    }

    public function testProcessBuildsProcessInstance()
    {
        $sandbox = new BubblewrapSandboxRunner(PHP_BINARY, array(), array(), array(), function () {
            // Skip binary validation in tests; handled elsewhere.
        });
        $process = $sandbox->process(array('echo', 'hi'), array(), null, null, 10);

        $this->assertInstanceOf(Process::class, $process);
        $this->assertEquals(10, $process->getTimeout());
        $this->assertNotFalse(strpos($process->getCommandLine(), 'echo'));
    }

    public function testRunUsesOverriddenProcess()
    {
        $sandbox = new class(PHP_BINARY, array(), array(), array(), function () {
            // Skip binary validation in tests; handled elsewhere.
        }) extends BubblewrapSandboxRunner {
            public $called = false;

            public function process(array $command, array $extraBinds = array(), $workingDirectory = null, array $env = null, $timeout = 60)
            {
                $this->called = true;
                return new Process(array(PHP_BINARY, '-r', 'echo "ok";'), null, null, null, 5);
            }
        };

        $process = $sandbox->run(array('ignored'));

        $this->assertTrue($sandbox->called);
        $this->assertSame('ok', trim($process->getOutput()));
    }

    public function testFromConfigBuildsWithProvidedValues()
    {
        $config = array(
            'binary' => PHP_BINARY,
            'base_args' => array('--foo'),
            'read_only_binds' => array('/etc/ssl'),
            'write_binds' => array('/tmp/custom'),
        );

        $sandbox = BubblewrapSandboxRunner::fromConfig($config);
        $built = $sandbox->buildCommand(array('echo', 'x'));

        $this->assertContains('--foo', $built);
        $this->assertContains('/etc/ssl', $built);
        $this->assertContains('/tmp/custom', $built);
    }

    public function testDefaultsExposeExpectedMounts()
    {
        $defaults = BubblewrapSandboxRunner::defaultBaseArgs();
        $this->assertContains('--unshare-all', $defaults);
        $this->assertContains('--die-with-parent', $defaults);
        $this->assertContains('/proc', $defaults);

        $readOnly = BubblewrapSandboxRunner::defaultReadOnlyBinds();
        $this->assertContains('/usr', $readOnly);

        $write = BubblewrapSandboxRunner::defaultWritableBinds();
        $this->assertEmpty($write);
    }

    public function testDefaultBinaryUsesAbsolutePath()
    {
        $this->assertSame('/usr/bin/bwrap', BubblewrapSandboxRunner::defaultBinary());
    }

    public function testDefaultReadOnlyBindsAddsLib64Conditionally()
    {
        $readOnly = BubblewrapSandboxRunner::defaultReadOnlyBinds();
        $hasLib64 = is_dir('/lib64');

        if ($hasLib64) {
            $this->assertContains('/lib64', $readOnly);
        }

        if (!$hasLib64) {
            $this->assertNotContains('/lib64', $readOnly);
        }
    }

    public function testNormalizeBindsHandlesStringsAndArrays()
    {
        $sandbox = $this->makeExposedSandbox();

        $normalized = $sandbox->normalizePublic(array(
            '/tmp/file',
            array('from' => '/a', 'to' => '/b'),
        ));

        $this->assertCount(2, $normalized);
        $this->assertTrue($normalized[0]['read_only']);
        $this->assertSame('/tmp/file', $normalized[0]['from']);
        $this->assertSame('/b', $normalized[1]['to']);
        $this->assertTrue($normalized[1]['read_only']);
    }

    public function testNormalizeBindsThrowsOnInvalidEntries()
    {
        $sandbox = $this->makeExposedSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->normalizePublic(array(
            '/tmp/file',
            123,
        ));
    }

    public function testBinaryExistsInPathDetectsExecutable()
    {
        $sandbox = $this->makeExposedSandbox();

        $dir = sys_get_temp_dir() . '/bwrap_guard_' . uniqid();
        if (!mkdir($dir)) {
            $this->fail('Failed to create temp directory');
        }
        $binary = $dir . '/dummybin';
        if (file_put_contents($binary, "#!/bin/sh\necho dummy") === false) {
            $this->fail('Failed to write dummy binary');
        }
        if (!chmod($binary, 0755)) {
            $this->fail('Failed to set permissions on dummy binary');
        }

        $originalPath = getenv('PATH');
        try {
            putenv('PATH=' . $dir);

            $this->assertTrue($sandbox::binaryExistsInPathPublic('dummybin'));
        } finally {
            putenv('PATH=' . $originalPath);

            if (file_exists($binary)) {
                unlink($binary);
            }

            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testNormalizeBindsRejectsRelativePaths()
    {
        $sandbox = $this->makeExposedSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->normalizePublic(array(
            'relative/path',
        ));
    }

    public function testNormalizeBindsRejectsPathTraversal()
    {
        $sandbox = $this->makeExposedSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->normalizePublic(array(
            '/etc/../../etc/passwd',
        ));
    }

    public function testNormalizeBindsRejectsNullBytes()
    {
        $sandbox = $this->makeExposedSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->normalizePublic(array(
            "/tmp/file\0.txt",
        ));
    }

    public function testBuildCommandRejectsRelativePathsInBinds()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->buildCommand(array('echo', 'test'), array(
            'relative/path',
        ));
    }

    public function testBuildCommandRejectsPathTraversalInBinds()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->buildCommand(array('echo', 'test'), array(
            '/tmp/../../etc/passwd',
        ));
    }

    public function testProcessRejectsRelativeWorkingDirectory()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->process(array('echo', 'test'), array(), 'relative/path');
    }

    public function testProcessRejectsPathTraversalInWorkingDirectory()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->process(array('echo', 'test'), array(), '/tmp/../../etc');
    }

    public function testCommandValidationRejectsNonStringParts()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->buildCommand(array('echo', 123));
    }

    public function testCommandValidationRejectsNullBytes()
    {
        $sandbox = $this->makeSandbox();

        $this->expectExceptionCompat(InvalidArgumentException::class);
        $sandbox->buildCommand(array("echo\0", 'test'));
    }

    public function testConstructorRejectsEmptyBinary()
    {
        $this->expectExceptionCompat(InvalidArgumentException::class);
        new BubblewrapSandboxRunner(
            '',
            BubblewrapSandboxRunner::defaultBaseArgs(),
            BubblewrapSandboxRunner::defaultReadOnlyBinds(),
            BubblewrapSandboxRunner::defaultWritableBinds()
        );
    }

    public function testFromConfigValidatesArrayTypes()
    {
        $config = array(
            'base_args' => 'not-an-array',
            'read_only_binds' => 'not-an-array',
            'write_binds' => 'not-an-array',
        );

        $sandbox = BubblewrapSandboxRunner::fromConfig($config);
        // Should use defaults when invalid types are provided
        $this->assertInstanceOf(BubblewrapSandboxRunner::class, $sandbox);

        // Verify defaults were actually applied
        $built = $sandbox->buildCommand(array('echo', 'test'));
        $this->assertContains('--unshare-all', $built);
        $this->assertContains('/usr', $built);
    }

    public function testAssertBubblewrapIsExecutableChecksFileExists()
    {
        $sandbox = new BubblewrapSandboxRunner(
            '/nonexistent/binary/path',
            BubblewrapSandboxRunner::defaultBaseArgs(),
            BubblewrapSandboxRunner::defaultReadOnlyBinds(),
            BubblewrapSandboxRunner::defaultWritableBinds()
        );

        $this->expectExceptionCompat(BubblewrapUnavailableException::class);
        $sandbox->buildCommand(array('echo', 'test'));
    }
}
