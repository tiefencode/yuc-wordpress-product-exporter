<?php
/**
 * Funktionen zum Generieren des Shopify CSV-Exports.
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
    $products_data = get_all_woocommerce_products_with_variations(31); // Annahme: Auch hier Kategorie 31
    $shopify_data = [];

    // Mapping für Shopify Standardized Product Types
    $shopify_product_category_mapping = [
        'Vinyl' => 'Media > Music & Sound Recordings > Music Albums',
        'CD' => 'Media > Music & Sound Recordings > Music Albums',
        'Tape' => 'Media > Music & Sound Recordings > Music Albums', // Für Kassetten
        'Shirt' => 'Apparel & Accessories > Clothing > Shirts & Tops',
        'Merchandise' => 'Apparel & Accessories', // Generisch für sonstiges Merch
        // Fallback-Kategorie, wenn nichts Passendes gefunden wird
        'Default' => 'Arts & Entertainment > Hobbies & Creative Arts > Collectibles'
    ];

    foreach ($products_data as $product) {
        // Die Beschreibung für Shopify sollte die des Hauptprodukts sein
        $product_description_html = wpautop($product['description']); // HTML-Tags für Shopify, ggf. mit wpautop für Absätze
        $product_description_html = html_entity_decode($product_description_html, ENT_QUOTES, 'UTF-8');

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
        $parent_product_title = html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8');

        foreach ($items_to_process as $item) {
            $is_variation = isset($item['parent_id']); // Prüfen, ob es eine Variation ist

            // Shopify Handle Generierung (KORRIGIERT UND VEREINFACHT)
            // Nutzt den Slug, der bereits in item['slug'] gespeichert ist.
            $handle = $item['slug'];
            $handle = sanitize_title($handle);

            // --- START: ANPASSUNG DER TITEL-GENERIERUNG ---
            $title = $parent_product_title; // Start mit Hauptprodukt-Titel

            if ($is_variation) {
                $variation_obj = wc_get_product($item['id']);
                if ($variation_obj && $variation_obj->is_type('variation')) {
                    $attributes_display_string = [];
                    $variation_attributes = $variation_obj->get_variation_attributes();

                    if (!empty($variation_attributes)) {
                        foreach ($variation_attributes as $attribute_taxonomy_slug => $term_slug) {
                            // Den Taxonomie-Slug säubern, um das 'attribute_' Präfix zu entfernen
                            $actual_taxonomy_name = str_replace('attribute_', '', $attribute_taxonomy_slug);

                            $term_object = get_term_by('slug', $term_slug, $actual_taxonomy_name);

                            if ($term_object && !is_wp_error($term_object)) {
                                // Wenn es ein globales Attribut ist, den lesbaren Namen verwenden
                                $attributes_display_string[] = $term_object->name;
                            } else {
                                // Fallback für benutzerdefinierte Attribute oder wenn Term nicht gefunden
                                $attributes_display_string[] = $term_slug;
                            }
                        }
                    }
                    // Füge die Attributwerte direkt an den Titel an
                    if (!empty($attributes_display_string)) {
                        $title .= ' ' . implode(' ', $attributes_display_string);
                    }
                }
            }
            // --- ENDE: ANPASSUNG DER TITEL-GENERIERUNG ---


            // --- START Anpassung der Shopify Product Type (Type-Spalte) Logik ---
            $shopify_product_type = '';

            // Priorität 1: Prüfen, ob "Shirt" im Titel vorkommt (hat höhere Priorität)
            if (stripos($title, 'shirt') !== false) {
                $shopify_product_type = 'Shirt';
            } else {
                // Priorität 2: Attribut 'pa_sound-carrier' direkt von der VARIATION holen
                $item_object = wc_get_product( $item['id'] ); // Holen des WC_Product_Variation Objekts
                if ( $item_object && $item_object->is_type('variation') ) {
                    $item_attributes = $item_object->get_variation_attributes();
                    if (isset($item_attributes['attribute_pa_sound-carrier']) && !empty($item_attributes['attribute_pa_sound-carrier'])) {
                        $shopify_product_type = ucfirst($item_attributes['attribute_pa_sound-carrier']);
                    }
                }

                // Priorität 3: Fallback auf das Hauptprodukt, falls die Variation kein spezifisches Attribut hat
                if (empty($shopify_product_type)) {
                    $sound_carrier_terms = wp_get_post_terms( $product['id'], 'pa_sound-carrier', array( 'fields' => 'names' ) );
                    if ( ! empty( $sound_carrier_terms ) && is_array( $sound_carrier_terms ) ) {
                        // Nehmen wir an, es gibt nur einen Sound Carrier pro Produkt, sonst muss die Logik hier präziser werden
                        $shopify_product_type = $sound_carrier_terms[0]; // Ersten Term verwenden
                    }
                }
            }
            // --- ENDE Anpassung der Shopify Product Type (Type-Spalte) Logik ---

            // *** ANPASSUNG HIER: Setze 'Status' auf 'draft', wenn Produkt ausverkauft ist ***
            $product_status_shopify = 'active'; // Standardmäßig aktiv
            if ($item['stock_status'] === 'outofstock' || ($item['stock_quantity'] !== null && $item['stock_quantity'] <= 0)) {
                $product_status_shopify = 'draft'; // Produkt ist ausverkauft, also als Entwurf speichern
            }

            // Der "Published" Status sollte weiterhin 'TRUE' sein, da "Status: draft" dies steuert.
            // Wenn ein Produkt "draft" ist, wird es nicht "published" sein in Shopify, unabhängig von diesem Feld.
            $published_status = 'TRUE'; // Shopify versteht 'active', 'archived', 'draft' besser für den Status


            // --- START Anpassung des Produktgewichts ---
            $variant_grams = ''; // Standardmäßig leer oder basierend auf tatsächlichem Gewicht
            // Fallunabhängige Prüfung ob der Typ "Vinyl" enthält
            if ( stripos( $shopify_product_type, 'Vinyl' ) !== false ) {
                $variant_grams = 1000; // 1000g für Vinyl
            } else {
                $variant_grams = 100; // 100g für alle anderen Produkte
            }
            // --- ENDE Anpassung des Produktgewichts ---


            // Shopify Standardized Product Category (Product Category Spalte)
            $standardized_category = $shopify_product_category_mapping['Default']; // Default-Wert
            if (!empty($shopify_product_type) && isset($shopify_product_category_mapping[$shopify_product_type])) {
                $standardized_category = $shopify_product_category_mapping[$shopify_product_type];
            }

            // *** START: Anpassung der Bild-Logik für Varianten (KORRIGIERT) ***
            $image_src = '';
            if ($is_variation) {
                $variation_object = wc_get_product($item['id']);
                // Korrigiert: Prüfen, ob eine Bild-ID für die Variation existiert.
                // WC_Product_Variation::get_image_id() gibt 0 zurück, wenn kein Bild gesetzt ist.
                if ($variation_object && $variation_object->get_image_id() !== 0) {
                    $image_id = $variation_object->get_image_id();
                    $image_src = wp_get_attachment_url($image_id);
                } else {
                    // Fallback zum Hauptproduktbild, wenn Variante kein eigenes Bild hat
                    $image_src = $product['image_url'];
                }
            } else {
                // Für einfache Produkte das Hauptproduktbild verwenden
                $image_src = $product['image_url'];
            }
            // *** ENDE: Anpassung der Bild-Logik für Varianten ***

            // --- START: Inventory Quantity Logic ---
            $variant_inventory_qty = 0; // Default auf 0, falls nicht instock oder verwaltet
            if ($item['stock_status'] === 'instock' && $item['stock_quantity'] !== null && $item['stock_quantity'] > 0) {
                $variant_inventory_qty = $item['stock_quantity'];
            }
            // --- ENDE: Inventory Quantity Logic ---

            $shopify_entry = [
                'id'                          => $item['id'],
                'Handle'                      => $handle,
                'Title'                       => $title,
                'Body (HTML)'                 => $product_description_html,
                'Vendor'                      => get_bloginfo('name'),
                'Standardized Product Type'   => $standardized_category,
                'Type'                        => $shopify_product_type,
                'Tags'                        => implode(', ', $product['tags']),
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
                'Image Alt Text'              => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                'Gift Card'                   => 'FALSE',
                'SEO Title'                   => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                'SEO Description'             => html_entity_decode($product['short_description'], ENT_QUOTES, 'UTF-8'),
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
    $dir_path   = $upload_dir['basedir'] . '/woo_product_exports/'; // Eigener Unterordner für Exporte
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
        'id', // Behalten wir bei, da du es brauchst
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
        // 'Gift Card' entfernt
        'SEO Title',
        'SEO Description',
        // 'Google Shopping / ...' Spalten entfernt
        // 'Variant Image' entfernt
        'Variant Weight Unit',
        'Variant Inventory Tracker',
        'Cost per item', // Behalten wir bei, falls du es doch irgendwann nutzen möchtest
        'Status',
        'Variant Inventory Qty'
    ];

    // UTF-8 BOM hinzufügen
    fwrite($file, "\xEF\xBB\xBF");
    fputcsv( $file, $header, ','); // Shopify verwendet standardmäßig Komma als Trennzeichen!

    foreach ( $data as $row ) {
        $csv_row = [];
        foreach ($header as $column_name) {
            $csv_row[] = isset($row[$column_name]) ? $row[$column_name] : '';
        }
        fputcsv( $file, $csv_row, ','); // Shopify verwendet Komma als Trennzeichen!
    }

    fclose( $file );
    @chmod( $filepath, 0664 );

    return $file_url;
}