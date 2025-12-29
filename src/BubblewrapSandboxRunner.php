<?php

namespace SecureRun;

use SecureRun\Exceptions\BubblewrapUnavailableException;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * Core bubblewrap runner used by the facade/service provider.
 *
 * Compatible with PHP 5.6+ and Laravel 5.x through 12.x (no scalar type hints).
 */
class BubblewrapSandboxRunner
{
    /**
     * Path to the bubblewrap binary (or the command name if in PATH).
     *
     * @var string
     */
    protected $binary;

    /**
     * Base arguments passed to bubblewrap before bind mounts.
     *
     * @var array<int, string>
     */
    protected $baseArgs;

    /**
     * Directories mounted as read-only inside the sandbox.
     *
     * @var array<int, string>
     */
    protected $readOnlyBinds;

    /**
     * Directories mounted with write access inside the sandbox.
     *
     * @var array<int, string>
     */
    protected $writeBinds;

    /**
     * @param string            $binary        Bubblewrap binary path or name.
     * @param array<int,string> $baseArgs      Default flags passed to bwrap.
     * @param array<int,string> $readOnlyBinds Read-only mounts.
     * @param array<int,string> $writeBinds    Writable mounts.
     */
    public function __construct($binary, array $baseArgs, array $readOnlyBinds, array $writeBinds)
    {
        $this->binary = $binary;
        $this->baseArgs = $baseArgs;
        $this->readOnlyBinds = $readOnlyBinds;
        $this->writeBinds = $writeBinds;
    }

    /**
     * Build an instance from a Laravel-style config array.
     *
     * @param array<string,mixed> $config
     * @return static
     */
    public static function fromConfig(array $config)
    {
        $binary = isset($config['binary']) ? $config['binary'] : static::defaultBinary();
        $baseArgs = isset($config['base_args']) ? $config['base_args'] : static::defaultBaseArgs();
        $readOnly = isset($config['read_only_binds']) ? $config['read_only_binds'] : static::defaultReadOnlyBinds();
        $writable = isset($config['write_binds']) ? $config['write_binds'] : static::defaultWritableBinds();

        return new static($binary, $baseArgs, $readOnly, $writable);
    }

    /**
     * Create a Process ready to run the sandboxed command.
     *
     * @param array<int,string> $command          Binary plus arguments to run inside the sandbox.
     * @param array<int,mixed>  $extraBinds       Additional bind mounts.
     * @param string            $workingDirectory Working directory inside the sandbox.
     * @param array|null        $env              Additional environment variables for the sandboxed process.
     * @param int|null          $timeout          Seconds before timing out. Null = no timeout.
     * @return \Symfony\Component\Process\Process
     */
    public function process(array $command, array $extraBinds = array(), $workingDirectory = null, array $env = null, $timeout = 60)
    {
        $cmd = $this->buildCommand($command, $extraBinds);
        $process = new Process($cmd, $workingDirectory, $env, null, $timeout);

        return $process;
    }

    /**
     * Run a sandboxed command and throw if it fails.
     *
     * @param array<int,string> $command
     * @param array<int,mixed>  $extraBinds
     * @param string            $workingDirectory
     * @param array|null        $env
     * @param int|null          $timeout
     * @return \Symfony\Component\Process\Process
     */
    public function run(array $command, array $extraBinds = array(), $workingDirectory = null, array $env = null, $timeout = 60)
    {
        $process = $this->process($command, $extraBinds, $workingDirectory, $env, $timeout);
        $process->mustRun();

        return $process;
    }

    /**
     * Build the final command array executed by Symfony Process.
     *
     * @param array<int,string> $command
     * @param array<int,mixed>  $extraBinds
     * @return array<int,string>
     */
    public function buildCommand(array $command, array $extraBinds = array())
    {
        $this->assertBubblewrapIsExecutable();
        $this->assertCommandIsNotEmpty($command);
        $binds = $this->normalizeBinds($extraBinds);

        $parts = array($this->binary);
        foreach ($this->baseArgs as $arg) {
            $parts[] = $arg;
        }

        foreach ($this->readOnlyBinds as $path) {
            $parts[] = '--ro-bind';
            $parts[] = $path;
            $parts[] = $path;
        }

        foreach ($this->writeBinds as $path) {
            $parts[] = '--bind';
            $parts[] = $path;
            $parts[] = $path;
        }

        foreach ($binds as $bind) {
            $flag = $bind['read_only'] ? '--ro-bind' : '--bind';
            $parts[] = $flag;
            $parts[] = $bind['from'];
            $parts[] = $bind['to'];
        }

        foreach ($command as $piece) {
            $parts[] = $piece;
        }

        return $parts;
    }

    /**
     * Default bubblewrap binary path.
     *
     * @return string
     */
    public static function defaultBinary()
    {
        return '/usr/bin/bwrap';
    }

    /**
     * Default bubblewrap base arguments.
     *
     * @return array<int,string>
     */
    public static function defaultBaseArgs()
    {
        return array(
            '--unshare-all',
            '--die-with-parent',
            '--new-session',
            '--proc',
            '/proc',
            '--dev',
            '/dev',
            '--tmpfs',
            '/tmp',
            '--tmpfs',
            '/run',
            '--setenv',
            'PATH',
            '/usr/bin:/bin:/usr/sbin:/sbin',
            '--chdir',
            '/tmp',
        );
    }

    /**
     * Default read-only bind mounts.
     *
     * @return array<int,string>
     */
    public static function defaultReadOnlyBinds()
    {
        $paths = array(
            '/usr',
            '/bin',
            '/lib',
            '/sbin',
            '/etc/resolv.conf',
            '/etc/ssl',
        );

        if (is_dir('/lib64')) {
            $paths[] = '/lib64';
        }

        return $paths;
    }

    /**
     * Default writable bind mounts.
     *
     * @return array<int,string>
     */
    public static function defaultWritableBinds()
    {
        return array(
            '/tmp',
        );
    }

    /**
     * Ensure a command was provided.
     *
     * @param array<int, string> $command
     * @return void
     */
    protected function assertCommandIsNotEmpty(array $command)
    {
        if (empty($command)) {
            throw new InvalidArgumentException('You must provide a command to run inside the sandbox.');
        }
    }

    /**
     * Ensure bubblewrap is available to execute.
     *
     * @throws \SecureRun\Exceptions\BubblewrapUnavailableException
     * @return void
     */
    protected function assertBubblewrapIsExecutable()
    {
        if (!is_executable($this->binary) && !static::binaryExistsInPath($this->binary)) {
            throw new BubblewrapUnavailableException('Bubblewrap (bwrap) is not available or executable: ' . $this->binary);
        }
    }

    /**
     * Normalize user-provided bind definitions.
     *
     * @param array<int, mixed> $binds
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeBinds(array $binds)
    {
        $normalized = array();

        foreach ($binds as $bind) {
            if (is_string($bind)) {
                $normalized[] = array(
                    'from' => $bind,
                    'to' => $bind,
                    'read_only' => true,
                );
                continue;
            }

            if (is_array($bind) && isset($bind['from']) && isset($bind['to'])) {
                $normalized[] = array(
                    'from' => $bind['from'],
                    'to' => $bind['to'],
                    'read_only' => isset($bind['read_only']) ? (bool) $bind['read_only'] : true,
                );
            }
        }

        return $normalized;
    }

    /**
     * @param string $binary
     * @return bool
     */
    protected static function binaryExistsInPath($binary)
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH'));
        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
