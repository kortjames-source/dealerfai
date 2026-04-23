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
            'color'             => '#0a6280',
            'page_background'   => '#f4f6f8',
            'header_background' => '#0a2e36',
            'header_text'       => '#ffffff',
            'nav_background'    => '#0a2e36',
            'nav_text'          => '#ffffff',
        ];

        $t = array_merge($defaults, (array)$theme);

        // Sanitize every value — these go directly into a <style> block.
        $escape = static function (string $v): string {
            // Strip anything that could break out of a CSS property value.
            return preg_replace('/[^a-zA-Z0-9#(),. %\-]/', '', $v);
        };

        $brandColor  = $escape((string)$t['color']);
        $pageBg      = $escape((string)$t['page_background']);
        $headerBg    = $escape((string)$t['header_background']);
        $headerText  = $escape((string)$t['header_text']);
        $navBg       = $escape((string)$t['nav_background']);
        $navText     = $escape((string)$t['nav_text']);

        $nonce = dealerfai_csp_nonce();
        ?>
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
  :root {
    --brand-color: <?= $brandColor ?>;
    --page-bg:     <?= $pageBg ?>;
    --header-bg:   <?= $headerBg ?>;
    --header-text: <?= $headerText ?>;
    --nav-bg:      <?= $navBg ?>;
    --nav-text:    <?= $navText ?>;
  }
</style>
<link rel="stylesheet" href="/assets/css/app.css">
        <?php
    }
}
