<?php

function dealerfai_get_theme_palette(?string $themeVariant, array $overrides = []): array
{
    $variant = strtolower(trim((string)$themeVariant));

    // Modern "Clean" Defaults
    $theme = [
        'variant' => $variant !== '' ? $variant : 'default',
        'logo' => '',
        'color' => '#0066cc', // Default primary accent
        'primary_color' => null,
        'page_background' => '#f8fafc',
        'header_background' => '#ffffff',
        'header_text' => '#1e293b',
        'nav_background' => '#ffffff',
        'nav_text' => '#475569',
        'card_background' => '#ffffff',
        'border_color' => '#e2e8f0',
    ];

    if ($variant === 'jlr') {
        $theme['color'] = '#1a1a1a';
        $theme['page_background'] = '#ffffff';
        $theme['header_background'] = '#ffffff';
        $theme['header_text'] = '#111111';
        $theme['nav_background'] = '#ffffff';
        $theme['nav_text'] = '#111111';
    } elseif (in_array($variant, ['land_rover', 'landrover', 'jaguar'], true)) {
        $theme['color'] = '#1a1a1a';
    }

    if (array_key_exists('logo', $overrides)) {
        $theme['logo'] = (string)$overrides['logo'];
    }
    if (!empty($overrides['primary_color'])) {
        $theme['primary_color'] = (string)$overrides['primary_color'];
        $theme['color'] = $theme['primary_color']; // Primary color overrides brand accent
    } elseif (!empty($overrides['color'])) {
        $theme['color'] = (string)$overrides['color'];
    }

    if (!empty($overrides['page_background'])) {
        $theme['page_background'] = (string)$overrides['page_background'];
    }
    foreach (['header_background', 'header_text', 'nav_background', 'nav_text'] as $key) {
        if (!empty($overrides[$key])) {
            $theme[$key] = (string)$overrides[$key];
        }
    }

    return $theme;
}

