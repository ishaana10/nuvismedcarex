<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/** Dropbox, through the v2 API. */
final class DropboxProvider extends CloudProvider
{
    public function id(): string { return 'dropbox'; }
    public function label(): string { return 'Dropbox'; }
    protected function tokenUrl(): string { return $this->ep('token', 'https://api.dropboxapi.com/oauth2/token'); }
    protected function refreshParams(): array { return ['client_id' => $this->cfg['app_key'], 'client_secret' => $this->cfg['app_secret'], 'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token']; }

    public function authUrl(string $redirectUri, string $state): string
    {
        return $this->ep('auth', 'https://www.dropbox.com/oauth2/authorize') . '?' . http_build_query([
            'client_id' => $this->cfg['app_key'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'token_access_type' => 'offline', 'state' => $state]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = CloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $code, 'client_id' => $this->cfg['app_key'], 'client_secret' => $this->cfg['app_secret'], 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code']);
        $d = CloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['refresh_token'])) throw new CloudException('Dropbox sign-in failed (' . CloudHttp::reason($r) . ').');
        $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        $acct = ''; try { $acct = $this->whoami(); } catch (\Throwable $e) {}
        return ['refresh_token' => (string)$d['refresh_token'], 'account' => $acct];
    }

    private function rpc(): string { return $this->ep('api', 'https://api.dropboxapi.com'); }
    private function content(): string { return $this->ep('content', 'https://content.dropboxapi.com'); }

    public function whoami(): string
    {
        $r = CloudHttp::request('POST', $this->rpc() . '/2/users/get_current_account', array_merge($this->bearer(), ['Content-Type:']), '', ['timeout' => 30]);
        if ($r['status'] !== 200) $this->fail($r, 'account check');
        $d = CloudHttp::json($r);
        return (string)($d['email'] ?? $d['name']['display_name'] ?? 'Dropbox account');
    }

    private static function argJson(array $a): string { return json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $path = '/' . implode('/', array_merge($segments, [$name]));
        // header values must be ASCII: escape non-ASCII per Dropbox's documentation
        $arg = preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', fn($m) => sprintf('\\u%04x', mb_ord($m[0])), self::argJson(['path' => $path, 'mode' => 'add', 'autorename' => true, 'mute' => true]));
        $r = CloudHttp::request('POST', $this->content() . '/2/files/upload', array_merge($this->bearer(), ['Dropbox-API-Arg: ' . $arg, 'Content-Type: application/octet-stream']), null, ['infile' => $localPath, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'upload');
        $d = CloudHttp::json($r);
        if (empty($d['id'])) throw new CloudException('Dropbox: upload finished but no file id was returned.');
        return ['id' => (string)$d['id'], 'url' => ''];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = CloudHttp::request('POST', $this->content() . '/2/files/download', array_merge($this->bearer(), ['Dropbox-API-Arg: ' . self::argJson(['path' => $remoteId]), 'Content-Type:']), '', ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = CloudHttp::request('POST', $this->rpc() . '/2/files/delete_v2', array_merge($this->bearer(), ['Content-Type: application/json']), json_encode(['path' => $remoteId]), ['timeout' => 30]);
        if ($r['status'] !== 200 && $r['status'] !== 409) $this->fail($r, 'delete');   // 409 = already gone
    }
}

/** WebDAV: Nextcloud, ownCloud, Synology, Box, any DAV server. Basic auth (use an app password). */
