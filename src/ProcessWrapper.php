<?php

namespace SecureRun;

use BadMethodCallException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Secure wrapper around Symfony Process that can optionally store environment variables.
 *
 * This wrapper provides access to environment variables only when explicitly enabled,
 * preventing accidental or forced exposure of sensitive data.
 *
 * Compatible with PHP 7.0+ (no scalar type hints).
 */
class ProcessWrapper
{
    /**
     * The wrapped Process instance.
     *
     * @var \Symfony\Component\Process\Process
     */
    private $process;

    /**
     * Environment variables passed to the process (if storage is enabled).
     *
     * @var array<string,string>|null
     */
    private $env;

    /**
     * Whether environment variable access is enabled for this instance.
     *
     * @var bool
     */
    private $envAccessEnabled;

    /**
     * Constructor.
     *
     * @param \Symfony\Component\Process\Process $process           The Process instance to wrap.
     * @param array<string,string>|null          $env               Environment variables (only stored if $envAccessEnabled is true).
     * @param bool                                $envAccessEnabled  Whether to enable environment variable access.
     */
    public function __construct(Process $process, $env = null, $envAccessEnabled = false)
    {
        $this->process = $process;
        $this->envAccessEnabled = (bool) $envAccessEnabled;
        $this->env = null;

        // Only store env if access is explicitly enabled
        if ($this->envAccessEnabled) {
            $this->env = $env !== null ? $env : array();
        }
    }

    /**
     * Get environment variables passed to the process.
     *
     * This method will only return the environment variables if:
     * 1. Environment access was enabled when creating this wrapper
     * 2. The method is called explicitly
     *
     * @return array<string,string> Environment variables array.
     * @throws \RuntimeException If environment access is not enabled for this instance.
     */
    public function getEnv()
    {
        if (!$this->envAccessEnabled) {
            throw new RuntimeException(
                'Environment variable access is not enabled for this ProcessWrapper instance. ' .
                'To enable it, pass unsecure_env_access => true in the options parameter when calling run().'
            );
        }

        return $this->env;
    }

    /**
     * Check if environment variable access is enabled for this instance.
     *
     * @return bool
     */
    public function isEnvAccessEnabled()
    {
        return $this->envAccessEnabled;
    }

    /**
     * Magic method to delegate calls to the wrapped Process instance.
     *
     * This allows the wrapper to be used as a drop-in replacement for Process
     * in most cases. Environment-related methods are blocked for security.
     *
     * @param string $method Method name.
     * @param array  $args   Method arguments.
     * @return mixed
     * @throws RuntimeException If trying to access env-related methods when access is disabled.
     * @throws BadMethodCallException If method does not exist on Process.
     */
    public function __call($method, $args)
    {
        // Prevent direct manipulation of environment variables
        if (strtolower($method) === 'setenv') {
            throw new RuntimeException(
                'Cannot modify environment variables through ProcessWrapper. ' .
                'Environment must be set at construction time.'
            );
        }

        if (!method_exists($this->process, $method)) {
            throw new BadMethodCallException(
                sprintf('Method %s does not exist on Symfony\Component\Process\Process', $method)
            );
        }

        return call_user_func_array(array($this->process, $method), $args);
    }

    /**
     * Magic method to delegate property access to the wrapped Process instance.
     *
     * Symfony Process has mostly private properties, so we attempt to use
     * getter methods (e.g., $wrapper->timeout calls $process->getTimeout()).
     *
     * @param string $name Property name.
     * @return mixed
     * @throws RuntimeException If property is not accessible on Process.
     */
    public function __get($name)
    {
        // Prevent access to internal properties
        if (in_array($name, array('process', 'env', 'envAccessEnabled'), true)) {
            throw new RuntimeException('Cannot access internal property: ' . $name);
        }

        // Symfony Process has mostly private properties, so direct access won't work.
        // Use getter methods if available, otherwise throw.
        $getter = 'get' . ucfirst($name);
        if (method_exists($this->process, $getter)) {
            return $this->process->$getter();
        }

        // Try 'is' prefix for boolean properties (e.g., isSuccessful, isTty)
        $isGetter = 'is' . ucfirst($name);
        if (method_exists($this->process, $isGetter)) {
            return $this->process->$isGetter();
        }

        throw new RuntimeException(
            sprintf('Property %s is not accessible on Symfony\Component\Process\Process', $name)
        );
    }

    /**
     * Magic method to delegate property setting to the wrapped Process instance.
     *
     * Symfony Process has mostly private properties, so we attempt to use
     * setter methods (e.g., $wrapper->timeout = 60 calls $process->setTimeout(60)).
     *
     * @param string $name  Property name.
     * @param mixed  $value Property value.
     * @return void
     * @throws RuntimeException If property is not settable on Process.
     */
    public function __set($name, $value)
    {
        // Prevent modification of internal properties
        if (in_array($name, array('process', 'env', 'envAccessEnabled'), true)) {
            throw new RuntimeException('Cannot modify internal property: ' . $name);
        }

        // Symfony Process has mostly private properties, so direct assignment won't work.
        // Use setter methods if available, otherwise throw.
        $setter = 'set' . ucfirst($name);
        if (method_exists($this->process, $setter)) {
            $this->process->$setter($value);
            return;
        }

        throw new RuntimeException(
            sprintf('Property %s is not settable on Symfony\Component\Process\Process', $name)
        );
    }
}

