<?php
declare(strict_types=1);

/**
 * Updates des Webpanels (Recht: updates.manage) – gemeinsam für Webpanel-Seiten.
 */

// ------------------------------------------------------------ Updates (updates.manage)

function updates_overview(): array
{
    $secret = trim((string)cfg('deploy_webhook_secret')) !== '';
    return [
        'repo' => github_repo(), 'branch' => github_branch(), 'mode' => panel_is_git() ? 'git' : 'zip',
        'version' => panel_version(), 'current_sha' => panel_current_sha(),
        'last_update' => setting_get('panel_last_update', ''), 'checked_at' => (int)setting_get('panel_checked_at', '0'),
        'latest' => panel_latest_known(),
        'panel_update_mode' => setting_get('panel_update_mode', 'off'),
        'webhook_configured' => $secret, 'webhook_path' => '/deploy-webhook.php',
        'backups' => panel_backups(),
        'env' => ['zip' => class_exists('ZipArchive'), 'writable' => is_writable(PANEL_ROOT), 'git' => panel_is_git(), 'curl' => function_exists('curl_init')],
    ];
}

function updates_save_settings(array $actor, string $panelMode): void
{
    require_perm($actor, 'updates.manage');
    $ok = ['off', 'notify', 'auto'];
    setting_set('panel_update_mode', in_array($panelMode, $ok, true) ? $panelMode : 'off');
}
