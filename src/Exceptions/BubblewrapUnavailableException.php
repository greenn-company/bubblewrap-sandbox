<?php

namespace SecureRun\Exceptions;

use RuntimeException;

/**
 * Raised when bubblewrap (bwrap) is missing or not executable.
 */
class BubblewrapUnavailableException extends RuntimeException
{
}
