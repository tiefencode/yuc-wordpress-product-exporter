<?php
/*
Plugin Name: WooCommerce Product Exporter (Tiefenexporter)
Description: Exports WooCommerce products to .csv for Facebook and Shopify. Also Exports Product data to Shopify API.
Version: 1.3 // Version aktualisiert
Author: Michael Oswald
*/

// Sicherstellen, dass WooCommerce aktiv ist
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

// Pfad zum 'includes'-Verzeichnis definieren
define( 'WOO_EXPORTER_INC_PATH', plugin_dir_path( __FILE__ ) . 'includes/' );

// Alle notwendigen Dateien einbinden
require_once WOO_EXPORTER_INC_PATH . 'product-data-retriever.php';
require_once WOO_EXPORTER_INC_PATH . 'facebook-feed-generator.php';
require_once WOO_EXPORTER_INC_PATH . 'shopify-feed-generator.php';
require_once WOO_EXPORTER_INC_PATH . 'admin-page.php';
require_once WOO_EXPORTER_INC_PATH . 'cron-jobs.php';
require_once WOO_EXPORTER_INC_PATH . 'settings-page.php';
require_once WOO_EXPORTER_INC_PATH . 'shopify-api-importer.php'; // Neu hinzugefügt

// Aktionen und Filter registrieren
add_action( 'admin_menu', 'woo_exporter_menu' );
add_action( 'admin_menu', 'woo_exporter_add_settings_page' );
add_action( 'admin_init', 'woo_exporter_settings_init' );
add_action( 'init', 'schedule_all_exports' );

// Cron-Hooks für die geplanten Exporte
add_action( 'woo_facebook_feed_export_hourly', 'run_facebook_feed_scheduled' );
add_action( 'woo_shopify_feed_export_daily', 'run_shopify_feed_export_scheduled' );

// Filter für das stündliche Cron-Intervall
add_filter( 'cron_schedules', 'add_hourly_cron_interval' );

// Deaktivierungs-Hook für das Plugin
register_deactivation_hook( __FILE__, 'deactivate_all_export_schedules' );

?>
