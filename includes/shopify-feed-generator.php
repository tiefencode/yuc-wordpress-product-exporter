<?php
/**
 * Funktionen zum Generieren des Shopify CSV-Exports.
 *
 * Dieses Plugin exportiert WooCommerce Produktdaten in ein Shopify-kompatibles CSV-Format.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Funktion zum Abrufen und Formatieren der WooCommerce-Produktdaten für den Shopify CSV-Export.
 *
 * @return array Das Array mit den für Shopify formatierten Produktdaten.
 */
function get_shopify_product_feed_data() {
    // Annahme: get_all_woocommerce_products_with_variations ist in einer anderen Datei definiert
    // und liefert Produkte inklusive deren Variationen.
    $products_data = get_all_woocommerce_products_with_variations(31);
    $shopify_data = [];

    // Mapping für Shopify Standardized Product Types
    $shopify_product_category_mapping = [
        'Vinyl'       => 'Media > Music & Sound Recordings > Music Albums',
        'CD'          => 'Media > Music & Sound Recordings > Music Albums',
        'Tape'        => 'Media > Music & Sound Recordings > Music Albums', // Für Kassetten
        'Shirt'       => 'Apparel & Accessories > Clothing > Shirts & Tops',
        'Merchandise' => 'Apparel & Accessories', // Generisch für sonstiges Merch
        // Fallback-Kategorie, wenn nichts Passendes gefunden wird
        'Default'     => 'Arts & Entertainment > Hobbies & Creative Arts > Collectibles'
    ];

    foreach ($products_data as $product) {
        // Die Beschreibung für Shopify sollte die des Hauptprodukts sein
        $product_description_html = wpautop($product['description']); // HTML-Tags für Shopify, ggf. mit wpautop für Absätze
        $product_description_html = html_entity_decode($product_description_html, ENT_QUOTES, 'UTF-8');

        // Die Kurz-Beschreibung für SEO Description
        $product_short_description_html = wpautop($product['short_description']);
        $product_short_description_html = html_entity_decode($product_short_description_html, ENT_QUOTES, 'UTF-8');

        // Für jede "flachgeklopfte" Variante (einfache Produkte und Variationen)
        $items_to_process = [];
        if ($product['type'] === 'variable' && !empty($product['variations'])) {
            foreach ($product['variations'] as $variation) {
                $items_to_process[] = $variation;
            }
        } else {
            $items_to_process[] = $product; // Einfaches Produkt
        }

        // Parent-Produktobjekt für Attribut-Abfragen
        $parent_product_obj = wc_get_product($product['id']);
        $parent_product_title_original = html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'); // Originaltitel behalten

        // --- START: Lieferzeit-Logik für das Hauptprodukt (angepasst für Taxonomie oder Metafeld) ---
        $delivery_time_from_woocommerce = '';
        $custom_delivery_time = '3 - 5 Werktage'; // Standardwert für Shopify Custom Field
        $product_tags_array = $product['tags']; // Bestehende Tags als Array
        $is_preorder = false; // Flag, um zu prüfen, ob es eine Vorbestellung ist

        // Versuch 1: Als globales Produktattribut (Taxonomie) 'product_delivery_time' abrufen
        $delivery_taxonomy_name = 'product_delivery_time';
        $delivery_terms = wp_get_post_terms($product['id'], $delivery_taxonomy_name, array('fields' => 'names'));

        if (!empty($delivery_terms) && !is_wp_error($delivery_terms)) {
            $delivery_time_from_woocommerce = $delivery_terms[0];
        } else {
            // Fallback: Wenn keine Taxonomie-Begriffe gefunden wurden, versuchen wir es als Metafeld
            $delivery_meta_key = 'product_delivery_time';
            $delivery_time_from_woocommerce = get_post_meta($product['id'], $delivery_meta_key, true);
        }

        $trimmed_delivery_time = trim($delivery_time_from_woocommerce);

        if (!empty($trimmed_delivery_time) && $trimmed_delivery_time === 'Vorbestellung / Preorder') {
            $custom_delivery_time = 'Vorbestellung'; // Der Wert, der in Shopify angezeigt werden soll
            $is_preorder = true; // Setze das Preorder-Flag
            // Füge "preorder" zum Tag-Array hinzu, falls noch nicht vorhanden
            if (!in_array('preorder', $product_tags_array)) {
                $product_tags_array[] = 'preorder';
            }
        }
        // --- ENDE: Lieferzeit-Logik für das Hauptprodukt ---

        // --- START: Pods Release Date Logik ---
        // Der korrigierte Meta-Key für das Pods-Feld "release_date"
        $pods_release_date_meta_key = 'release_date';
        $release_date_from_pods = get_post_meta($product['id'], $pods_release_date_meta_key, true);
        $custom_release_date = ''; // Standardmäßig leer

        if (!empty($release_date_from_pods)) {
            try {
                $date_obj = new DateTime($release_date_from_pods);
                $custom_release_date = $date_obj->format('Y-m-d'); // Empfohlenes Format für Shopify
            } catch (Exception $e) {
                // Bei Fehlern im Datumsformat einfach den Rohwert übernehmen
                $custom_release_date = $release_date_from_pods;
            }
        }
        // --- ENDE: Pods Release Date Logik ---


        foreach ($items_to_process as $item) {
            $is_variation = isset($item['parent_id']); // Prüfen, ob es eine Variation ist

            // Shopify Handle Generierung
            $handle = $item['slug'];
            $handle = sanitize_title($handle);

            // --- START: ANPASSUNG DER TITEL-GENERIERUNG (INKL. PREORDER-PRÄFIX OHNE DOPPELPUNKT) ---
            $current_item_title = $parent_product_title_original; // Beginne mit dem Originaltitel

            if ($is_preorder) {
                $current_item_title = 'PREORDER ' . $current_item_title; // Füge "Preorder " ohne Doppelpunkt hinzu
            }

            if ($is_variation) {
                $variation_obj = wc_get_product($item['id']);
                if ($variation_obj && $variation_obj->is_type('variation')) {
                    $attributes_display_string = [];
                    $variation_attributes = $variation_obj->get_variation_attributes();

                    if (!empty($variation_attributes)) {
                        foreach ($variation_attributes as $attribute_taxonomy_slug => $term_slug) {
                            $actual_taxonomy_name = str_replace('attribute_', '', $attribute_taxonomy_slug);

                            $term_object = get_term_by('slug', $term_slug, $actual_taxonomy_name);

                            if ($term_object && !is_wp_error($term_object)) {
                                $attributes_display_string[] = $term_object->name;
                            } else {
                                $attributes_display_string[] = $term_slug;
                            }
                        }
                    }
                    if (!empty($attributes_display_string)) {
                        $current_item_title .= ' ' . implode(' ', $attributes_display_string);
                    }
                }
            }
            // --- ENDE: ANPASSUNG DER TITEL-GENERIERUNG ---


            // --- START Anpassung der Shopify Product Type (Type-Spalte) Logik ---
            $shopify_product_type = '';
            if (stripos($current_item_title, 'shirt') !== false) {
                $shopify_product_type = 'Shirt';
            } else {
                $item_object = wc_get_product( $item['id'] );
                if ( $item_object && $item_object->is_type('variation') ) {
                    $item_attributes = $item_object->get_variation_attributes();
                    if (isset($item_attributes['attribute_pa_sound-carrier']) && !empty($item_attributes['attribute_pa_sound-carrier'])) {
                        $shopify_product_type = ucfirst($item_attributes['attribute_pa_sound-carrier']);
                    }
                }
                if (empty($shopify_product_type)) {
                    $sound_carrier_terms = wp_get_post_terms( $product['id'], 'pa_sound-carrier', array( 'fields' => 'names' ) );
                    if ( ! empty( $sound_carrier_terms ) && is_array( $sound_carrier_terms ) ) {
                        $shopify_product_type = $sound_carrier_terms[0];
                    }
                }
            }
            // --- ENDE Anpassung der Shopify Product Type (Type-Spalte) Logik ---

            // *** Setze 'Status' auf 'draft', wenn Produkt ausverkauft ist ***
            $product_status_shopify = 'active';
            if ($item['stock_status'] === 'outofstock' || ($item['stock_quantity'] !== null && $item['stock_quantity'] <= 0)) {
                $product_status_shopify = 'draft';
            }
            $published_status = 'TRUE';


            // --- START Anpassung des Produktgewichts ---
            $variant_grams = '';
            if ( stripos( $shopify_product_type, 'Vinyl' ) !== false ) {
                $variant_grams = 1000;
            } else {
                $variant_grams = 100;
            }
            // --- ENDE Anpassung des Produktgewichts ---


            // Shopify Standardized Product Category
            $standardized_category = $shopify_product_category_mapping['Default'];
            if (!empty($shopify_product_type) && isset($shopify_product_category_mapping[$shopify_product_type])) {
                $standardized_category = $shopify_product_category_mapping[$shopify_product_type];
            }

            // *** START: Anpassung der Bild-Logik für Varianten ***
            $image_src = '';
            if ($is_variation) {
                $variation_object = wc_get_product($item['id']);
                if ($variation_object && $variation_object->get_image_id() !== 0) {
                    $image_id = $variation_object->get_image_id();
                    $image_src = wp_get_attachment_url($image_id);
                } else {
                    $image_src = $product['image_url'];
                }
            } else {
                $image_src = $product['image_url'];
            }
            // *** ENDE: Anpassung der Bild-Logik für Varianten ***

            // --- START: Inventory Quantity Logic ---
            $variant_inventory_qty = 0;
            if ($item['stock_status'] === 'instock' && $item['stock_quantity'] !== null && $item['stock_quantity'] > 0) {
                $variant_inventory_qty = $item['stock_quantity'];
            }
            // --- ENDE: Inventory Quantity Logic ---

            $shopify_entry = [
                'id'                          => $item['id'],
                'Handle'                      => $handle,
                'Title'                       => $current_item_title,
                'Body (HTML)'                 => $product_description_html,
                'Vendor'                      => get_bloginfo('name'),
                'Standardized Product Type'   => $standardized_category,
                'Type'                        => $shopify_product_type,
                'Tags'                        => implode(', ', $product_tags_array),
                'Published'                   => $published_status,
                'Option1 Name'                => '',
                'Option1 Value'               => '',
                'Option2 Name'                => '',
                'Option2 Value'               => '',
                'Option3 Name'                => '',
                'Option3 Value'               => '',
                'Variant SKU'                 => $item['sku'],
                'Variant Grams'               => $variant_grams,
                'Variant Inventory Policy'    => 'deny',
                'Variant Fulfillment Service' => 'manual',
                'Variant Price'               => number_format($item['price'], 2, '.', ''),
                'Variant Compare At Price'    => ($item['sale_price'] && $item['regular_price'] > $item['sale_price']) ? number_format($item['regular_price'], 2, '.', '') : '',
                'Variant Requires Shipping'   => 'TRUE',
                'Variant Taxable'             => 'TRUE',
                'Variant Barcode'             => '',
                'Image Src'                   => $image_src,
                'Image Position'              => 1,
                'Image Alt Text'              => html_entity_decode($current_item_title, ENT_QUOTES, 'UTF-8'),
                'Gift Card'                   => 'FALSE',
                'SEO Title'                   => html_entity_decode($parent_product_title_original, ENT_QUOTES, 'UTF-8'),
                'SEO Description'             => $product_short_description_html,
                'Google Shopping / Google Product Category' => '',
                'Google Shopping / Gender'    => '',
                'Google Shopping / Age Group' => '',
                'Google Shopping / MPN'       => '',
                'Google Shopping / Condition' => 'new',
                'Google Shopping / Custom Product' => '',
                'Google Shopping / Custom Label 0' => '',
                'Google Shopping / Custom Label 1' => '',
                'Google Shopping / Custom Label 2' => '',
                'Google Shopping / Custom Label 3' => '',
                'Google Shopping / Custom Label 4' => '',
                'Variant Image'               => '',
                'Variant Weight Unit'         => 'g',
                'Variant Inventory Tracker'   => 'shopify',
                'Cost per item'               => '',
                'Status'                      => $product_status_shopify,
                'Variant Inventory Qty'       => $variant_inventory_qty,
                // --- NEUE CUSTOM-FELDER HINZUFÜGEN ---
                'custom.delivery_time'        => $custom_delivery_time,
                'custom.release_date'         => $custom_release_date,
            ];

            $shopify_data[] = $shopify_entry;
        }
    }

    return $shopify_data;
}


