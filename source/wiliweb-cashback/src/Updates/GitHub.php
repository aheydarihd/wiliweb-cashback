<?php

namespace WiliWeb\Cashback\Updates;

final class GitHub
{
    private const REPO = 'aheydarihd/wiliweb-cashback';
    private const CACHE = 'wwcb_github_release';

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'update'], 10, 4);
        add_filter('plugins_api', [$this, 'information'], 20, 3);
    }

    public function release(): ?array
    {
        $cached = get_transient(self::CACHE);
        if (is_array($cached)) {
            return $cached ?: null;
        }
        $response = wp_remote_get('https://api.github.com/repos/'.self::REPO.'/releases/latest', [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE, [], 5 * MINUTE_IN_SECONDS);
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $version = ltrim((string)($data['tag_name'] ?? ''), 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }
        $package = '';
        foreach (($data['assets'] ?? []) as $asset) {
            $expected = 'https://github.com/'.self::REPO.'/releases/download/'.$data['tag_name'].'/wiliweb-cashback-'.$version.'.zip';
            if (($asset['name'] ?? '') === 'wiliweb-cashback-'.$version.'.zip' && ($asset['browser_download_url'] ?? '') === $expected) {
                $package = $expected;
                break;
            }
        }
        if (!$package) {
            return null;
        }
        $release = ['version' => $version, 'package' => $package, 'url' => 'https://github.com/'.self::REPO, 'notes' => (string)($data['body'] ?? '')];
        set_transient(self::CACHE, $release, HOUR_IN_SECONDS);
        return $release;
    }

    public function update($update, array $pluginData, string $pluginFile, array $locales)
    {
        if ($pluginFile !== plugin_basename(WWCB_FILE)) {
            return $update;
        }
        $release = $this->release();
        if (!$release) {
            return $update;
        }
        return ['id' => 'https://github.com/'.self::REPO, 'slug' => 'wiliweb-cashback', 'version' => $release['version'], 'url' => $release['url'], 'package' => $release['package'], 'tested' => '', 'requires_php' => '8.1', 'requires' => '6.5'];
    }

    public function information($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'wiliweb-cashback') {
            return $result;
        }
        $release = $this->release();
        if (!$release) {
            return $result;
        }
        return (object)['name' => 'WiliWeb Cashback | کش‌بک و کیف پول', 'slug' => 'wiliweb-cashback', 'version' => $release['version'], 'author' => '<a href="https://wiliweb.com">WiliWeb</a>', 'homepage' => $release['url'], 'requires' => '6.5', 'requires_php' => '8.1', 'download_link' => $release['package'], 'sections' => ['description' => 'کش‌بک و کیف پول ووکامرس با تاریخ انقضای مستقل و پیامک IPPanel.', 'changelog' => nl2br(esc_html($release['notes']))]];
    }
}
