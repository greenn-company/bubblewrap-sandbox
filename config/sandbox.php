<?php

/**
 * Build read-only bind mounts dynamically based on actual system paths.
 *
 * Modern Ubuntu/Debian systems use merged /usr where /lib, /bin, /sbin are
 * symlinks to their /usr counterparts. We need to bind the real paths,
 * not the symlinks, to avoid bwrap errors.
 */
$readOnlyBinds = array();

// Always bind /usr (contains most binaries and libraries)
$readOnlyBinds[] = '/usr';

// For /bin, /lib, /sbin: only bind if they are real directories, not symlinks
// In merged /usr systems, these are symlinks to /usr/bin, /usr/lib, /usr/sbin
foreach (array('/bin', '/lib', '/sbin') as $path) {
    if (is_dir($path) && !is_link($path)) {
        $readOnlyBinds[] = $path;
    }
}

// /lib64 exists on some 64-bit systems (Debian/Ubuntu x86_64)
// Only add if it's a real directory
if (is_dir('/lib64') && !is_link('/lib64')) {
    $readOnlyBinds[] = '/lib64';
}

// /etc paths needed for network and SSL
$readOnlyBinds[] = '/etc/resolv.conf';
$readOnlyBinds[] = '/etc/ssl';

// /etc/alternatives is needed for ffmpeg and other alternatives-managed binaries
if (is_dir('/etc/alternatives')) {
    $readOnlyBinds[] = '/etc/alternatives';
}

/**
 * Build base arguments dynamically.
 *
 * On merged /usr systems, we need to create symlinks inside the sandbox
 * so that paths like /bin/bash and /lib/x86_64-linux-gnu work correctly.
 */
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
    '--tmpfs',
    '/run',
    '--setenv',
    'PATH',
    '/usr/bin:/bin:/usr/sbin:/sbin',
    '--chdir',
    '/tmp',
);

// On merged /usr systems, create symlinks so /bin, /lib, /sbin paths work
// These symlinks must be created AFTER /usr is mounted (handled by bwrap order)
if (is_link('/bin')) {
    $baseArgs[] = '--symlink';
    $baseArgs[] = 'usr/bin';
    $baseArgs[] = '/bin';
}

if (is_link('/lib')) {
    $baseArgs[] = '--symlink';
    $baseArgs[] = 'usr/lib';
    $baseArgs[] = '/lib';
}

if (is_link('/sbin')) {
    $baseArgs[] = '--symlink';
    $baseArgs[] = 'usr/sbin';
    $baseArgs[] = '/sbin';
}

// /lib64 symlink if it exists as a symlink on the host
if (is_link('/lib64')) {
    $target = readlink('/lib64');
    if ($target !== false) {
        $baseArgs[] = '--symlink';
        $baseArgs[] = $target;
        $baseArgs[] = '/lib64';
    }
}

return array(
    /*
    |--------------------------------------------------------------------------
    | bwrap binary path
    |--------------------------------------------------------------------------
    |
    | Binary used to spawn the sandbox. Keep "/usr/bin/bwrap" when installed
    | from distro packages; change to "bwrap" if you prefer PATH lookup.
    */
    'binary' => '/usr/bin/bwrap',

    /*
    |--------------------------------------------------------------------------
    | Base arguments
    |--------------------------------------------------------------------------
    |
    | Adjust bubblewrap default parameters here. Avoid removing
    | --unshare-all, --die-with-parent, and the /proc and /dev mounts.
    | On merged /usr systems, symlinks are added dynamically above.
    */
    'base_args' => $baseArgs,

    /*
    |--------------------------------------------------------------------------
    | Read-only bind mounts
    |--------------------------------------------------------------------------
    |
    | Host paths that will be mounted as read-only inside the sandbox.
    | Built dynamically above to handle merged /usr systems correctly.
    */
    'read_only_binds' => $readOnlyBinds,

    /*
    |--------------------------------------------------------------------------
    | Writable bind mounts
    |--------------------------------------------------------------------------
    |
    | Host paths exposed with write access inside the sandbox.
    | Note: /tmp inside sandbox is already a tmpfs via base_args.
    */
    'write_binds' => array(),
);
