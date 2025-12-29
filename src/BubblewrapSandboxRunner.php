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
     * Optional binary validator callable for testability.
     *
     * @var callable|null
     */
    protected $binaryValidator;

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
     * Constructor.
     *
     * @param string            $binary        Bubblewrap binary path or name.
     * @param array<int,string> $baseArgs      Default flags passed to bwrap.
     * @param array<int,string> $readOnlyBinds Read-only mounts.
     * @param array<int,string> $writeBinds    Writable mounts.
     * @param callable|null     $binaryValidator Optional validator callable for the binary (primarily for tests).
     */
    public function __construct($binary, array $baseArgs, array $readOnlyBinds, array $writeBinds, $binaryValidator = null)
    {
        if (!is_string($binary) || $binary === '') {
            throw new InvalidArgumentException('Binary path must be a non-empty string.');
        }

        $this->binary = $binary;
        $this->baseArgs = $baseArgs;
        $this->readOnlyBinds = $readOnlyBinds;
        $this->writeBinds = $writeBinds;
        $this->binaryValidator = $binaryValidator;
    }

    /**
     * Build an instance from a Laravel-style config array.
     *
     * @param array<string,mixed> $config Configuration array with keys: binary, base_args, read_only_binds, write_binds, binary_validator.
     * @return static
     */
    public static function fromConfig(array $config)
    {
        $binary = isset($config['binary']) && is_string($config['binary']) ? $config['binary'] : static::defaultBinary();
        $baseArgs = isset($config['base_args']) && is_array($config['base_args']) ? $config['base_args'] : static::defaultBaseArgs();
        $readOnly = isset($config['read_only_binds']) && is_array($config['read_only_binds']) ? $config['read_only_binds'] : static::defaultReadOnlyBinds();
        $writable = isset($config['write_binds']) && is_array($config['write_binds']) ? $config['write_binds'] : static::defaultWritableBinds();
        $validator = isset($config['binary_validator']) && is_callable($config['binary_validator']) ? $config['binary_validator'] : null;

        return new static($binary, $baseArgs, $readOnly, $writable, $validator);
    }

    /**
     * Create a Process ready to run the sandboxed command.
     *
     * @param array<int,string> $command          Binary plus arguments to run inside the sandbox.
     * @param array<int,mixed>  $extraBinds       Additional bind mounts.
     * @param string|null       $workingDirectory Working directory inside the sandbox.
     * @param array|null        $env              Additional environment variables for the sandboxed process.
     * @param int|null          $timeout          Seconds before timing out. Null = no timeout.
     * @return \Symfony\Component\Process\Process
     * @throws InvalidArgumentException If timeout is invalid.
     */
    public function process(array $command, array $extraBinds = array(), $workingDirectory = null, array $env = null, $timeout = 60)
    {
        if ($workingDirectory !== null) {
            $this->assertValidPath($workingDirectory, 'working directory');
        }

        if ($timeout !== null && (!is_numeric($timeout) || $timeout < 0)) {
            throw new InvalidArgumentException('Timeout must be null or a non-negative number.');
        }

        $cmd = $this->buildCommand($command, $extraBinds);
        $process = new Process(
            $this->normalizeProcessCommand($cmd),
            $workingDirectory,
            $env,
            null,
            $timeout
        );

        return $process;
    }

    /**
     * Run a sandboxed command and throw if it fails.
     *
     * @param array<int,string> $command          Binary plus arguments to run inside the sandbox.
     * @param array<int,mixed>  $extraBinds       Additional bind mounts.
     * @param string|null       $workingDirectory Working directory inside the sandbox.
     * @param array|null        $env              Additional environment variables for the sandboxed process.
     * @param int|null          $timeout          Seconds before timing out. Null = no timeout.
     * @return \Symfony\Component\Process\Process
     * @throws InvalidArgumentException If timeout is invalid.
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
     * Normalize command for Symfony Process compatibility.
     *
     * Symfony Process 3.4 expects a string, 4+ accepts arrays.
     * This method ensures compatibility across versions.
     *
     * @param array<int,string> $commandParts Command parts as array.
     * @return array<int,string>|string Command as array (4+) or escaped string (3.4).
     */
    protected function normalizeProcessCommand(array $commandParts)
    {
        if (static::processAcceptsArrayCommands()) {
            return $commandParts;
        }

        $escaped = array();
        foreach ($commandParts as $piece) {
            $escaped[] = escapeshellarg($piece);
        }

        return implode(' ', $escaped);
    }

    /**
     * Detect whether the installed Symfony Process version accepts array commands.
     *
     * Symfony Process 3.4 requires string commands, while 4.0+ accepts arrays.
     * This method detects the version capability at runtime.
     *
     * @return bool True if arrays are supported, false if string is required.
     */
    protected static function processAcceptsArrayCommands()
    {
        static $supportsArray = null;

        if ($supportsArray !== null) {
            return $supportsArray;
        }

        $supportsArray = true;

        if (interface_exists('Throwable')) {
            try {
                new Process(array('true'));
            } catch (\Throwable $e) { // @phpstan-ignore-line PHP < 7 compat
                $supportsArray = false;
            }

            return $supportsArray;
        }

        try {
            new Process(array('true'));
        } catch (\Exception $e) {
            $supportsArray = false;
        }

        return $supportsArray;
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
        // /tmp inside the sandbox is already provided by a tmpfs mount in defaultBaseArgs.
        return array();
    }

    /**
     * Ensure a command was provided and validate its structure.
     *
     * @param array<int, string> $command
     * @return void
     * @throws InvalidArgumentException If the command is empty or invalid.
     */
    protected function assertCommandIsNotEmpty(array $command)
    {
        if (empty($command)) {
            throw new InvalidArgumentException('You must provide a command to run inside the sandbox.');
        }

        // Validate that all command parts are strings
        foreach ($command as $index => $part) {
            if (!is_string($part)) {
                throw new InvalidArgumentException(
                    sprintf('Command part at index %d must be a string, got %s.', $index, gettype($part))
                );
            }

            // Prevent null bytes in command parts
            if (strpos($part, "\0") !== false) {
                throw new InvalidArgumentException(
                    sprintf('Command part at index %d contains null bytes.', $index)
                );
            }
        }
    }

    /**
     * Ensure bubblewrap is available to execute.
     *
     * @throws \SecureRun\Exceptions\BubblewrapUnavailableException If bubblewrap is not available or not executable.
     * @return void
     */
    protected function assertBubblewrapIsExecutable()
    {
        if (!is_string($this->binary) || $this->binary === '') {
            throw new BubblewrapUnavailableException('Bubblewrap binary path must be a non-empty string.');
        }

        if ($this->binaryValidator) {
            $result = call_user_func($this->binaryValidator, $this->binary);
            if ($result === false) {
                throw new BubblewrapUnavailableException('Bubblewrap binary failed custom validation: ' . $this->binary);
            }
            return;
        }

        // If a path is provided, validate it directly and do not fall back to PATH.
        if (strpos($this->binary, '/') !== false) {
            if (!file_exists($this->binary)) {
                throw new BubblewrapUnavailableException('Bubblewrap binary not found: ' . $this->binary);
            }

            if (!is_executable($this->binary)) {
                throw new BubblewrapUnavailableException('Bubblewrap binary is not executable: ' . $this->binary);
            }

            return;
        }

        // Otherwise, search for the binary name in PATH.
        if (!static::binaryExistsInPath($this->binary)) {
            throw new BubblewrapUnavailableException('Bubblewrap binary not found in PATH: ' . $this->binary);
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
                $this->assertValidPath($bind, 'bind path');
                $normalized[] = array(
                    'from' => $bind,
                    'to' => $bind,
                    'read_only' => true,
                );
                continue;
            }

            if (is_array($bind) && isset($bind['from']) && isset($bind['to'])) {
                $this->assertValidPath($bind['from'], 'bind source path');
                $this->assertValidPath($bind['to'], 'bind target path');
                $normalized[] = array(
                    'from' => $bind['from'],
                    'to' => $bind['to'],
                    'read_only' => isset($bind['read_only']) ? (bool) $bind['read_only'] : true,
                );
                continue;
            }

            // Invalid bind entry - fail fast instead of silently ignoring.
            throw new InvalidArgumentException('Invalid bind mount format: ' . print_r($bind, true));
        }

        return $normalized;
    }

    /**
     * Validate that a path is absolute and safe for use in bind mounts.
     *
     * @param string $path The path to validate.
     * @param string $context Context description for error messages (e.g., 'bind source path').
     * @return void
     * @throws InvalidArgumentException If the path is invalid or potentially unsafe.
     */
    protected function assertValidPath($path, $context = 'path')
    {
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException("Invalid {$context}: path must be a non-empty string.");
        }

        // Paths must be absolute (start with /) to prevent path traversal attacks
        if ($path[0] !== '/') {
            throw new InvalidArgumentException("Invalid {$context}: path must be absolute (start with /), got: {$path}");
        }

        // Prevent path traversal attempts (e.g., /../../etc/passwd)
        // Realpath will resolve symlinks and .. components, but we check for obvious attempts
        if (strpos($path, '..') !== false) {
            throw new InvalidArgumentException("Invalid {$context}: path contains '..' which is not allowed, got: {$path}");
        }

        // Prevent null bytes (potential security issue)
        if (strpos($path, "\0") !== false) {
            throw new InvalidArgumentException("Invalid {$context}: path contains null bytes, got: {$path}");
        }
    }

    /**
     * Check if a binary exists in the system PATH.
     *
     * @param string $binary Binary name to search for.
     * @return bool True if the binary is found and executable in PATH.
     */
    protected static function binaryExistsInPath($binary)
    {
        $pathEnv = getenv('PATH');
        $paths = $pathEnv === false ? array() : explode(PATH_SEPARATOR, $pathEnv);
        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
