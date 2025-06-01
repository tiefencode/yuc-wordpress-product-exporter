<?php
/**
 * Funktionen für die Plugin-Einstellungen (Shopify API Zugangsdaten).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Fügt eine Unterseite für die Einstellungen zum Admin-Menü hinzu.
 */
function woo_exporter_add_settings_page() {
    add_options_page(
        'WooCommerce Product Exporter (Tiefenexporter) Einstellungen', // Seitentitel
        'Tiefenexporter Einstellungen', // Menü-Titel
        'manage_options', // Benötigte Berechtigung
        'woo-exporter-settings', // Menü-Slug
        'woo_exporter_settings_page_content' // Callback-Funktion zum Anzeigen des Inhalts
    );
}

/**
 * Registriert die Einstellungen und Sektionen.
 */
function woo_exporter_settings_init() {
    // Sektion für Shopify API
    add_settings_section(
        'woo_exporter_shopify_api_section', // ID der Sektion
        'Shopify API Zugangsdaten', // Titel der Sektion
        'woo_exporter_shopify_api_section_callback', // Callback-Funktion
        'woo-exporter-settings' // Seite, zu der die Sektion gehört
    );

    // Feld für Shopify Store URL
    add_settings_field(
        'woo_exporter_shopify_store_url', // ID des Feldes
        'Shopify Store URL', // Titel des Feldes
        'woo_exporter_shopify_store_url_callback', // Callback-Funktion
        'woo-exporter-settings', // Seite, zu der das Feld gehört
        'woo_exporter_shopify_api_section' // Sektion, zu der das Feld gehört
    );

    // Feld für Shopify Admin API Access Token
    add_settings_field(
        'woo_exporter_shopify_access_token', // ID des Feldes
        'Shopify Admin API Access Token', // Titel des Feldes
        'woo_exporter_shopify_access_token_callback', // Callback-Funktion
        'woo-exporter-settings', // Seite, zu der das Feld gehört
        'woo_exporter_shopify_api_section' // Sektion, zu der das Feld gehört
    );

    // Einstellungen registrieren
    register_setting( 'woo-exporter-settings', 'woo_exporter_shopify_store_url' );
    register_setting( 'woo-exporter-settings', 'woo_exporter_shopify_access_token' );
}

/**
 * Callback für die Shopify API Sektion Beschreibung.
 */
function woo_exporter_shopify_api_section_callback() {
    echo '<p>Geben Sie hier Ihre Shopify Store URL und Ihren Admin API Access Token ein, um den automatischen Produktimport zu ermöglichen.</p>';
    echo '<p>Die Store URL sollte das Format <code>your-store-name.myshopify.com</code> haben (ohne https://).</p>';
    echo '<p>Den Admin API Access Token erhalten Sie, indem Sie in Shopify eine Private App erstellen (Apps > Apps und Vertriebskanäle entwickeln > App entwickeln > App erstellen).</p>';
}

/**
 * Callback für das Shopify Store URL Feld.
 */
function woo_exporter_shopify_store_url_callback() {
    $value = get_option( 'woo_exporter_shopify_store_url' );
    echo '<input type="text" name="woo_exporter_shopify_store_url" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="your-store-name.myshopify.com">';
    echo '<p class="description">z.B. <code>your-shop.myshopify.com</code></p>';
}

/**
 * Callback für das Shopify Admin API Access Token Feld.
 */
function woo_exporter_shopify_access_token_callback() {
    $value = get_option( 'woo_exporter_shopify_access_token' );
    echo '<input type="password" name="woo_exporter_shopify_access_token" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="shpat_...">';
    echo '<p class="description">Der Admin API Access Token (beginnt oft mit <code>shpat_</code>)</p>';
}

/**
 * Inhalt der Einstellungen-Seite.
 */
function woo_exporter_settings_page_content() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'woo-exporter-settings' ); // Gruppe der Einstellungen
            do_settings_sections( 'woo-exporter-settings' ); // Alle registrierten Sektionen für diese Seite
            submit_button( 'Einstellungen speichern' );
            ?>
        </form>
    </div>
    <?php
}