/**
 * Funktion zum Schreiben der Shopify Product Feed-Daten in eine CSV-Datei.
 *
 * @param array  $data     Das Array mit den für Shopify formatierten Produktdaten.
 * @param string $filename Der Dateiname für die CSV-Datei (z.B. 'shopify_product_feed.csv').
 * @return string|false Die URL der generierten CSV-Datei bei Erfolg, false bei Fehler.
 */
function write_shopify_product_feed_csv( $data, $filename = 'shopify_product_feed.csv' ) {
    $upload_dir = wp_upload_dir();
    $dir_path   = $upload_dir['basedir'] . '/woo_product_exports/';
    $file_url   = $upload_dir['baseurl'] . '/woo_product_exports/' . $filename;
    $filepath   = $dir_path . $filename;

    // Sicherstellen, dass der Export-Ordner existiert
    if ( ! is_dir( $dir_path ) ) {
        wp_mkdir_p( $dir_path );
    }

    $file = fopen( $filepath, 'w' );
    if ( ! $file ) {
        error_log( "WooCommerce Exporter: Could not open/create Shopify file for writing at " . $filepath );
        return false;
    }

    // Shopify CSV Header - muss exakt der Shopify-Import-Spezifikation entsprechen
    $header = [
        'id',
        'Handle',
        'Title',
        'Body (HTML)',
        'Vendor',
        'Standardized Product Type',
        'Type',
        'Tags',
        'Published',
        'Option1 Name',
        'Option1 Value',
        'Option2 Name',
        'Option2 Value',
        'Option3 Name',
        'Option3 Value',
        'Variant SKU',
        'Variant Grams',
        'Variant Inventory Policy',
        'Variant Fulfillment Service',
        'Variant Price',
        'Variant Compare At Price',
        'Variant Requires Shipping',
        'Variant Taxable',
        'Variant Barcode',
        'Image Src',
        'Image Position',
        'Image Alt Text',
        'SEO Title',
        'SEO Description',
        'Google Shopping / Google Product Category',
        'Google Shopping / Gender',
        'Google Shopping / Age Group',
        'Google Shopping / MPN',
        'Google Shopping / Condition',
        'Google Shopping / Custom Product',
        'Google Shopping / Custom Label 0',
        'Google Shopping / Custom Label 1',
        'Google Shopping / Custom Label 2',
        'Google Shopping / Custom Label 3',
        'Google Shopping / Custom Label 4',
        'Variant Image',
        'Variant Weight Unit',
        'Variant Inventory Tracker',
        'Cost per item',
        'Status',
        'Variant Inventory Qty',
        // --- NEUE CUSTOM-FELDER HINZUFÜGEN ZUM HEADER ---
        'custom.delivery_time',
        'custom.release_date',
    ];

    // UTF-8 BOM hinzufügen
    fwrite($file, "\xEF\xBB\xBF");
    fputcsv( $file, $header, ',');

    foreach ( $data as $row ) {
        $csv_row = [];
        foreach ($header as $column_name) {
            $csv_row[] = isset($row[$column_name]) ? $row[$column_name] : '';
        }
        fputcsv( $file, $csv_row, ',');
    }

    fclose( $file );
    @chmod( $filepath, 0664 );

    return $file_url;
}