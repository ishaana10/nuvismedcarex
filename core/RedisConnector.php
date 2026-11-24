<?php
declare(strict_types=1);

/**
 * NuRedisConnector - finds and opens the Redis connection, wherever the app is hosted.
 *
 * Nothing here is tied to one hosting account or domain. The socket is looked for in this order:
 *   1. the path in config (REDIS_PATH) - ignored when it does not exist, so a path copied
 *      from an old account can never switch Redis off
 *   2. <home>/.redis/redis.sock, where <home> is worked out from the running user and the
 *      install folder (A2 Hosting layout)
 * and, when no socket is up yet, the host daemon is asked to start (touching ~/.redis.on) and
 * given a few seconds. That start-up attempt is rate limited so a Redis that is genuinely off
 * never slows the site down: it costs one attempt a minute, not one per request.
 *
 * config 'enabled' may be true, false, or 'auto' (the default):
 *   true  - use Redis, and try to start it
 *   false - never use Redis
 *   auto  - use it when the extension is loaded and a Redis is found or can be started
 */
final class NuRedisConnector
{
    /** Why the last connection attempt ended the way it did (shown in the Developer panel). */
    public static string $lastReason = 'Not attempted yet.';
    /** How the last connection was made: 'unix', 'tcp' or '' when not connected. */
    public static string $lastVia = '';
    /** The socket path actually used (or the best candidate when none exists). */
    public static string $lastSocket = '';

    private const RETRY_SECONDS = 60;
    private const START_WAIT_SECONDS = 3.0;

    /** @return 'on'|'off'|'auto' */
    public static function mode(array $cfg): string
    {
        $v = $cfg['enabled'] ?? 'auto';
        if (is_string($v)) {
            $v = strtolower(trim($v));
            if ($v === 'auto' || $v === '') {
                return 'auto';
            }
            return in_array($v, ['1', 'true', 'on', 'yes'], true) ? 'on' : 'off';
        }
        return $v ? 'on' : 'off';
    }

