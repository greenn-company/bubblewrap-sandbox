<?php

$readOnlyBinds = array(
    '/usr',
    '/bin',
    '/lib',
    '/sbin',
    '/etc/resolv.conf',
    '/etc/ssl',
);

// Algumas distros possuem /lib64 (ex.: Debian/Ubuntu); Alpine não cria essa pasta.
if (is_dir('/lib64')) {
    $readOnlyBinds[] = '/lib64';
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
    */
    'base_args' => array(
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
    ),

    /*
    |--------------------------------------------------------------------------
    | Read-only bind mounts
    |--------------------------------------------------------------------------
    |
    | Host paths that will be mounted as read-only inside the sandbox.
    */
    'read_only_binds' => $readOnlyBinds,

    /*
    |--------------------------------------------------------------------------
    | Writable bind mounts
    |--------------------------------------------------------------------------
    |
    | Host paths exposed with write access inside the sandbox.
    */
    'write_binds' => array(
        // /tmp inside the sandbox is already a tmpfs via base_args. Add host paths only when needed.
    ),
);
