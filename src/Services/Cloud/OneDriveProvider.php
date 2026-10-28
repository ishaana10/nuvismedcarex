<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/** Microsoft OneDrive and SharePoint document libraries, through the Graph API. */
final class OneDriveProvider extends CloudProvider
{
    public function id(): string { return 'onedrive'; }
    public function label(): string { return 'OneDrive'; }
    private function tenant(): string { return (string)($this->cfg['tenant'] ?: 'common'); }
    protected function tokenUrl(): string { return $this->ep('token', 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/token'); }
    protected function refreshParams(): array { return ['client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token', 'scope' => 'Files.ReadWrite offline_access User.Read']; }
    private function api(): string { return $this->ep('api', 'https://graph.microsoft.com/v1.0'); }

    public function authUrl(string $redirectUri, string $state): string
    {
        return $this->ep('auth', 'https://login.microsoftonline.com/' . rawurlencode($this->tenant()) . '/oauth2/v2.0/authorize') . '?' . http_build_query([
            'client_id' => $this->cfg['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'response_mode' => 'query',
            'scope' => 'Files.ReadWrite offline_access User.Read', 'state' => $state, 'prompt' => 'select_account']);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = CloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $code, 'client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code', 'scope' => 'Files.ReadWrite offline_access User.Read']);
        $d = CloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['refresh_token'])) throw new CloudException('Microsoft sign-in failed (' . CloudHttp::reason($r) . ').');
        $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        $acct = ''; try { $acct = $this->whoami(); } catch (\Throwable $e) {}
        return ['refresh_token' => (string)$d['refresh_token'], 'account' => $acct];
    }

    public function whoami(): string
    {
        $r = CloudHttp::request('GET', $this->api() . '/me?$select=userPrincipalName,mail,displayName', $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 200) $this->fail($r, 'account check');
        $d = CloudHttp::json($r);
        return (string)($d['userPrincipalName'] ?? $d['mail'] ?? $d['displayName'] ?? 'Microsoft account');
    }

    private function enc(array $segs): string { return implode('/', array_map('rawurlencode', $segs)); }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $size = filesize($localPath);
        $path = $this->enc(array_merge($segments, [$name]));
        if ($size <= 4 * 1024 * 1024) {
            $r = CloudHttp::request('PUT', $this->api() . '/me/drive/root:/' . $path . ':/content?@microsoft.graph.conflictBehavior=rename', array_merge($this->bearer(), ['Content-Type: ' . $mime]), null, ['infile' => $localPath, 'timeout' => 300]);
            if ($r['status'] !== 200 && $r['status'] !== 201) $this->fail($r, 'upload');
            $d = CloudHttp::json($r);
        } else {
            $s = CloudHttp::request('POST', $this->api() . '/me/drive/root:/' . $path . ':/createUploadSession', array_merge($this->bearer(), ['Content-Type: application/json']),
                json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'rename']]), ['timeout' => 30]);
            $url = CloudHttp::json($s)['uploadUrl'] ?? '';
            if ($s['status'] !== 200 || $url === '') $this->fail($s, 'upload start');
            $chunk = 327680 * 10; $off = 0; $d = [];
            while ($off < $size) {
                $len = min($chunk, $size - $off);
                $r = CloudHttp::request('PUT', $url, ['Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size, 'Content-Type: application/octet-stream'], null, ['body_from' => [$localPath, $off, $len], 'timeout' => 300]);
                if (!in_array($r['status'], [200, 201, 202], true)) $this->fail($r, 'upload');
                $off += $len; if ($r['status'] !== 202) $d = CloudHttp::json($r);
            }
        }
        if (empty($d['id'])) throw new CloudException('OneDrive: upload finished but no file id was returned.');
        return ['id' => (string)$d['id'], 'url' => (string)($d['webUrl'] ?? '')];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = CloudHttp::request('GET', $this->api() . '/me/drive/items/' . rawurlencode($remoteId) . '/content', $this->bearer(), null, ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = CloudHttp::request('DELETE', $this->api() . '/me/drive/items/' . rawurlencode($remoteId), $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 204 && $r['status'] !== 404) $this->fail($r, 'delete');
    }
}