    /** The account's home folder, found without assuming any particular account name. */
    public static function homeDir(): ?string
    {
        $seen = [];
        $guesses = [getenv('HOME') ?: null];
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            $guesses[] = is_array($pw) ? ($pw['dir'] ?? null) : null;
        }
        // Shared hosting keeps sites under /home/<account>/... (or /home2/<account>/...)
        if (preg_match('#^(/home\d*/[^/]+)(?:/|$)#', str_replace('\\', '/', __DIR__), $m)) {
            $guesses[] = $m[1];
        }
        foreach ($guesses as $g) {
            if (is_string($g) && $g !== '' && !isset($seen[$g]) && is_dir($g)) {
                return rtrim($g, '/');
            }
            $seen[(string) $g] = true;
        }
        return null;
    }

    /** @return string[] places a Redis socket may be, best first, without duplicates */
    public static function socketCandidates(array $cfg): array
    {
        $out = [];
        $env = getenv('REDIS_PATH');
        foreach ([$cfg['path'] ?? null, $env !== false ? $env : null] as $p) {
            if (is_string($p) && $p !== '') {
                $out[] = $p;
            }
        }
        $home = self::homeDir();
        if ($home !== null) {
            $out[] = $home . '/.redis/redis.sock';
        }
        return array_values(array_unique($out));
    }

    public static function findSocket(array $cfg): ?string
    {
        foreach (self::socketCandidates($cfg) as $p) {
            if (@file_exists($p)) {
                return $p;
            }
        }
        return null;
    }

    /** The file whose presence asks the host to run a Redis daemon for this account. */
    public static function flagFile(): ?string
    {
        $home = self::homeDir();
        return $home !== null ? $home . '/.redis.on' : null;
    }

    /**
     * Ask the host to start the daemon.
     * @return 'created'|'existing'|'failed' - 'existing' means it was asked for before, so a
     *         daemon that is still not running is unlikely to appear within a request.
     */
    public static function requestDaemon(): string
    {
        $flag = self::flagFile();
        if ($flag === null) {
            return 'failed';
        }
        if (@file_exists($flag)) {
            return 'existing';
        }
        return @touch($flag) ? 'created' : 'failed';
    }

    /** Wait (briefly) for the socket to appear after asking for the daemon. */
    public static function waitForSocket(array $cfg, float $seconds = self::START_WAIT_SECONDS): ?string
    {
        $deadline = microtime(true) + $seconds;
        do {
            $p = self::findSocket($cfg);
            if ($p !== null) {
                return $p;
            }
            usleep(150000);
        } while (microtime(true) < $deadline);
        return null;
    }

    private static function marker(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . '/nuvis_redis_' . md5(__DIR__) . '.retry';
    }

    /** True when a start-up attempt may be made now (at most one a minute, server-wide). */
    private static function mayAttemptStart(): bool
    {
        $m = self::marker();
        $t = @filemtime($m);
        return $t === false || (time() - $t) >= self::RETRY_SECONDS;
    }

    private static function noteAttempt(bool $succeeded): void
    {
        $m = self::marker();
        if ($succeeded) {
            @unlink($m);
        } else {
            @touch($m);
        }
    }

    /**
     * Open a connection.
     * @return \Redis|null the ready connection, or null (see $lastReason for why)
     */
    public static function connect(array $cfg): ?\Redis
    {
        self::$lastVia = '';
        self::$lastSocket = self::socketCandidates($cfg)[0] ?? '';
        $mode = self::mode($cfg);

        if ($mode === 'off') {
            self::$lastReason = 'Redis is switched off in the configuration (redis.enabled = false).';
            return null;
        }
        if (!extension_loaded('redis')) {
            self::$lastReason = 'The PHP "redis" extension is not installed or not enabled for this site.';
            return null;
        }

        $timeout = (float) ($cfg['timeout'] ?? 2.5);
        $wantTcp = ($cfg['scheme'] ?? 'unix') === 'tcp';
        $problems = [];

        $attempts = $wantTcp ? ['tcp', 'unix'] : ['unix', 'tcp'];
        $startTried = false;
        foreach ($attempts as $how) {
            if ($how === 'unix') {
                $sock = self::findSocket($cfg);
                if ($sock === null && $mode !== 'off' && self::mayAttemptStart()) {
                    $startTried = true;
                    self::noteAttempt(false); // claim the attempt now so simultaneous requests do not all wait
                    $asked = self::requestDaemon();
                    if ($asked !== 'failed') {
                        $sock = self::waitForSocket($cfg, $asked === 'created' ? self::START_WAIT_SECONDS : 0.4);
                    }
                    if ($sock === null) {
                        $problems[] = 'no Redis socket appeared at ' . (self::$lastSocket ?: 'the usual place')
                            . ' after asking the host to start it';
                    }
                } elseif ($sock === null) {
                    $problems[] = 'no Redis socket found at ' . (self::$lastSocket ?: 'the usual place');
                }
                if ($sock !== null) {
                    $r = self::open('unix', $sock, 0, $timeout, $cfg, $why);
                    if ($r) {
                        self::$lastVia = 'unix';
                        self::$lastSocket = $sock;
                        self::$lastReason = 'Connected over the socket ' . $sock . '.';
                        self::noteAttempt(true);
                        return $r;
                    }
                    $problems[] = $why;
                }
            } else {
                // A TCP Redis is only tried when asked for, or when the socket route failed and the
                // retry window allows (a refused local port fails instantly, so this is cheap).
                if (!$wantTcp && !$startTried && !self::mayAttemptStart()) {
                    continue;
                }
                $host = (string) ($cfg['host'] ?? '127.0.0.1');
                $port = (int) ($cfg['port'] ?? 6379);
                $r = self::open('tcp', $host, $port, $wantTcp ? $timeout : min($timeout, 0.25), $cfg, $why);
                if ($r) {
                    self::$lastVia = 'tcp';
                    self::$lastReason = "Connected to $host:$port.";
                    self::noteAttempt(true);
                    return $r;
                }
                $problems[] = $why;
            }
        }

        self::noteAttempt(false);
        self::$lastReason = 'Redis is not reachable: ' . implode('; ', array_filter($problems)) . '.';
        return null;
    }

    private static function open(string $how, string $target, int $port, float $timeout, array $cfg, ?string &$why): ?\Redis
    {
        $why = null;
        try {
            $r = new \Redis();
            $ok = $how === 'unix' ? @$r->connect($target, 0, $timeout) : @$r->connect($target, $port, $timeout);
            if (!$ok) {
                $why = ($how === 'unix' ? "could not connect to $target" : "could not connect to $target:$port");
                return null;
            }
            if (!empty($cfg['password'])) {
                $r->auth($cfg['password']);
            }
            $r->select((int) ($cfg['database'] ?? 0));
            // Reading/writing must not hang a page if Redis stalls
            $r->setOption(\Redis::OPT_READ_TIMEOUT, max(0.5, $timeout));
            $r->ping();
            return $r;
        } catch (\Throwable $e) {
            $why = ($how === 'unix' ? "socket $target" : "$target:$port") . ' refused: ' . $e->getMessage();
            return null;
        }
    }

    /** Everything the Developer panel shows about where Redis would live. */
    public static function describe(array $cfg): array
    {
        return [
            'mode'            => self::mode($cfg),
            'extension'       => extension_loaded('redis'),
            'home'            => self::homeDir(),
            'flag_file'       => self::flagFile(),
            'flag_exists'     => ($f = self::flagFile()) !== null && @file_exists($f),
            'socket_found'    => self::findSocket($cfg),
            'socket_checked'  => self::socketCandidates($cfg),
            'last_reason'     => self::$lastReason,
            'via'             => self::$lastVia,
        ];
    }

    /** Text edit of a config file's redis 'enabled' value; everything else is left untouched. */
    public static function setModeInConfigText(string $php, string|bool $mode): string
    {
        $literal = $mode === false ? 'false' : "'auto'";
        // 'redis' => [ ... 'enabled' => <value> ...   (also the var_export form:  'redis' => array ( ... )
        $withEnabled = '/(\'redis\'\s*=>\s*(?:\[|array\s*\()(?:(?!\]\s*,|\)\s*,).)*?\'enabled\'\s*=>\s*)[^,\n\]\)]+/s';
        if (preg_match($withEnabled, $php)) {
            return preg_replace($withEnabled, '${1}' . $literal, $php, 1);
        }
        $open = '/(\'redis\'\s*=>\s*(?:\[|array\s*\())/';
        if (preg_match($open, $php)) {
            return preg_replace($open, '${1}' . "\n        'enabled' => {$literal},", $php, 1);
        }
        $end = strrpos($php, '];');
        if ($end === false) {
            throw new \RuntimeException('The configuration file is not in the expected format.');
        }
        return substr($php, 0, $end) . "    'redis' => ['enabled' => {$literal}],\n" . substr($php, $end);
    }
}
