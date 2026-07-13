<?php

return [
    'categories' => [
        'payment' => [
            'label_key' => 'app.integration_category_payment',
        ],
        'fiscalization' => [
            'label_key' => 'app.integration_category_fiscalization',
        ],
        'messaging' => [
            'label_key' => 'app.integration_category_messaging',
        ],
        'email' => [
            'label_key' => 'app.integration_category_email',
        ],
        'authentication' => [
            'label_key' => 'app.integration_category_authentication',
        ],
    ],

    'providers' => [
        'monopay' => [
            'label' => 'Monopay (monobank)',
            'category' => 'payment',
            'fields' => [
                'api_token' => ['label_key' => 'app.integration_field_api_token', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'invoice_validity_seconds' => ['label_key' => 'app.integration_field_invoice_validity_seconds', 'type' => 'integer', 'min' => 60, 'max' => 2592000],
                'qr_id' => ['label_key' => 'app.integration_field_qr_id', 'type' => 'text', 'max' => 255],
            ],
        ],
        'liqpay' => [
            'label' => 'LiqPay',
            'category' => 'payment',
            'fields' => [
                'public_key' => ['label_key' => 'app.integration_field_public_key', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'private_key' => ['label_key' => 'app.integration_field_private_key', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'api_version' => ['label_key' => 'app.integration_field_api_version', 'type' => 'integer', 'default' => 7, 'min' => 1, 'max' => 20],
            ],
        ],
        'wayforpay' => [
            'label' => 'WayForPay',
            'category' => 'payment',
            'fields' => [
                'merchant_account' => ['label_key' => 'app.integration_field_merchant_account', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'merchant_secret_key' => ['label_key' => 'app.integration_field_merchant_secret_key', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'merchant_domain_name' => ['label_key' => 'app.integration_field_merchant_domain_name', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'api_version' => ['label_key' => 'app.integration_field_api_version', 'type' => 'integer', 'default' => 1, 'min' => 1, 'max' => 20],
                'merchant_auth_type' => ['label_key' => 'app.integration_field_merchant_auth_type', 'type' => 'text', 'default' => 'SimpleSignature', 'max' => 255],
            ],
        ],
        'ladna_fiscalization' => [
            'label' => 'Ladna fiscalization',
            'category' => 'fiscalization',
            'description_key' => 'app.integration_ladna_fiscalization_copy',
            'fields' => [],
        ],
        'checkbox' => [
            'label' => 'Checkbox',
            'category' => 'fiscalization',
            'fields' => [
                'license_key' => ['label_key' => 'app.integration_field_license_key', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'cashier_login' => ['label_key' => 'app.integration_field_cashier_login', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'cashier_password' => ['label_key' => 'app.integration_field_cashier_password', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
            ],
        ],
        'turbosms' => [
            'label' => 'TurboSMS',
            'category' => 'messaging',
            'fields' => [
                'api_token' => ['label_key' => 'app.integration_field_api_token', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'sms_sender' => ['label_key' => 'app.integration_field_sms_sender', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'viber_sender' => ['label_key' => 'app.integration_field_viber_sender', 'type' => 'text', 'max' => 255],
            ],
        ],
        'smsclub' => [
            'label' => 'Smsclub.mobi',
            'category' => 'messaging',
            'fields' => [
                'bearer_token' => ['label_key' => 'app.integration_field_bearer_token', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'src_addr' => ['label_key' => 'app.integration_field_src_addr', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'integration_id' => ['label_key' => 'app.integration_field_integration_id', 'type' => 'text', 'max' => 255],
            ],
        ],
        'sendpulse' => [
            'label' => 'SendPulse',
            'category' => 'messaging',
            'fields' => [
                'auth_mode' => ['label_key' => 'app.integration_field_auth_mode', 'type' => 'select', 'default' => 'api_key', 'required_when_enabled' => true, 'options' => [
                    'api_key' => 'app.integration_option_api_key',
                    'oauth' => 'app.integration_option_oauth',
                ]],
                'api_key' => ['label_key' => 'app.integration_field_api_key', 'type' => 'password', 'sensitive' => true, 'required_when_enabled_if' => ['auth_mode' => 'api_key'], 'max' => 2048],
                'client_id' => ['label_key' => 'app.integration_field_client_id', 'type' => 'text', 'required_when_enabled_if' => ['auth_mode' => 'oauth'], 'max' => 255],
                'client_secret' => ['label_key' => 'app.integration_field_client_secret', 'type' => 'password', 'sensitive' => true, 'required_when_enabled_if' => ['auth_mode' => 'oauth'], 'max' => 2048],
                'smtp_host' => ['label_key' => 'app.integration_field_smtp_host', 'type' => 'text', 'max' => 255],
                'smtp_port' => ['label_key' => 'app.integration_field_smtp_port', 'type' => 'integer', 'min' => 1, 'max' => 65535],
                'smtp_login' => ['label_key' => 'app.integration_field_smtp_login', 'type' => 'text', 'max' => 255],
                'smtp_password' => ['label_key' => 'app.integration_field_smtp_password', 'type' => 'password', 'sensitive' => true, 'max' => 2048],
                'smtp_encryption' => ['label_key' => 'app.integration_field_smtp_encryption', 'type' => 'select', 'options' => [
                    '' => 'app.integration_option_none',
                    'tls' => 'TLS',
                    'ssl' => 'SSL',
                ]],
                'mail_from_email' => ['label_key' => 'app.integration_field_mail_from_email', 'type' => 'email', 'max' => 255],
                'mail_from_name' => ['label_key' => 'app.integration_field_mail_from_name', 'type' => 'text', 'max' => 255],
                'sms_sender' => ['label_key' => 'app.integration_field_sms_sender', 'type' => 'text', 'max' => 255],
                'sms_address_book_id' => ['label_key' => 'app.integration_field_sms_address_book_id', 'type' => 'integer', 'min' => 1],
                'sms_route' => ['label_key' => 'app.integration_field_sms_route', 'type' => 'text', 'max' => 255],
            ],
        ],
        'mail_delivery' => [
            'label' => 'Email delivery',
            'category' => 'email',
            'scopes' => ['platform'],
            'fields' => [
                'engine' => ['label_key' => 'app.integration_field_mail_engine', 'type' => 'select', 'default' => 'sendpulse_smtp', 'required_when_enabled' => true, 'options' => [
                    'sendpulse_smtp' => 'app.mail_engine_sendpulse_smtp',
                    'smtp' => 'app.mail_engine_smtp',
                    'sendmail' => 'app.mail_engine_sendmail',
                    'log' => 'app.mail_engine_log',
                ]],
                'fallback_engine' => ['label_key' => 'app.integration_field_mail_fallback_engine', 'type' => 'select', 'default' => 'log', 'options' => [
                    'sendmail' => 'app.mail_engine_sendmail',
                    'log' => 'app.mail_engine_log',
                ]],
                'mail_from_email' => ['label_key' => 'app.integration_field_mail_from_email', 'type' => 'email', 'required_when_enabled' => true, 'max' => 255],
                'mail_from_name' => ['label_key' => 'app.integration_field_mail_from_name', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'smtp_host' => ['label_key' => 'app.integration_field_smtp_host', 'type' => 'text', 'default' => 'smtp-pulse.com', 'required_when_enabled_if' => ['engine' => ['sendpulse_smtp', 'smtp']], 'max' => 255],
                'smtp_port' => ['label_key' => 'app.integration_field_smtp_port', 'type' => 'integer', 'default' => 587, 'required_when_enabled_if' => ['engine' => ['sendpulse_smtp', 'smtp']], 'min' => 1, 'max' => 65535],
                'smtp_login' => ['label_key' => 'app.integration_field_smtp_login', 'type' => 'text', 'required_when_enabled_if' => ['engine' => ['sendpulse_smtp', 'smtp']], 'max' => 255],
                'smtp_password' => ['label_key' => 'app.integration_field_smtp_password', 'type' => 'password', 'sensitive' => true, 'required_when_enabled_if' => ['engine' => ['sendpulse_smtp', 'smtp']], 'max' => 2048],
                'smtp_encryption' => ['label_key' => 'app.integration_field_smtp_encryption', 'type' => 'select', 'default' => 'tls', 'options' => [
                    '' => 'app.integration_option_none',
                    'tls' => 'TLS',
                    'ssl' => 'SSL',
                ]],
            ],
        ],
        'google_oauth' => [
            'label' => 'Google OAuth',
            'category' => 'authentication',
            'scopes' => ['platform'],
            'fields' => [
                'client_id' => ['label_key' => 'app.integration_field_google_client_id', 'type' => 'text', 'required_when_enabled' => true, 'max' => 1024],
                'client_secret' => ['label_key' => 'app.integration_field_google_client_secret', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
                'credentials_json' => ['label_key' => 'app.integration_field_google_credentials_json', 'type' => 'textarea', 'sensitive' => true, 'max' => 8192, 'rows' => 6],
            ],
        ],
        'cloudflare_turnstile' => [
            'label' => 'Cloudflare Turnstile',
            'category' => 'authentication',
            'scopes' => ['platform'],
            'fields' => [
                'site_key' => ['label_key' => 'app.integration_field_turnstile_site_key', 'type' => 'text', 'required_when_enabled' => true, 'max' => 255],
                'secret_key' => ['label_key' => 'app.integration_field_turnstile_secret_key', 'type' => 'password', 'sensitive' => true, 'required_when_enabled' => true, 'max' => 2048],
            ],
        ],
    ],
];
