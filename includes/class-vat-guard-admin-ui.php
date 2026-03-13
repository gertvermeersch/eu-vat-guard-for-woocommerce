<?php
/**
 * VAT Guard Admin UI Helper
 *
 * Provides standardized UI components for admin pages
 *
 * @package Stormlabs\EUVATGuard
 */

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI Helper Class
 * 
 * Provides reusable UI components with consistent styling
 */
class VAT_Guard_Admin_UI {

    /**
     * CSS class constants for consistent styling
     */
    const BOX_SUCCESS = 'vat-guard-box vat-guard-box-success';
    const BOX_INFO = 'vat-guard-box vat-guard-box-info';
    const BOX_WARNING = 'vat-guard-box vat-guard-box-warning';
    const BOX_DANGER = 'vat-guard-box vat-guard-box-danger';
    const BOX_NEUTRAL = 'vat-guard-box vat-guard-box-neutral';

    /**
     * Enqueue admin styles
     */
    public static function enqueue_styles() {
        wp_enqueue_style(
            'eu-vat-guard-admin-ui',
            EU_VAT_GUARD_PLUGIN_URL . 'assets/css/admin-ui.css',
            array(),
            EU_VAT_GUARD_VERSION
        );
    }

    /**
     * Render page header
     *
     * @param string $title Page title
     * @param string $icon Icon emoji (optional)
     */
    public static function page_header($title, $icon = '🛡️') {
        ?>
        <h1 class="vat-guard-page-title">
            <?php if ($icon): ?>
                <span class="vat-guard-icon"><?php echo esc_html($icon); ?></span>
            <?php endif; ?>
            <?php echo esc_html($title); ?>
        </h1>
        <?php
    }

    /**
     * Render info box
     *
     * @param string $type Box type (success, info, warning, danger, neutral)
     * @param string $content Box content (HTML allowed)
     * @param string $title Optional title
     */
    public static function info_box($type, $content, $title = '') {
        $class_map = array(
            'success' => self::BOX_SUCCESS,
            'info' => self::BOX_INFO,
            'warning' => self::BOX_WARNING,
            'danger' => self::BOX_DANGER,
            'neutral' => self::BOX_NEUTRAL,
        );

        $class = isset($class_map[$type]) ? $class_map[$type] : self::BOX_NEUTRAL;
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <?php if ($title): ?>
                <h3 class="vat-guard-box-title"><?php echo esc_html($title); ?></h3>
            <?php endif; ?>
            <div class="vat-guard-box-content">
                <?php echo wp_kses_post($content); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render tab navigation
     *
     * @param array $tabs Array of tabs with 'id', 'label', and optional 'url'
     * @param string $active_tab Currently active tab ID
     * @param string $page Page slug for URL generation
     */
    public static function tab_navigation($tabs, $active_tab, $page = 'eu-vat-guard') {
        ?>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab): ?>
                <?php
                $url = isset($tab['url']) ? $tab['url'] : "?page={$page}&tab={$tab['id']}";
                $is_active = $active_tab === $tab['id'];
                $extra_class = isset($tab['class']) ? ' ' . $tab['class'] : '';
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="nav-tab <?php echo $is_active ? 'nav-tab-active' : ''; echo esc_attr($extra_class); ?>">
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </h2>
        <?php
    }

    /**
     * Render settings section header
     *
     * @param string $title Section title
     */
    public static function section_header($title) {
        ?>
        <h2 class="vat-guard-section-title"><?php echo esc_html($title); ?></h2>
        <?php
    }

