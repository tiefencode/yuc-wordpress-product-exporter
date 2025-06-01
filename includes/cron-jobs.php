<?php
/**
 * Funktionen für die Planung und Ausführung von Cron-Jobs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Fügt ein stündliches Intervall zu den WordPress Cron-Zeitplänen hinzu
add_filter( 'cron_schedules', 'add_hourly_cron_interval' );
function add_hourly_cron_interval( $schedules ) {
    $schedules['hourly'] = array(
        'interval' => 3600, // 3600 Sekunden = 1 Stunde
        'display'  => __( 'Once Hourly', 'woocommerce-product-exporter' )
    );
    return $schedules;
}

// Automatische Planung für beide Exporte
add_action( 'init', 'schedule_all_exports' );
function schedule_all_exports() {
    // Facebook Export (stündlich)
    if ( ! wp_next_scheduled( 'woo_facebook_feed_export_hourly' ) ) {
        wp_schedule_event( time(), 'hourly', 'woo_facebook_feed_export_hourly' );
    }
    // Shopify Export (täglich)
    if ( ! wp_next_scheduled( 'woo_shopify_feed_export_daily' ) ) {
        wp_schedule_event( time(), 'daily', 'woo_shopify_feed_export_daily' );
    }
}

add_action( 'woo_facebook_feed_export_hourly', 'run_facebook_feed_scheduled' );
function run_facebook_feed_scheduled() {
    // Stellen Sie sicher, dass get_facebook_product_feed_data existiert und funktioniert
    if ( function_exists( 'get_facebook_product_feed_data' ) ) {
        $facebook_feed_data = get_facebook_product_feed_data();
        if ( ! empty( $facebook_feed_data ) ) {
            // Stellen Sie sicher, dass write_facebook_product_feed_csv existiert und funktioniert
            if ( function_exists( 'write_facebook_product_feed_csv' ) ) {
                write_facebook_product_feed_csv( $facebook_feed_data, 'facebook_product_feed.csv' );
            } else {
                // Hier könnte ein Log-Eintrag eingefügt werden, falls die Funktion fehlt
                error_log('Error: write_facebook_product_feed_csv function not found.');
            }
        }
    } else {
        // Hier könnte ein Log-Eintrag eingefügt werden, falls die Funktion fehlt
        error_log('Error: get_facebook_product_feed_data function not found.');
    }
}

add_action( 'woo_shopify_feed_export_daily', 'run_shopify_feed_export_scheduled' );
function run_shopify_feed_export_scheduled() {
    // Start Logging für diesen spezifischen Cron-Lauf
    woo_exporter_log_shopify_import( 'Shopify Export (JSONL/CSV) und Import (API) gestartet.', 'info' );

    // Annahme: get_all_woocommerce_products_with_variations(31) ist in einer anderen Datei definiert
    // und gibt die benötigten Daten zurück.
    $shopify_data = get_all_woocommerce_products_with_variations(31);

    if ( ! empty( $shopify_data ) ) {
        // --- Shopify API Import (JSONL) ---
        $jsonl_filename = 'shopify_products_' . date('Ymd_His') . '.jsonl'; // Eindeutiger Dateiname
        $jsonl_filepath = write_shopify_products_jsonl( $shopify_data, $jsonl_filename );

        if ( is_wp_error( $jsonl_filepath ) ) {
            woo_exporter_log_shopify_import( 'Fehler beim Generieren der JSONL-Datei: ' . $jsonl_filepath->get_error_message(), 'error' );
            return;
        }
        if ( ! $jsonl_filepath || ! file_exists( $jsonl_filepath ) ) {
            woo_exporter_log_shopify_import( 'JSONL-Datei konnte nicht generiert oder gefunden werden.', 'error' );
            return;
        }

        $file_size = filesize( $jsonl_filepath );
        if ( $file_size === false ) {
            woo_exporter_log_shopify_import( 'Dateigröße der JSONL-Datei konnte nicht ermittelt werden.', 'error' );
            return;
        }

        // Schritt 1: Staged Upload in Shopify erstellen
        $staged_upload_data = woo_exporter_shopify_create_staged_upload( $jsonl_filename, $file_size, 'application/jsonl' );
        if ( is_wp_error( $staged_upload_data ) ) {
            woo_exporter_log_shopify_import( 'Fehler bei stagedUploadsCreate: ' . $staged_upload_data->get_error_message(), 'error' );
            return;
        }

        // *** HIER IST DIE HINZUGEFÜGTE DEBUG-LOG-ZEILE ***
        woo_exporter_log_shopify_import( 'Shopify Staged Upload Data (vollständig) von Shopify empfangen: ' . print_r($staged_upload_data, true), 'debug' );
        // *************************************************

        // Schritt 2: JSONL-Datei zu Shopify hochladen
        // Annahme: woo_exporter_shopify_upload_file ist in einer anderen Datei definiert
        // und wurde bereits mit der cURL-Implementierung aktualisiert.
        $upload_success = woo_exporter_shopify_upload_file( $staged_upload_data, $jsonl_filepath );
        if ( is_wp_error( $upload_success ) ) {
            woo_exporter_log_shopify_import( 'Fehler beim Hochladen der JSONL-Datei: ' . $upload_success->get_error_message(), 'error' );
            return;
        }

        // Schritt 3: Shopify Bulk Operation triggern
        // Annahme: woo_exporter_shopify_trigger_bulk_import ist in einer anderen Datei definiert
        $bulk_operation_id = woo_exporter_shopify_trigger_bulk_import( $staged_upload_data['resourceUrl'] );
        if ( is_wp_error( $bulk_operation_id ) ) {
            woo_exporter_log_shopify_import( 'Fehler beim Triggern der Bulk Operation: ' . $bulk_operation_id->get_error_message(), 'error' );
            return;
        }

        woo_exporter_log_shopify_import( 'Shopify Bulk Import Workflow erfolgreich gestartet! Job ID: ' . $bulk_operation_id, 'info' );

        // --- Shopify CSV Export (für manuellen Import / Backup) ---
        // Generiert die CSV-Datei mit den Shopify-spezifischen Daten
        // Annahme: write_shopify_product_feed_csv ist in einer anderen Datei definiert
        $file_url_csv = write_shopify_product_feed_csv( $shopify_data, 'shopify_product_feed.csv' );
        if ( $file_url_csv ) {
            woo_exporter_log_shopify_import( 'Shopify CSV (manuell / Backup) erfolgreich generiert: ' . $file_url_csv, 'info' );
        } else {
            woo_exporter_log_shopify_import( 'Fehler beim Generieren der Shopify CSV (manuell / Backup).', 'error' );
        }

    } else {
        woo_exporter_log_shopify_import( 'Keine Produktdaten für den Shopify Export (JSONL/CSV) gefunden.', 'info' );
    }
    woo_exporter_log_shopify_import( 'Shopify Export und Import beendet.', 'info' );
}


// Optional: Deaktivieren der Planung beim Deaktivieren des Plugins
register_deactivation_hook( __FILE__, 'deactivate_all_export_schedules' );
function deactivate_all_export_schedules() {
    $timestamp_fb = wp_next_scheduled( 'woo_facebook_feed_export_hourly' );
    if ( $timestamp_fb ) {
        wp_unschedule_event( $timestamp_fb, 'hourly', 'woo_facebook_feed_export_hourly' );
    }
    $timestamp_shopify = wp_next_scheduled( 'woo_shopify_feed_export_daily' );
    if ( $timestamp_shopify ) {
        wp_unschedule_event( $timestamp_shopify, 'daily', 'woo_shopify_feed_export_daily' );
    }
}