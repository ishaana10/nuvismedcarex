<?php
declare(strict_types=1);

namespace ClinicFlow\Services\Cloud;

/** Google Drive, through the Drive v3 API with the drive.file scope (only files this app created). */
final class GoogleDriveProvider extends CloudProvider
{
    private array $folderIds = [];
    public function id(): string { return 'gdrive'; }
    public function label(): string { return 'Google Drive'; }
    protected function tokenUrl(): string { return $this->ep('token', 'https://oauth2.googleapis.com/token'); }
    protected function refreshParams(): array { return ['client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'refresh_token' => $this->cfg['refresh_token'], 'grant_type' => 'refresh_token']; }

    public function authUrl(string $redirectUri, string $state): string
    {
        // drive.file = the app can only see files it created itself (least privilege)
        return $this->ep('auth', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
            'client_id' => $this->cfg['client_id'], 'redirect_uri' => $redirectUri, 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = CloudHttp::request('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], [
            'code' => $code, 'client_id' => $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'], 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code']);
        $d = CloudHttp::json($r);
        if ($r['status'] !== 200 || empty($d['refresh_token'])) {
            throw new CloudException('Google did not return a refresh token (' . (empty($d['refresh_token']) && $r['status'] === 200 ? 'remove the app under your Google account permissions and connect again' : CloudHttp::reason($r)) . ').');
        }
        $this->cfg['refresh_token'] = (string)$d['refresh_token'];
        $acct = ''; try { $acct = $this->whoami(); } catch (\Throwable $e) {}
        return ['refresh_token' => (string)$d['refresh_token'], 'account' => $acct];
    }

    public function whoami(): string
    {
        $r = CloudHttp::request('GET', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/about?fields=user(emailAddress,displayName)', $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 200) $this->fail($r, 'account check');
        $u = CloudHttp::json($r)['user'] ?? [];
        return (string)($u['emailAddress'] ?? $u['displayName'] ?? 'Google account');
    }

    private function folder(array $segments): string
    {
        $parent = 'root'; $path = '';
        foreach ($segments as $seg) {
            $path .= '/' . $seg;
            if (isset($this->folderIds[$path])) { $parent = $this->folderIds[$path]; continue; }
            $q = "mimeType='application/vnd.google-apps.folder' and trashed=false and name='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $seg) . "' and '{$parent}' in parents";
            $r = CloudHttp::request('GET', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1, 'spaces' => 'drive']), $this->bearer(), null, ['timeout' => 30]);
            if ($r['status'] !== 200) $this->fail($r, 'folder lookup');
            $id = CloudHttp::json($r)['files'][0]['id'] ?? null;
            if (!$id) {
                $r = CloudHttp::request('POST', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files?fields=id', array_merge($this->bearer(), ['Content-Type: application/json']),
                    json_encode(['name' => $seg, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent]]), ['timeout' => 30]);
                if ($r['status'] !== 200) $this->fail($r, 'folder creation');
                $id = CloudHttp::json($r)['id'] ?? null;
            }
            $this->folderIds[$path] = $parent = (string)$id;
        }
        return $parent;
    }

    public function upload(string $localPath, array $segments, string $name, string $mime): array
    {
        $parent = $this->folder($segments);
        $init = CloudHttp::request('POST', $this->ep('upload', 'https://www.googleapis.com') . '/upload/drive/v3/files?uploadType=resumable&fields=id,webViewLink',
            array_merge($this->bearer(), ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . filesize($localPath)]),
            json_encode(['name' => $name, 'parents' => [$parent]]), ['timeout' => 30]);
        $loc = $init['headers']['location'] ?? '';
        if ($init['status'] !== 200 || $loc === '') $this->fail($init, 'upload start');
        $r = CloudHttp::request('PUT', $loc, ['Content-Type: ' . $mime], null, ['infile' => $localPath, 'timeout' => 300]);
        if ($r['status'] < 200 || $r['status'] > 201) $this->fail($r, 'upload');
        $d = CloudHttp::json($r);
        if (empty($d['id'])) throw new CloudException('Google Drive: upload finished but no file id was returned.');
        return ['id' => (string)$d['id'], 'url' => (string)($d['webViewLink'] ?? '')];
    }

    public function download(string $remoteId, callable $sink): void
    {
        $r = CloudHttp::request('GET', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files/' . rawurlencode($remoteId) . '?alt=media', $this->bearer(), null, ['sink' => $sink, 'timeout' => 300]);
        if ($r['status'] !== 200) $this->fail($r, 'download');
    }

    public function delete(string $remoteId): void
    {
        $r = CloudHttp::request('DELETE', $this->ep('api', 'https://www.googleapis.com') . '/drive/v3/files/' . rawurlencode($remoteId), $this->bearer(), null, ['timeout' => 30]);
        if ($r['status'] !== 204 && $r['status'] !== 404) $this->fail($r, 'delete');
    }
}
