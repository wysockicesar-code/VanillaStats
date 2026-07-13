<?php
// Configuration File — DO NOT share or commit to public repositories

$passwordFile = __DIR__ . '/.password';

// Default password is 'admin'
if (!file_exists($passwordFile)) {
    file_put_contents($passwordFile, password_hash('admin', PASSWORD_DEFAULT));
}

$passwordHash = trim(file_get_contents($passwordFile));

$siteTokenFile = __DIR__ . '/.site_token';
if (!file_exists($siteTokenFile)) {
    file_put_contents($siteTokenFile, bin2hex(random_bytes(16)));
}
$siteToken = trim(file_get_contents($siteTokenFile));

$config = [
    'password_hash'   => $passwordHash,
    'password_file'   => $passwordFile,
    'site_token'      => $siteToken,
    'site_token_file' => $siteTokenFile,
    'database'        => __DIR__ . '/analytics.db',
    'session_timeout' => 3600,
];

return $config;
