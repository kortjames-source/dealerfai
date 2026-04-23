<?php

function dealerfai_get_theme_palette(?string $themeVariant, array $overrides = []): array
{
    $variant = strtolower(trim((string)$themeVariant));

    $theme = [
        'variant' => $variant !== '' ? $variant : 'default',
        'logo' => '',
        'color' => '#0a6280',
        'page_background' => '#f4f6f8',
        'header_background' => '#0a2e36',
        'header_text' => '#ffffff',
        'nav_background' => '#0a2e36',
        'nav_text' => '#ffffff',
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
    if (!empty($overrides['color'])) {
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