    /**
     * Render statistics table
     *
     * @param array $stats Array of statistics with 'label' and 'value'
     */
    public static function stats_table($stats) {
        ?>
        <table class="widefat striped vat-guard-stats-table">
            <tbody>
                <?php foreach ($stats as $stat): ?>
                    <tr>
                        <td class="vat-guard-stat-label">
                            <strong><?php echo esc_html($stat['label']); ?></strong>
                        </td>
                        <td class="vat-guard-stat-value">
                            <?php echo wp_kses_post($stat['value']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render action buttons group
     *
     * @param array $buttons Array of buttons with 'label', 'id', 'class', 'disabled'
     */
    public static function action_buttons($buttons) {
        ?>
        <p class="vat-guard-action-buttons">
            <?php foreach ($buttons as $button): ?>
                <button type="button" 
                        id="<?php echo esc_attr($button['id']); ?>"
                        class="button <?php echo esc_attr($button['class'] ?? ''); ?>"
                        <?php echo !empty($button['disabled']) ? 'disabled' : ''; ?>>
                    <?php echo esc_html($button['label']); ?>
                </button>
            <?php endforeach; ?>
        </p>
        <?php
    }

    /**
     * Render feature list
     *
     * @param array $features Array of feature strings
     * @param string $icon Icon to use for each item (optional)
     */
    public static function feature_list($features, $icon = '✓') {
        ?>
        <ul class="vat-guard-feature-list">
            <?php foreach ($features as $feature): ?>
                <li>
                    <?php if ($icon): ?>
                        <span class="vat-guard-feature-icon"><?php echo esc_html($icon); ?></span>
                    <?php endif; ?>
                    <?php echo esc_html($feature); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * Render support banner
     *
     * @param string $message Banner message
     * @param array $links Array of links with 'url', 'label', 'target'
     */
    public static function support_banner($message, $links = array()) {
        ?>
        <div class="vat-guard-support-banner">
            <?php echo esc_html($message); ?>
            <?php foreach ($links as $link): ?>
                <a href="<?php echo esc_url($link['url']); ?>" 
                   <?php echo isset($link['target']) ? 'target="' . esc_attr($link['target']) . '"' : ''; ?>
                   class="vat-guard-support-link">
                    <?php echo esc_html($link['label']); ?>
                </a>
                <?php if ($link !== end($links)): ?>
                    <span class="vat-guard-separator"><?php esc_html_e('or', 'eu-vat-guard-for-woocommerce'); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render status badge
     *
     * @param string $status Status type (active, inactive, valid, invalid, etc.)
     * @param string $label Status label
     */
    public static function status_badge($status, $label) {
        $class_map = array(
            'active' => 'vat-guard-status-success',
            'valid' => 'vat-guard-status-success',
            'inactive' => 'vat-guard-status-error',
            'invalid' => 'vat-guard-status-error',
            'warning' => 'vat-guard-status-warning',
            'neutral' => 'vat-guard-status-neutral',
        );

        $icon_map = array(
            'active' => '✓',
            'valid' => '✓',
            'inactive' => '✗',
            'invalid' => '✗',
            'warning' => '⚠',
            'neutral' => '•',
        );

        $class = isset($class_map[$status]) ? $class_map[$status] : 'vat-guard-status-neutral';
        $icon = isset($icon_map[$status]) ? $icon_map[$status] : '';
        ?>
        <span class="vat-guard-status-badge <?php echo esc_attr($class); ?>">
            <?php if ($icon): ?>
                <span class="vat-guard-status-icon"><?php echo esc_html($icon); ?></span>
            <?php endif; ?>
            <?php echo esc_html($label); ?>
        </span>
        <?php
    }

    /**
     * Render collapsible section
     *
     * @param string $title Section title
     * @param string $content Section content (HTML allowed)
     * @param bool $open Whether section is open by default
     */
    public static function collapsible_section($title, $content, $open = false) {
        $details_attr = $open ? 'open' : '';
        ?>
        <details class="vat-guard-collapsible" <?php echo esc_attr($details_attr); ?>>
            <summary class="vat-guard-collapsible-title"><?php echo esc_html($title); ?></summary>
            <div class="vat-guard-collapsible-content">
                <?php echo wp_kses_post($content); ?>
            </div>
        </details>
        <?php
    }
}
