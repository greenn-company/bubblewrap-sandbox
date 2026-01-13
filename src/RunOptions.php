<?php

namespace SecureRun;

/**
 * Constants and utilities for run() method options.
 *
 * This class centralizes all valid option keys and their default values,
 * making it easier to extend and maintain the options system.
 *
 * Compatible with PHP 5.6+ (no scalar type hints).
 */
class RunOptions
{
    /**
     * Option key for enabling environment variable access.
     *
     * When set to true, the run() method will return a ProcessWrapper
     * that allows accessing environment variables via getEnv().
     *
     * Default: false (env is never exposed)
     */
    const UNSECURE_ENV_ACCESS = 'unsecure_env_access';

    /**
     * Get all valid option keys.
     *
     * @return array<string> Array of valid option key names.
     */
    public static function getValidKeys()
    {
        return array(
            self::UNSECURE_ENV_ACCESS,
        );
    }

    /**
     * Get default values for all options.
     *
     * @return array<string,mixed> Associative array of option keys and their default values.
     */
    public static function getDefaults()
    {
        return array(
            self::UNSECURE_ENV_ACCESS => false,
        );
    }

    /**
     * Check if an option key is valid.
     *
     * @param string $key Option key to check.
     * @return bool True if the key is valid, false otherwise.
     */
    public static function isValidKey($key)
    {
        return in_array($key, self::getValidKeys(), true);
    }

    /**
     * Validate and normalize an options array.
     *
     * This method validates that all keys are valid and that values match
     * expected types. Invalid options will throw exceptions.
     *
     * @param array<string,mixed> $options Options array to validate.
     * @return array<string,mixed> Normalized options array with defaults merged.
     * @throws \InvalidArgumentException If options contain invalid keys or values.
     */
    public static function validateAndNormalize(array $options)
    {
        // Check for invalid keys
        foreach ($options as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    'Option keys must be strings. Got ' . gettype($key) . ' at key: ' . var_export($key, true)
                );
            }

            if (!self::isValidKey($key)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Unknown option key: %s. Valid options are: %s',
                        $key,
                        implode(', ', self::getValidKeys())
                    )
                );
            }
        }

        // Validate specific option values
        if (isset($options[self::UNSECURE_ENV_ACCESS])) {
            $value = $options[self::UNSECURE_ENV_ACCESS];
            if (!is_bool($value)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Option %s must be a boolean. Got %s (value: %s)',
                        self::UNSECURE_ENV_ACCESS,
                        gettype($value),
                        var_export($value, true)
                    )
                );
            }
        }

        // Merge with defaults (user options override defaults)
        return array_merge(self::getDefaults(), $options);
    }

    /**
     * Get a specific option value from an array, using default if not set.
     *
     * @param array<string,mixed> $options Options array.
     * @param string              $key     Option key to retrieve.
     * @return mixed Option value, or default if not set.
     */
    public static function get(array $options, $key)
    {
        if (!self::isValidKey($key)) {
            throw new \InvalidArgumentException('Invalid option key: ' . $key);
        }

        $defaults = self::getDefaults();
        return isset($options[$key]) ? $options[$key] : $defaults[$key];
    }
}


