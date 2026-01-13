<?php

namespace SecureRun;

use Symfony\Component\Process\Process;

/**
 * Secure wrapper around Symfony Process that can optionally store environment variables.
 *
 * This wrapper provides access to environment variables only when explicitly enabled,
 * preventing accidental or forced exposure of sensitive data.
 *
 * Compatible with PHP 5.6+ (no scalar type hints).
 */
class ProcessWrapper
{
    /**
     * The wrapped Process instance.
     *
     * @var \Symfony\Component\Process\Process
     */
    protected $process;

    /**
     * Environment variables passed to the process (if storage is enabled).
     *
     * @var array<string,string>|null
     */
    protected $env;

    /**
     * Whether environment variable access is enabled for this instance.
     *
     * @var bool
     */
    protected $envAccessEnabled;

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

        // Only store env if access is explicitly enabled
        if ($this->envAccessEnabled) {
            $this->env = $env !== null ? $env : array();
        } else {
            $this->env = null;
        }
    }

    /**
     * Get the wrapped Process instance.
     *
     * @return \Symfony\Component\Process\Process
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * Get environment variables passed to the process.
     *
     * This method will only return the environment variables if:
     * 1. Environment access was enabled when creating this wrapper
     * 2. The method is called explicitly
     *
     * @return array<string,string> Environment variables array, or empty array if access is disabled.
     * @throws \RuntimeException If environment access is not enabled for this instance.
     */
    public function getEnv()
    {
        if (!$this->envAccessEnabled) {
            throw new \RuntimeException(
                'Environment variable access is not enabled for this ProcessWrapper instance. ' .
                'To enable it, pass unsecure_env_access => true in the options parameter when calling run().'
            );
        }

        return $this->env !== null ? $this->env : array();
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
     * in most cases.
     *
     * @param string $method Method name.
     * @param array  $args   Method arguments.
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (!method_exists($this->process, $method)) {
            throw new \BadMethodCallException(
                sprintf('Method %s does not exist on Symfony\Component\Process\Process', $method)
            );
        }

        return call_user_func_array(array($this->process, $method), $args);
    }

    /**
     * Magic method to delegate property access to the wrapped Process instance.
     *
     * @param string $name Property name.
     * @return mixed
     */
    public function __get($name)
    {
        // Prevent access to internal properties
        if (in_array($name, array('process', 'env', 'envAccessEnabled'), true)) {
            $trace = debug_backtrace();
            $caller = isset($trace[1]) ? $trace[1] : array();
            if (!isset($caller['class']) || $caller['class'] !== __CLASS__) {
                throw new \RuntimeException('Cannot access protected property: ' . $name);
            }
        }

        return $this->process->$name;
    }

    /**
     * Magic method to delegate property setting to the wrapped Process instance.
     *
     * @param string $name  Property name.
     * @param mixed  $value Property value.
     * @return void
     */
    public function __set($name, $value)
    {
        // Prevent modification of internal properties
        if (in_array($name, array('process', 'env', 'envAccessEnabled'), true)) {
            throw new \RuntimeException('Cannot modify protected property: ' . $name);
        }

        $this->process->$name = $value;
    }
}


