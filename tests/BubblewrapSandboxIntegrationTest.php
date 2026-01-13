<?php

namespace SecureRun\Tests;

use SecureRun\BubblewrapSandboxRunner;
use SecureRun\ProcessWrapper;
use SecureRun\Exceptions\BubblewrapUnavailableException;
use Symfony\Component\Process\Process;

class BubblewrapSandboxIntegrationTest extends TestCase
{
    /**
     * Run a sandboxed command with the given configuration, skipping when bubblewrap
     * is not available on the host or when namespaces cannot be created.
     *
     * @param array<int,string> $baseArgs
     * @param array<int,string> $readOnlyBinds
     * @param array<int,string> $command
     * @param array<int,mixed>  $extraBinds
     * @param array<int,string> $writeBinds
     * @return \SecureRun\ProcessWrapper
     */
    protected function runSandboxCommand(array $baseArgs, array $readOnlyBinds, array $command, array $extraBinds = array(), array $writeBinds = array())
    {
        $binary = BubblewrapSandboxRunner::defaultBinary();
        if (!is_executable($binary)) {
            $this->markTestSkipped('bubblewrap binary is not available at ' . $binary);
        }

        try {
            $runner = new BubblewrapSandboxRunner($binary, $baseArgs, $readOnlyBinds, $writeBinds);
            $wrapper = $runner->process($command, $extraBinds);
        } catch (BubblewrapUnavailableException $e) {
            $this->markTestSkipped('bubblewrap unavailable: ' . $e->getMessage());
        }

        $wrapper->run();

        $this->skipIfNamespaceUnsupported($wrapper);

        return $wrapper;
    }

    /**
     * Skip tests when the kernel refuses to create namespaces (common in CI).
     *
     * @param \Symfony\Component\Process\Process|\SecureRun\ProcessWrapper $process
     * @return void
     */
    protected function skipIfNamespaceUnsupported($process)
    {
        if ($process->isSuccessful()) {
            return;
        }

        $error = trim($process->getErrorOutput());
        if ($error === '') {
            $error = trim($process->getOutput());
        }

        if (strpos($error, 'Operation not permitted') !== false || strpos($error, 'Permission denied') !== false) {
            $this->markTestSkipped('bubblewrap cannot create namespaces in this environment: ' . $error);
        }
    }

    /**
     * Normalize string containment assertions across PHPUnit versions.
     *
     * @param string $needle
     * @param string $haystack
     * @return void
     */
    protected function assertStringContainsCompat($needle, $haystack)
    {
        if (method_exists($this, 'assertStringContainsString')) {
            $this->assertStringContainsString($needle, $haystack);
            return;
        }

        $this->assertContains($needle, $haystack);
    }

    /**
     * Normalize negative string containment assertions across PHPUnit versions.
     *
     * @param string $needle
     * @param string $haystack
     * @return void
     */
    protected function assertStringNotContainsCompat($needle, $haystack)
    {
        if (method_exists($this, 'assertStringNotContainsString')) {
            $this->assertStringNotContainsString($needle, $haystack);
            return;
        }

        $this->assertNotContains($needle, $haystack);
    }

    public function testBasicSandboxShellIsolation()
    {
        $readOnly = array('/usr', '/bin', '/lib', '/sbin', '/etc');
        if (is_dir('/lib64')) {
            $readOnly[] = '/lib64';
        }

        $baseArgs = array(
            '--unshare-pid',
            '--clearenv',
            '--setenv',
            'PATH',
            '/usr/bin:/bin:/usr/sbin:/sbin',
            '--proc',
            '/proc',
            '--dev',
            '/dev',
            '--tmpfs',
            '/tmp',
            '--chdir',
            '/tmp',
        );

        $script = implode("\n", array(
            'echo "---LS---"',
            'if [ -d /home ]; then ls -A /home; fi',
            'echo "---PS---"',
            'ps aux',
            'echo "---ENV---"',
            'env',
        ));

        $process = $this->runSandboxCommand($baseArgs, $readOnly, array('/bin/bash', '-c', $script));
        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $this->fail('Sandbox failed to start: ' . $error);
        }

        $output = $process->getOutput();
        $parts = explode('---PS---', $output);
        $lsSection = isset($parts[0]) ? trim(str_replace('---LS---', '', $parts[0])) : '';
        $rest = isset($parts[1]) ? $parts[1] : '';
        $psParts = explode('---ENV---', $rest);
        $psSection = isset($psParts[0]) ? trim($psParts[0]) : '';
        $envSection = isset($psParts[1]) ? trim($psParts[1]) : '';

