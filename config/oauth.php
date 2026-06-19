<?php

return [
    /*
     * OAuth2 provider metadata (endpoints + scopes + SMTP host) — the static, NON-secret
     * half. Client id/secret are entered per-tenant/platform in Settings and stored
     * AES-256-GCM encrypted (SecretCipher); they never live here.
     *
     * Two purposes share each provider:
     *   - 'login' : social sign-in (resolve a verified email → existing member).
     *   - 'email' : obtain a refresh token to SEND mail over XOAUTH2 (Gmail/MS/Yahoo).
     *
     * Per-provider quirks are config flags so the flow stays generic:
     *   token_auth   : 'body' (id/secret in form) | 'basic' (HTTP Basic — Yahoo).
     *   token_method : 'post' (default) | 'get' (Facebook).
     */
    'providers' => [
        'google' => [
            'label' => 'Google',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes' => [
                'login' => ['openid', 'email', 'profile'],
                'email' => ['openid', 'email', 'https://mail.google.com/'],
            ],
            // access_type=offline + prompt=consent are required to receive a refresh_token.
            'authorize_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
            'token_auth' => 'body',
            'token_method' => 'post',
            'smtp' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
            'supports' => ['login', 'email'],
            'email_field' => 'email',
        ],
        'microsoft' => [
            'label' => 'Microsoft',
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'scopes' => [
                'login' => ['openid', 'email', 'profile'],
                'email' => ['openid', 'email', 'offline_access', 'https://outlook.office.com/SMTP.Send'],
            ],
            'authorize_params' => [],
            'token_auth' => 'body',
            'token_method' => 'post',
            'smtp' => ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls'],
            'supports' => ['login', 'email'],
            'email_field' => 'email',
        ],
        'yahoo' => [
            'label' => 'Yahoo',
            'authorize_url' => 'https://api.login.yahoo.com/oauth2/request_auth',
            'token_url' => 'https://api.login.yahoo.com/oauth2/get_token',
            'userinfo_url' => 'https://api.login.yahoo.com/openid/v1/userinfo',
            'scopes' => [
                'login' => ['openid', 'email', 'profile'],
                'email' => ['openid', 'email', 'mail-w'],
            ],
            'authorize_params' => [],
            // Yahoo requires the client credentials as an HTTP Basic header on token calls.
            'token_auth' => 'basic',
            'token_method' => 'post',
            'smtp' => ['host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl'],
            'supports' => ['login', 'email'],
            'email_field' => 'email',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'authorize_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
            'userinfo_url' => 'https://graph.facebook.com/me?fields=id,name,email',
            'scopes' => [
                'login' => ['email', 'public_profile'],
            ],
            'authorize_params' => [],
            'token_auth' => 'body',
            'token_method' => 'get',
            'supports' => ['login'],
            'email_field' => 'email',
        ],
    ],
];
