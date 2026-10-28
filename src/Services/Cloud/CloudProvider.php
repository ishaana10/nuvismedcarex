<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/**
 * A cloud drive documents can be stored in.
 *
 * Every provider works the same way: a refresh token obtained once through the
 * browser, exchanged for a short-lived access token whenever a file has to
 * move. WebDAV is the exception and uses a username and password.
 */
abstract class CloudProvider
{
    protected array $cfg;
    protected array $global;
    private static array $tokenCache = [];

    public function __construct(array $global)
    {
        $this->global = $global;
        $this->cfg = $global[$this->id()] ?? [];
    }

    abstract public function id(): string;
    abstract public function label(): string;
    /** true = needs the browser OAuth flow; false = credentials entered directly */
    public function usesOAuth(): bool { return true; }

    /** Endpoint, overridable through $nuConfig['cloud_endpoints'] (self-hosted gateways, tests). */
    protected function ep(string $key, string $default): string
    {
        // Overridable for a self-hosted gateway or a test double
        return (string) ($GLOBALS['nxCloudEndpoints'][$this->id() . '.' . $key] ?? $default);
    }

    public function authUrl(string $redirectUri, string $state): string { throw new CloudException('This provider does not use browser sign-in.'); }
    /** @return array{refresh_token:string,account:string} */
    public function exchangeCode(string $code, string $redirectUri): array { throw new CloudException('This provider does not use browser sign-in.'); }

    /** Short-lived access token from the stored refresh token. */
    protected function accessToken(): string
    {
        $key = $this->id() . '|' . substr(hash('sha256', (string)($this->cfg['refresh_token'] ?? '')), 0, 12);
        $c = self::$tokenCache[$key] ?? null;
        if ($c && $c['exp'] > time() + 30) return $c['tok'];
        if (empty($this->cfg['refresh_token'])) throw new CloudException($this->label() . ' is not connected yet.');
        $r = CloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $this->refreshParams(), ['timeout' => 30]);
        $d = CloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['access_token'])) {
            if (($d['error'] ?? '') === 'invalid_grant') throw new CloudException($this->label() . ' access was revoked or expired. Connect the account again.');
            throw new CloudException($this->label() . ' sign-in failed (' . CloudHttp::reason($r) . ').');
        }
        if (!empty($d['refresh_token']) && $d['refresh_token'] !== $this->cfg['refresh_token']) {   // Microsoft rotates refresh tokens
            CloudConfig::setConnection($this->id(), ['refresh_token' => (string)$d['refresh_token']]);
            $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        }
        self::$tokenCache[$key] = ['tok' => (string)$d['access_token'], 'exp' => time() + (int)($d['expires_in'] ?? 3000)];
        return self::$tokenCache[$key]['tok'];
    }
    protected function tokenUrl(): string { return ''; }
    protected function refreshParams(): array { return []; }

    protected function bearer(): array { return ['Authorization: Bearer ' . $this->accessToken()]; }

    protected function fail(array $r, string $what): never
    {
        throw new CloudException("{$this->label()}: {$what} failed (" . CloudHttp::reason($r) . ').');
    }

    /** @param string[] $segments folder path, @return array{id:string,url:string} */
    abstract public function upload(string $localPath, array $segments, string $name, string $mime): array;
    /** Stream the remote file through $sink(chunk). @param string $remoteId id/path returned by upload() */
    abstract public function download(string $remoteId, callable $sink): void;
    abstract public function delete(string $remoteId): void;
    /** Verify the connection; returns a label for the connected account. */
    abstract public function whoami(): string;
}
