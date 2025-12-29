<?php

namespace SecureRun\Sandbox;

use SecureRun\BubblewrapSandbox as BaseFacade;

/**
 * Backwards-compatible facade kept for older imports that referenced
 * SecureRun\Sandbox\BubblewrapSandbox.
 *
 * New code should import SecureRun\BubblewrapSandbox (or use the Laravel
 * alias BubblewrapSandbox). This class exists only as a thin shim to avoid
 * breaking existing applications.
 *
 * @deprecated Use \SecureRun\BubblewrapSandbox instead.
 */
class BubblewrapSandbox extends BaseFacade
{
}
