<?php
/**
 * Outputs the per-org CSS custom properties (theme variables) in a nonce-protected
 * <style> block, followed by the application stylesheet <link>.
 *
 * Usage (in every PHP page, inside <head>, after security_headers.php has run):
 *
 *   <?php dealerfai_theme_head($theme); ?>
 *
 * The $theme array comes from dealerfai_get_theme_palette() in helpers/theme.php.
 * Pages that use a static "#0a2e36" header (login, mfa, etc.) may pass a minimal
 * array or leave it null to use defaults.
 *
 * CSS variables set:
 *   --brand-color   Primary accent colour
 *   --page-bg       Page background
 *   --header-bg     Header bar background
 *   --header-text   Header bar text / foreground
 *   --nav-bg        Navigation bar background
 *   --nav-text      Navigation bar link colour
 */

if (!function_exists('dealerfai_theme_head')) {
    function dealerfai_theme_head(?array $theme = null): void
    {
        $defaults = [
            'color'             => '#0066cc', // Modern AI Blue
            'page_background'   => 'transparent', // Let the bg image show through
            'header_background' => 'rgba(255, 255, 255, 0.5)',
            'header_text'       => '#0f172a',
            'nav_background'    => '#ffffff',
            'nav_text'          => '#475569',
            'card_background'   => '#ffffff',
            'border_color'      => '#e2e8f0',
        ];

        $t = array_merge($defaults, (array)$theme);

        // Sanitize every value
        $escape = static function (string $v): string {
            return preg_replace('/[^a-zA-Z0-9#(),. %\-]/', '', $v);
        };

        $brandColor  = $escape((string)$t['color']);
        $pageBg      = $escape((string)$t['page_background']);
        $headerBg    = $escape((string)$t['header_background']);
        $headerText  = $escape((string)$t['header_text']);
        $navBg       = $escape((string)$t['nav_background']);
        $navText     = $escape((string)$t['nav_text']);
        $cardBg      = $escape((string)($t['card_background'] ?? '#ffffff'));
        $borderColor = $escape((string)($t['border_color'] ?? '#e2e8f0'));

        // Helper to get HSL for translucency
        $hexToHsl = function($hex) {
            $hex = str_replace('#', '', $hex);
            if(strlen($hex) == 3) {
                $r = hexdec(substr($hex,0,1).substr($hex,0,1));
                $g = hexdec(substr($hex,1,1).substr($hex,1,1));
                $b = hexdec(substr($hex,2,1).substr($hex,2,1));
            } else {
                $r = hexdec(substr($hex,0,2));
                $g = hexdec(substr($hex,2,2));
                $b = hexdec(substr($hex,4,2));
            }
            $r /= 255; $g /= 255; $b /= 255;
            $max = max($r, $g, $b); $min = min($r, $g, $b);
            $l = ($max + $min) / 2;
            if ($max == $min) { $h = $s = 0; }
            else {
                $d = $max - $min;
                $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
                switch($max){
                    case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
                    case $g: $h = ($b - $r) / $d + 2; break;
                    case $b: $h = ($r - $g) / $d + 4; break;
                }
                $h /= 6;
            }
            return [round($h * 360), round($s * 100) . '%', round($l * 100) . '%'];
        };

        $hsl = $hexToHsl($brandColor);
        $brandHsl = "{$hsl[0]}, {$hsl[1]}, {$hsl[2]}";

        $nonce = dealerfai_csp_nonce();
        ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
  :root {
    --brand-color: <?= $brandColor ?>;
    --brand-hsl:   <?= $brandHsl ?>;
    --page-bg:     <?= $pageBg ?>;
    --header-bg:   <?= $headerBg ?>;
    --header-text: <?= $headerText ?>;
    --nav-bg:      <?= $navBg ?>;
    --nav-text:    <?= $navText ?>;
    --card-bg:     <?= $cardBg ?>;
    --border-color:<?= $borderColor ?>;
    
    /* Modern Accents derived from brand color */
    --accent-glow: rgba(<?= $brandHsl ?>, 0.15);
    --accent-soft: rgba(<?= $brandHsl ?>, 0.08);
    --glass-bg:    rgba(255, 255, 255, 0.7);
    --radius-lg:   12px;
    --radius-md:   8px;
    --shadow-sm:   0 1px 2px rgba(0,0,0,0.05);
    --shadow-md:   0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    --shadow-lg:   0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
  }
</style>
<link rel="stylesheet" href="/assets/css/app.css">
        <?php
    }
}