        $home = getenv('HOME');
        if ($home !== false) {
            $this->assertStringNotContainsCompat(basename($home), $lsSection);
        }

        $psLines = array_filter(explode("\n", $psSection));
        $this->assertNotEmpty($psLines);
        $this->assertLessThanOrEqual(4, count($psLines));
        $this->assertStringNotContainsCompat('phpunit', $psSection);

        $this->assertStringNotContainsCompat('HOME=', $envSection);
        $this->assertStringNotContainsCompat('USER=', $envSection);
    }

    public function testWritableBindAllowsLimitedHomeAccess()
    {
        $readOnly = BubblewrapSandboxRunner::defaultReadOnlyBinds();
        if (!in_array('/etc', $readOnly, true)) {
            $readOnly[] = '/etc';
        }

        $baseArgs = array(
            '--unshare-all',
            '--die-with-parent',
            '--new-session',
            '--proc',
            '/proc',
            '--dev',
            '/dev',
            '--tmpfs',
            '/tmp',
            '--setenv',
            'PATH',
            '/usr/bin:/bin:/usr/sbin:/sbin',
            '--chdir',
            '/home',
        );

        $tempDir = sys_get_temp_dir() . '/sandbox-test-' . uniqid();
        if (!mkdir($tempDir)) {
            $this->fail('Failed to create temporary directory for sandbox bind.');
        }

        try {
            $process = $this->runSandboxCommand(
                $baseArgs,
                $readOnly,
                array('/bin/bash', '-c', "echo 'Teste de isolamento' > arquivo.txt && cat arquivo.txt"),
                array(
                    array('from' => $tempDir, 'to' => '/home', 'read_only' => false),
                )
            );

            if (!$process->isSuccessful()) {
                $this->fail('Sandbox command failed: ' . trim($process->getErrorOutput()));
            }

            $this->assertSame("Teste de isolamento\n", $process->getOutput());
            $this->assertFileExists($tempDir . '/arquivo.txt');
            $this->assertSame("Teste de isolamento\n", file_get_contents($tempDir . '/arquivo.txt'));
        } finally {
            if (file_exists($tempDir . '/arquivo.txt')) {
                unlink($tempDir . '/arquivo.txt');
            }

            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testNetworkIsolationBlocksPing()
    {
        $ping = null;
        if (is_executable('/bin/ping')) {
            $ping = '/bin/ping';
        } elseif (is_executable('/usr/bin/ping')) {
            $ping = '/usr/bin/ping';
        }

        if ($ping === null) {
            $this->markTestSkipped('ping binary not available on this system.');
        }

        $readOnly = array('/usr', '/bin', '/lib', '/sbin', '/etc/resolv.conf', '/etc/ssl');
        if (is_dir('/lib64')) {
            $readOnly[] = '/lib64';
        }

        $baseArgs = array(
            '--unshare-net',
            '--proc',
            '/proc',
            '--dev',
            '/dev',
            '--tmpfs',
            '/tmp',
            '--setenv',
            'PATH',
            '/usr/bin:/bin:/usr/sbin:/sbin',
            '--chdir',
            '/tmp',
        );

        $command = array('/bin/bash', '-c', $ping . " -c 1 8.8.8.8 || echo 'Rede bloqueada com sucesso'");
        $process = $this->runSandboxCommand($baseArgs, $readOnly, $command);

        $this->assertStringContainsCompat('Rede bloqueada com sucesso', $process->getOutput());
    }

    public function testPythonRunsInsideSandbox()
    {
        $python = '/usr/bin/python3';
        if (!is_executable($python)) {
            $this->markTestSkipped('python3 is not available at ' . $python);
        }

        $baseArgs = BubblewrapSandboxRunner::defaultBaseArgs();
        $readOnly = BubblewrapSandboxRunner::defaultReadOnlyBinds();

        $process = $this->runSandboxCommand(
            $baseArgs,
            $readOnly,
            array($python, '-c', 'import os; print("User:", os.getuid()); print("PID:", os.getpid())')
        );

        if (!$process->isSuccessful()) {
            $this->fail('Python failed inside sandbox: ' . trim($process->getErrorOutput()));
        }

        $output = $process->getOutput();
        $this->assertStringContainsCompat('User:', $output);
        $this->assertStringContainsCompat('PID:', $output);
    }
}
