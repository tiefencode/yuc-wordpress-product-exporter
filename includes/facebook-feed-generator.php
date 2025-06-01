<?php
/**
 * Funktionen zum Generieren des Facebook Product Feeds.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Funktion zum Abrufen und Formatieren der WooCommerce-Produktdaten für den Facebook Product Feed.
 *
 * @return array Das Array mit den für Facebook formatierten Produktdaten.
 */
function get_facebook_product_feed_data() {
    // Ruft alle Produktdaten inklusive Variationen ab
    // Die Kategorie-ID 31 wird hier an die Basis-Funktion übergeben
    $products_data = get_all_woocommerce_products_with_variations(31);
    $facebook_data = [];

    foreach ($products_data as $product) {
        // Allgemeine Basisdaten, die für Hauptprodukte und deren Variationen gelten
        $base_common_data = [
            'description'     => str_replace(["\n", "\r"], '', html_entity_decode(strip_tags($product['description']), ENT_QUOTES, 'UTF-8')),
            'condition'       => 'new',
            'brand'           => get_bloginfo('name'),
            'identifier_exists' => 'FALSE',
            'google_product_category' => 'Apparel & Accessories > Clothing', // TODO: Anpassen an deine Produkte oder ein Mapping vornehmen
            'product_type'    => implode(' > ', $product['categories']),
        ];

        // Behandle zuerst einfache Produkte
        if ($product['type'] === 'simple') {
            $facebook_entry = array_merge($base_common_data, [
                'id'             => $product['id'],
                'title'          => html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8'),
                'availability'   => ($product['stock_status'] === 'instock') ? 'in stock' : (($product['stock_status'] === 'onbackorder') ? 'available_for_order' : 'out of stock'),
                'price'          => 'EUR ' . number_format($product['price'], 2, '.', ''),
                'link'           => $product['permalink'] . '&utm_source=Meta%20/%20Facebook%20Catalog%20Feed%20/%20Instagram&utm_campaign=Facebook%20Insta%20Feed%20NEW&utm_medium=cpc&utm_term=' . $product['id'],
                'image_link'     => $product['image_url'],
                // 'item_group_id' wurde für eine flache Struktur entfernt
            ]);
            $facebook_data[] = $facebook_entry;

            // Dann behandle variable Produkte, indem wir jede Variation als separates Produkt auflisten
        } elseif ($product['type'] === 'variable' && !empty($product['variations'])) {
            // Holen des übergeordneten Produktobjekts einmal pro variablem Produkt
            $parent_product_title = html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8');
            $parent_product_obj = wc_get_product($product['id']);

            if ( !$parent_product_obj || !$parent_product_obj->is_type('variable') ) {
                // Dies sollte nicht passieren, wenn $product['type'] === 'variable' ist,
                // aber eine zusätzliche Prüfung schadet nicht für die Robustheit.
                continue;
            }

            foreach ($product['variations'] as $variation) {
                // Holen des Variationsobjekts für Details
                $variation_obj = wc_get_product($variation['id']);

                // Zusätzliche Prüfung, ob $variation_obj wirklich ein WC_Product_Variation Objekt ist
                if ( !$variation_obj || !$variation_obj->is_type('variation') ) {
                    error_log('WooCommerce Exporter: Variation object could not be loaded for ID: ' . $variation['id'] . ' or is not a variation type.');
                    continue; // Überspringen, wenn die Variation nicht korrekt geladen werden kann
                }

                $facebook_entry = array_merge($base_common_data, [
                    'id'             => $variation['id'],
                    'availability'   => ($variation['stock_status'] === 'instock') ? 'in stock' : (($variation['stock_status'] === 'onbackorder') ? 'available_for_order' : 'out of stock'),
                    'price'          => 'EUR ' . number_format($variation['price'], 2, '.', ''),
                    'link'           => add_query_arg('variation_id', $variation['id'], $product['permalink']) . '&utm_source=Meta%20/%20Facebook%20Catalog%20Feed%20/%20Instagram&utm_campaign=Facebook%20Insta%20Feed%20NEW&utm_medium=cpc&utm_term=' . $variation['id'],
                    'image_link'     => $variation['image_url'] ?: $product['image_url'],
                    // 'item_group_id' wurde für eine flache Struktur entfernt
                ]);

                // Anpassung der Titel-Generierung für Varianten
                $variant_title_parts = [$parent_product_title]; // Start mit dem Hauptprodukt-Titel

                // Zugriff auf die lesbaren Attribut-Werte
                $attributes_display_string = [];

                // Get variation attributes directly from the WC_Product_Variation object
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

                // Die Attributwerte direkt an den Titel anfügen
                if (!empty($attributes_display_string)) {
                    $variant_title_parts = array_merge($variant_title_parts, $attributes_display_string);
                }

                $facebook_entry['title'] = implode(' ', $variant_title_parts);

                $facebook_data[] = $facebook_entry;
            }
        }
    }

    return $facebook_data;
}


/**
 * Funktion zum Schreiben der Facebook Product Feed-Daten in eine CSV-Datei.
 *
 * @param array  $data     Das Array mit den für Facebook formatierten Produktdaten.
 * @param string $filename Der Dateiname für die CSV-Datei (z.B. 'facebook_product_feed.csv').
 * @return string|false Die URL der generierten CSV-Datei bei Erfolg, false bei Fehler.
 */
function write_facebook_product_feed_csv( $data, $filename = 'facebook_product_feed.csv' ) {
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
        // Fehlerbehandlung: Kann Datei nicht öffnen/erstellen
        error_log( "WooCommerce Exporter: Could not open/create file for writing at " . $filepath );
        return false;
    }

    // CSV-Header schreiben - muss exakt der Facebook-Spezifikation entsprechen
    // 'item_group_id' wurde entfernt, da eine flache Produktstruktur gewünscht ist
    $header = [
        'id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand',
        'identifier_exists', 'google_product_category', 'product_type'
    ];
    // UTF-8 BOM hinzufügen, um CSV-Kompatibilität in Excel/Texteditoren zu verbessern
    fwrite($file, "\xEF\xBB\xBF");
    fputcsv( $file, $header, ';'); // Semikolon als Trennzeichen

    // Produktdaten schreiben
    foreach ( $data as $row ) {
        // Sicherstellen, dass alle Header-Spalten vorhanden sind und in der richtigen Reihenfolge
        $csv_row = [];
        foreach ($header as $column_name) {
            $csv_row[] = isset($row[$column_name]) ? $row[$column_name] : ''; // Leeren String, falls Spalte fehlt
        }
        fputcsv( $file, $csv_row, ';'); // Semikolon als Trennzeichen
    }

    fclose( $file );

    // Stelle sicher, dass die Datei web-zugänglich ist (manchmal sind Berechtigungen ein Problem)
    @chmod( $filepath, 0664 );

    return $file_url;
}