<?php

return [
    'default_template' => 'general',
    'default_palette' => 'general',

    'templates' => [
        'general' => [
            'key' => 'general',
            'name_key' => 'app.festival_landing_template_general',
            'view' => 'festivals.public.templates.general',
            'thumbnail' => 'assets/festivals/landing-templates/general.webp',
        ],
        'velvet_night' => [
            'key' => 'velvet_night',
            'name_key' => 'app.festival_landing_template_velvet_night',
            'view' => 'festivals.public.templates.velvet-night',
            'thumbnail' => 'assets/festivals/landing-templates/velvet-night.webp',
        ],
    ],

    'palettes' => [
        'general' => [
            'key' => 'general',
            'name_key' => 'app.festival_landing_palette_general',
            'swatches' => ['#FAF8F5', '#3B223F', '#A78AB9', '#E7DDC9'],
            'tokens' => [
                'page' => '#FAF8F5',
                'surface' => '#FFFFFF',
                'text' => '#2B2B2F',
                'muted_text' => '#64748B',
                'primary' => '#3B223F',
                'primary_text' => '#FFFFFF',
                'accent' => '#A78AB9',
                'accent_text' => '#2B1731',
                'border' => '#E7DDC9',
            ],
        ],
        'editorial_blush' => [
            'key' => 'editorial_blush',
            'name_key' => 'app.festival_landing_palette_editorial_blush',
            'swatches' => ['#FFF9F5', '#3B223F', '#C12E61', '#E7D6D4'],
            'tokens' => [
                'page' => '#FFF9F5',
                'surface' => '#FFFFFF',
                'text' => '#252128',
                'muted_text' => '#6E6168',
                'primary' => '#3B223F',
                'primary_text' => '#FFFFFF',
                'accent' => '#C12E61',
                'accent_text' => '#FFFFFF',
                'border' => '#E7D6D4',
            ],
        ],
        'velvet_theatre' => [
            'key' => 'velvet_theatre',
            'name_key' => 'app.festival_landing_palette_velvet_theatre',
            'swatches' => ['#120203', '#9E0E17', '#D9AE62', '#FFF5EE'],
            'tokens' => [
                'page' => '#120203',
                'surface' => '#210305',
                'text' => '#FFF5EE',
                'muted_text' => '#D8B7B3',
                'primary' => '#9E0E17',
                'primary_text' => '#FFFFFF',
                'accent' => '#D9AE62',
                'accent_text' => '#25140D',
                'border' => '#571317',
            ],
        ],
        'electric_stage' => [
            'key' => 'electric_stage',
            'name_key' => 'app.festival_landing_palette_electric_stage',
            'swatches' => ['#16131D', '#E50072', '#9B83D6', '#FF766E'],
            'tokens' => [
                'page' => '#16131D',
                'surface' => '#211B2B',
                'text' => '#FFF9F1',
                'muted_text' => '#CBC2D6',
                'primary' => '#E50072',
                'primary_text' => '#FFFFFF',
                'accent' => '#9B83D6',
                'accent_text' => '#18121F',
                'border' => '#3B3149',
            ],
        ],
        'midnight_gold' => [
            'key' => 'midnight_gold',
            'name_key' => 'app.festival_landing_palette_midnight_gold',
            'swatches' => ['#0F1C2E', '#C7933E', '#E6C777', '#FFF7E6'],
            'tokens' => [
                'page' => '#0F1C2E',
                'surface' => '#17283F',
                'text' => '#FFF7E6',
                'muted_text' => '#C1CBD8',
                'primary' => '#C7933E',
                'primary_text' => '#1C160B',
                'accent' => '#E6C777',
                'accent_text' => '#1C160B',
                'border' => '#314967',
            ],
        ],
    ],
];
