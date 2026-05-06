<?php
/**
 * Plugin Name:       AAA Audio Summary Player
 * Plugin URI:        https://github.com/MattWhistler/whistlerAudioPlayer
 * Description:       Custom audio player for article summaries with anonymous listening analytics.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Whistler
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       audio-summary-player
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ASP_VERSION', '0.3.0');
define('ASP_PLUGIN_FILE', __FILE__);
define('ASP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASP_PLUGIN_BASENAME', plugin_basename(__FILE__));

$asp_autoload = ASP_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($asp_autoload)) {
    require_once $asp_autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'AudioSummaryPlayer\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = ASP_PLUGIN_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(__FILE__, [\AudioSummaryPlayer\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\AudioSummaryPlayer\Plugin::class, 'deactivate']);

add_action('plugins_loaded', [\AudioSummaryPlayer\Plugin::class, 'boot']);
