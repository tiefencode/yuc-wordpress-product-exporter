<?php
/**
 * Funktionen für die Admin-Seite des WooCommerce Product Exporters.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Admin-Seite anpassen, um beide Exporte anzubieten
function woo_exporter_menu() {
    add_management_page(
        'Tiefenexporter', // Haupttitel
        'Tiefenexporter', // Menütitel
        'manage_options',
        'woo-product-export', // Slug der Seite
        'woo_product_exporter_page_content'
    );
}

function woo_product_exporter_page_content() {
    ?>
    <div class="wrap">
        <h1>WooCommerce Product Exporter</h1>

        <h2>Facebook Product Feed Export</h2>
        <?php
        if ( isset( $_GET['export_fb_feed'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'export_fb_feed_nonce' ) ) {
            $facebook_feed_data = get_facebook_product_feed_data();
            if ( ! empty( $facebook_feed_data ) ) {
                $file_url = write_facebook_product_feed_csv( $facebook_feed_data, 'facebook_product_feed.csv' );
                if ( $file_url ) {
                    echo '<div class="notice notice-success is-dismissible"><p>Facebook Product Feed CSV erfolgreich generiert: <a href="' . esc_url( $file_url ) . '" target="_blank">' . esc_url( $file_url ) . '</a></p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Fehler beim Generieren der Facebook Product Feed CSV. Bitte Logs prüfen.</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>Keine Produktdaten für den Facebook Product Feed gefunden.</p></div>';
            }
        }
        ?>
        <p>Klicke auf den Button, um den Facebook Product Feed zu generieren. Die Datei wird im Upload-Verzeichnis abgelegt.</p>
        <form method="get" action="">
            <input type="hidden" name="page" value="woo-product-export" />
            <?php wp_nonce_field( 'export_fb_feed_nonce', '_wpnonce' ); ?>
            <input type="submit" name="export_fb_feed" class="button button-primary" value="Facebook Feed jetzt generieren" />
        </form>

        <hr>

        <h2>Shopify CSV Export</h2>
        <?php
        // ANPASSUNG: Hier wird nur der CSV-Export ausgelöst
        if ( isset( $_GET['export_shopify_csv'] ) && wp_verify_nonce( $_GET['_wpnonce_shopify_csv'], 'export_shopify_csv_nonce' ) ) {
            $shopify_feed_data = get_shopify_product_feed_data();
            if ( ! empty( $shopify_feed_data ) ) {
                $file_url = write_shopify_product_feed_csv( $shopify_feed_data, 'shopify_product_feed.csv' );
                if ( $file_url ) {
                    // ANPASSUNG: Erfolgsmeldung mit Link zur CSV-Datei
                    echo '<div class="notice notice-success is-dismissible"><p>Shopify CSV-Export erfolgreich generiert: <a href="' . esc_url( $file_url ) . '" target="_blank">' . esc_url( $file_url ) . '</a></p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>Fehler beim Generieren des Shopify CSV-Exports. Bitte Logs prüfen.</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p>Keine Produktdaten für den Shopify CSV-Export gefunden.</p></div>';
            }
        }
        ?>
        <p>Klicke auf den Button, um den Shopify CSV-Export zu generieren. Die Datei wird im Upload-Verzeichnis abgelegt.</p>
        <form method="get" action="">
            <input type="hidden" name="page" value="woo-product-export" />
            <?php wp_nonce_field( 'export_shopify_csv_nonce', '_wpnonce_shopify_csv' ); ?>
            <input type="submit" name="export_shopify_csv" class="button button-primary" value="Shopify CSV jetzt generieren" />
        </form>

        <?php
        // ANPASSUNG: Der Bereich für die Shopify Bulk Import Logs wurde entfernt
        ?>

    </div>
    <?php
}