<?php
/**
 * Funktionen zur Interaktion mit der Shopify Admin GraphQL API für Produktimporte.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Pfad für die speziellen Import-Logs definieren
define( 'WOO_EXPORTER_LOG_DIR', WP_CONTENT_DIR . '/woo-exporter-logs/' );

/**
 * Schreibt eine Nachricht in eine spezifische Shopify-Import-Log-Datei.
 * Eine neue Datei wird pro Bulk-Import-Lauf erstellt.
 *
 * @param string $message Die zu protokollierende Nachricht.
 * @param string $type Der Typ der Nachricht (info, error, warning, debug).
 */
function woo_exporter_log_shopify_import( $message, $type = 'info' ) {
    // Sicherstellen, dass der Log-Ordner existiert
    if ( ! is_dir( WOO_EXPORTER_LOG_DIR ) ) {
        wp_mkdir_p( WOO_EXPORTER_LOG_DIR );
        // NEU: .htaccess Datei erstellen, um den Ordner zu schützen
        $htaccess_content = "Deny from all\n";
        file_put_contents( WOO_EXPORTER_LOG_DIR . '.htaccess', $htaccess_content );
    }

    // Dateiname basierend auf dem Startzeitpunkt des täglichen Shopify-Cron-Jobs
    // Um sicherzustellen, dass alle Logs eines Laufs in der gleichen Datei landen.
    static $log_file_prefix = null;
    if ( $log_file_prefix === null ) {
        $log_file_prefix = current_time( 'Y-m-d_H-i-s' );
    }

    $log_filename = "{$log_file_prefix}_shopify_bulk_import.log";
    $log_filepath = WOO_EXPORTER_LOG_DIR . $log_filename;

    // Erzeugen der Log-Zeile
    $timestamp_line = '[' . current_time( 'mysql' ) . '] ';
    $type_line = strtoupper( $type ) . ': ';
    file_put_contents( $log_filepath, $timestamp_line . $type_line . $message . "\n", FILE_APPEND | LOCK_EX );
}

/**
 * Führt eine GraphQL-Anfrage an die Shopify Admin API aus.
 *
 * @param string $query Die GraphQL-Abfrage oder Mutation.
 * @param array  $variables Optionale Variablen für die Abfrage.
 * @return array|WP_Error Die API-Antwort als Array oder ein WP_Error Objekt bei Fehlern.
 */
function woo_exporter_shopify_graphql_request( $query, $variables = [] ) {
    $store_url = get_option( 'woo_exporter_shopify_store_url' );
    $access_token = get_option( 'woo_exporter_shopify_access_token' );

    if ( empty( $store_url ) || empty( $access_token ) ) {
        woo_exporter_log_shopify_import( 'Shopify Store URL oder Access Token fehlen in den Einstellungen.', 'error' );
        return new WP_Error( 'shopify_api_error', 'Shopify Store URL oder Access Token fehlen in den Einstellungen.' );
    }

    $shopify_domain = str_replace( ['https://', 'http://'], '', rtrim( $store_url, '/' ) );
    $api_version = '2025-04'; // Aktuelle oder gewünschte API-Version
    $endpoint = "https://{$shopify_domain}/admin/api/{$api_version}/graphql.json";

    $headers = [
        'X-Shopify-Access-Token' => $access_token,
        'Content-Type'           => 'application/json',
    ];

// Ändern Sie diesen Teil:
    $body_data = [
        'query' => $query,
    ];

    // Fügen Sie 'variables' nur hinzu, wenn sie NICHT leer sind.
    // Wenn $variables ein leeres Array ist, wird json_encode es zu '[]'.
    // Wenn es ein leeres Objekt sein soll, müsste es 'new stdClass()' sein oder ganz weggelassen werden.
    // Die Shopify API ist hier streng und erwartet ein leeres OBJEKT {} wenn keine Variablen existieren.
    // Die beste Methode ist, das 'variables' Feld ganz wegzulassen, wenn es leer ist.
    if ( ! empty( $variables ) ) {
        $body_data['variables'] = $variables;
    } else {
        // Für Shopify ist es besser, ein explizit leeres Objekt zu senden, wenn keine Variablen da sind,
        // statt das Feld wegzulassen oder ein leeres Array zu senden.
        // Dies entspricht `{}` in JSON.
        $body_data['variables'] = new stdClass(); // Erzwingt ein leeres JSON-Objekt
    }


    $body = json_encode($body_data);

    woo_exporter_log_shopify_import( 'Sende GraphQL-Anfrage an: ' . $endpoint . "\nQuery (erste 200 Zeichen): " . substr($query, 0, 200) . "...\nVariables: " . json_encode($variables), 'info' );

    $response = wp_remote_post(
        $endpoint,
        [
            'method'    => 'POST',
            'headers'   => $headers,
            'body'      => $body,
            'timeout'   => 30, // Timeout für GraphQL-Anfrage
            'sslverify' => false, // In Produktion auf true setzen!
        ]
    );

    if ( is_wp_error( $response ) ) {
        woo_exporter_log_shopify_import( 'Shopify GraphQL Request Error: ' . $response->get_error_message(), 'error' );
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    if ( $response_code !== 200 ) {
        woo_exporter_log_shopify_import( 'Shopify GraphQL Request Failed (' . $response_code . '): ' . $response_body, 'error' );
        return new WP_Error( 'shopify_api_error', 'Shopify GraphQL Request failed: ' . $response_body, ['status' => $response_code] );
    }

    if ( isset( $data['errors'] ) ) {
        woo_exporter_log_shopify_import( 'Shopify GraphQL Errors in Response: ' . print_r( $data['errors'], true ), 'error' );
        return new WP_Error( 'shopify_graphql_errors', 'Shopify GraphQL returned errors.', $data['errors'] );
    }

    woo_exporter_log_shopify_import( 'Shopify GraphQL Request erfolgreich. Antwort (teilweise): ' . substr($response_body, 0, 500) . '...', 'info' );
    return $data;
}

/**
 * Erstellt eine signierte URL für den Datei-Upload in Shopify (Staged Upload).
 * Dies ist der erste Schritt für den Bulk-Import.
 *
 * @param string $filename Der Dateiname (z.B. 'shopify_products.jsonl').
 * @param int    $file_size Die Größe der Datei in Bytes.
 * @param string $mime_type Der MIME-Typ der Datei (hier 'application/jsonl').
 * @return array|WP_Error Die Upload-Daten (url, resourceUrl, parameters) bei Erfolg oder WP_Error.
 */
function woo_exporter_shopify_create_staged_upload( $filename, $file_size, $mime_type ) {
    $query = '
        mutation stagedUploadsCreate($input: [StagedUploadInput!]!) {
            stagedUploadsCreate(input: $input) {
                stagedTargets {
                    url
                    resourceUrl
                    parameters {
                        name
                        value
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
    ';

    $variables = [
        'input' => [
            [
                'filename' => $filename,
                'mimeType' => $mime_type,
                'resource' => 'BULK_MUTATION_VARIABLES', // Wichtig für Bulk Import, erwartet JSONL
                'fileSize' => (string)$file_size, // Muss ein String sein
            ]
        ]
    ];

    $response = woo_exporter_shopify_graphql_request( $query, $variables );

    if ( is_wp_error( $response ) ) {
        woo_exporter_log_shopify_import( 'Fehler bei stagedUploadsCreate (GraphQL).', 'error' );
        return $response;
    }

    if ( isset( $response['data']['stagedUploadsCreate']['userErrors'] ) && ! empty( $response['data']['stagedUploadsCreate']['userErrors'] ) ) {
        woo_exporter_log_shopify_import( 'stagedUploadsCreate User Errors: ' . print_r( $response['data']['stagedUploadsCreate']['userErrors'], true ), 'error' );
        return new WP_Error( 'shopify_staged_upload_error', 'Fehler beim Erstellen des Staged Uploads.', $response['data']['stagedUploadsCreate']['userErrors'] );
    }

    // KORRIGIERT: Greife auf 'stagedTargets' zu
    if ( isset( $response['data']['stagedUploadsCreate']['stagedTargets'][0] ) ) {
        $staged_upload_data = $response['data']['stagedUploadsCreate']['stagedTargets'][0];
        woo_exporter_log_shopify_import( 'Staged Upload erfolgreich erstellt. URL: ' . $staged_upload_data['url'], 'info' );
        return $staged_upload_data;
    }

    woo_exporter_log_shopify_import( 'Unbekannter Fehler beim Erstellen des Staged Uploads: Keine Upload-Daten erhalten.', 'error' );
    return new WP_Error( 'shopify_staged_upload_error', 'Unbekannter Fehler beim Erstellen des Staged Uploads: Keine Upload-Daten erhalten.' );
}



/**
 * Lädt eine Datei zu einer signierten Shopify Upload-URL hoch.
 * Versuch 2: Direkter PUT des Dateiinhalts über CURLOPT_PUT und CURLOPT_INFILE (streamen).
 * Dies ist oft der empfohlene Weg für signierte PUT-Uploads ohne Formular-Felder.
 *
 * @param array  $staged_upload_data Daten vom stagedUploadsCreate (url, resourceUrl, parameters).
 * @param string $file_path Der lokale Pfad zur Datei, die hochgeladen werden soll.
 * @return bool|WP_Error True bei Erfolg oder WP_Error.
 */
function woo_exporter_shopify_upload_file( $staged_upload_data, $file_path ) {
    $upload_url = $staged_upload_data['url'];
    // Die Parameter werden hier IGNORIERT, da sie für diese Art von PUT nicht benötigt werden.
    // $parameters = $staged_upload_data['parameters'];

    // Datei zum Lesen öffnen
    $file_handle = fopen( $file_path, 'r' );
    if ( $file_handle === false ) {
        woo_exporter_log_shopify_import( 'Datei konnte nicht zum Lesen geöffnet werden für den Upload: ' . $file_path, 'error' );
        return new WP_Error( 'file_read_error', 'Datei konnte nicht zum Lesen geöffnet werden für den Upload: ' . $file_path );
    }

    $file_size = filesize( $file_path );
    if ( $file_size === false ) {
        fclose($file_handle);
        woo_exporter_log_shopify_import( 'Dateigröße der JSONL-Datei konnte nicht ermittelt werden.', 'error' );
        return new WP_Error( 'file_size_error', 'Dateigröße konnte nicht ermittelt werden.' );
    }

    $ch = curl_init();

    if ($ch === false) {
        fclose($file_handle);
        woo_exporter_log_shopify_import( 'Curl-Initialisierung fehlgeschlagen.', 'error' );
        return new WP_Error( 'curl_init_failed', 'Curl-Initialisierung fehlgeschlagen.' );
    }

    curl_setopt($ch, CURLOPT_URL, $upload_url);
    curl_setopt($ch, CURLOPT_PUT, true); // Setzt die Methode auf PUT
    curl_setopt($ch, CURLOPT_INFILE, $file_handle); // Datei-Handle für den Upload
    curl_setopt($ch, CURLOPT_INFILESIZE, $file_size); // Wichtig: Die Größe der hochzuladenden Datei

    // Setze die Header
    $curl_headers = [
        'Content-Type: application/jsonl', // MIME-Typ der hochzuladenden Datei
        // Content-Length wird automatisch von CURLOPT_INFILESIZE und CURLOPT_PUT gesetzt
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);

    // SSL-Verifizierung (In Produktion auf true setzen!)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_HEADER, true); // Lese Header der Antwort

    woo_exporter_log_shopify_import( 'Starte Datei-Upload (PUT stream) zu: ' . $upload_url . ' (Dateigröße: ' . round($file_size / 1024, 2) . ' KB)', 'info' );
    woo_exporter_log_shopify_import( 'CURL PUT HEADERS GESENDET: ' . implode(' | ', $curl_headers), 'debug' );

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $response_header = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);

    fclose($file_handle); // Datei-Handle schließen
    curl_close($ch);

    if ($curl_error) {
        woo_exporter_log_shopify_import( 'Shopify File Upload Curl Error (PUT stream): ' . $curl_error, 'error' );
        return new WP_Error( 'shopify_file_upload_error', 'Shopify File Upload Curl Error (PUT stream): ' . $curl_error );
    }

    // Bei erfolgreichem PUT-Upload sollte der Status 200 OK sein
    if ( $http_code !== 200 ) {
        woo_exporter_log_shopify_import( 'Shopify File Upload Failed (PUT stream) (' . $http_code . '). Response Headers: ' . $response_header . ' | Response Body: ' . $response_body, 'error' );
        return new WP_Error( 'shopify_file_upload_failed', 'Datei-Upload zu Shopify fehlgeschlagen.', ['status' => $http_code, 'body' => $response_body, 'headers' => $response_header] );
    }

    woo_exporter_log_shopify_import( 'Datei erfolgreich zu Shopify Staged Upload hochgeladen (PUT stream). HTTP Status: ' . $http_code . ' | Antwort: ' . $response_body, 'info' );
    return true;
}


/**
 * Triggert einen Bulk-Import-Job in Shopify über GraphQL.
 * Dies ist der dritte und letzte Schritt für den Bulk-Import.
 *
 * @param string $staged_upload_resource_url Die resourceUrl vom stagedUploadsCreate, die auf die hochgeladene Datei verweist.
 * @return string|WP_Error Die ID des Bulk-Operations-Jobs bei Erfolg oder WP_Error.
 */
function woo_exporter_shopify_trigger_bulk_import( $staged_upload_resource_url ) {
    // Mutation für das Erstellen oder Aktualisieren von Produkten.
    // 'id: ID!' im ProductInput erlaubt das Aktualisieren eines bestehenden Produkts
    // anstatt immer ein neues zu erstellen. Wenn id nicht vorhanden ist, wird erstellt.
    // Aber: ProductInput hat kein 'id' Feld. `productCreate` erstellt, `productUpdate` aktualisiert.
    // Für Bulk Operation mit Upsert-Verhalten müsste die Logik die Shopify IDs speichern
    // oder die Mutation komplexer sein.
    // Aktuell nutzen wir `productCreate`. Bei existierenden Handles wird Shopify Fehler melden.
    $mutation_string = 'mutation ($input: ProductInput!) { productCreate(input: $input) { product { id handle } userErrors { field message } } }';

    $query = '
        mutation {
            bulkOperationRunMutation(
                mutation: "' . esc_js( $mutation_string ) . '"
                stagedUploadPath: "' . esc_js( $staged_upload_resource_url ) . '"
            ) {
                bulkOperation {
                    id
                    status
                    errorCode
                    url
                    createdAt
                    completedAt
                    objectCount
                }
                userErrors {
                    field
                    message
                }
            }
        }
    ';

    woo_exporter_log_shopify_import( 'Triggere Shopify Bulk Operation mit resourceUrl: ' . $staged_upload_resource_url, 'info' );
    $response = woo_exporter_shopify_graphql_request( $query );

    if ( is_wp_error( $response ) ) {
        woo_exporter_log_shopify_import( 'Fehler beim Triggern der Bulk Operation (GraphQL).', 'error' );
        return $response;
    }

    if ( isset( $response['data']['bulkOperationRunMutation']['userErrors'] ) && ! empty( $response['data']['bulkOperationRunMutation']['userErrors'] ) ) {
        woo_exporter_log_shopify_import( 'bulkOperationRunMutation User Errors: ' . print_r( $response['data']['bulkOperationRunMutation']['userErrors'], true ), 'error' );
        return new WP_Error( 'shopify_bulk_import_error', 'Fehler beim Starten der Bulk-Operation.', $response['data']['bulkOperationRunMutation']['userErrors'] );
    }

    if ( isset( $response['data']['bulkOperationRunMutation']['bulkOperation']['id'] ) ) {
        $bulk_operation_id = $response['data']['bulkOperationRunMutation']['bulkOperation']['id'];
        $bulk_operation_status = $response['data']['bulkOperationRunMutation']['bulkOperation']['status'];
        woo_exporter_log_shopify_import( 'Shopify Bulk Import Job erfolgreich gestartet. ID: ' . $bulk_operation_id . ', Status: ' . $bulk_operation_status . '. Prüfen Sie den Status in Shopify unter Einstellungen > Bulk-Operationen.', 'info' );
        return $bulk_operation_id;
    }

    woo_exporter_log_shopify_import( 'Unbekannter Fehler beim Starten der Bulk-Operation: Keine Bulk Operation ID erhalten.', 'error' );
    return new WP_Error( 'shopify_bulk_import_error', 'Unbekannter Fehler beim Starten der Bulk-Operation: Keine Bulk Operation ID erhalten.' );
}



/**
 * Wandelt WooCommerce-Produktdaten in das Shopify GraphQL ProductInput-Format um (für JSONL).
 * Wrappt das finale Produkt-Input-Objekt in ein "input"-Feld, wie von Shopify erwartet.
 *
 * @param array $product_data Einzelnes Produkt oder Variation aus get_all_woocommerce_products_with_variations.
 * @param array $parent_product_data Das Hauptprodukt-Array (für Beschreibung, Kategorien, etc.).
 * @return array Das für Shopify GraphQL formatierte und gewrappte Produkt-Input-Array.
 */
function woo_exporter_format_product_for_shopify_graphql( $product_data, $parent_product_data ) {
    $is_variation = isset( $product_data['parent_id'] );

    // Handle und Title
    $handle = $product_data['slug'];
    if ($is_variation && !empty($product_data['sku'])) {
        $handle .= '-' . sanitize_title($product_data['sku']);
    } else {
        $handle = sanitize_title($handle);
    }

    $title = html_entity_decode($parent_product_data['name'], ENT_QUOTES, 'UTF-8');
    if ($is_variation && !empty($product_data['attributes'])) {
        foreach ($product_data['attributes'] as $attr_name => $attr_value) {
            $title .= ' ' . html_entity_decode($attr_value, ENT_QUOTES, 'UTF-8');
        }
    }

    $description_html = wpautop($parent_product_data['description']);
    // Sicherstellen, dass der String gültiges UTF-8 ist, bevor entitäten dekodiert werden
    // Dies hilft, wenn es "Mojibake" gibt.
    $description_html = mb_convert_encoding($description_html, 'UTF-8', mb_detect_encoding($description_html, 'UTF-8, ISO-8859-1, Windows-1252', true));
    $description_html = html_entity_decode($description_html, ENT_QUOTES, 'UTF-8');
    $description_html = wp_kses_post( $description_html ); // Sanitize HTML

    // SEO-Titel und -Beschreibung
    $seo_title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $seo_description = html_entity_decode($parent_product_data['short_description'], ENT_QUOTES, 'UTF-8');
    // Auch hier Encoding-Fix anwenden:
    $seo_description = mb_convert_encoding($seo_description, 'UTF-8', mb_detect_encoding($seo_description, 'UTF-8, ISO-8859-1, Windows-1252', true));

    if (empty($seo_description)) {
        $clean_desc = wp_strip_all_tags($description_html);
        $clean_desc = preg_replace('/\s+/', ' ', $clean_desc);
        $seo_description = substr($clean_desc, 0, 160);
        if (strlen($clean_desc) > 160) {
            $seo_description = rtrim(substr($seo_description, 0, strrpos($seo_description, ' ')), '.!,') . '...';
        }
    }
    // IMMER HTML-Tags entfernen und Leerzeichen normalisieren für SEO Description
    $seo_description = wp_strip_all_tags( $seo_description );
    $seo_description = trim( preg_replace( '/\s+/', ' ', $seo_description ) );
    if (mb_strlen($seo_description, 'UTF-8') > 160) {
        $seo_description = mb_substr($seo_description, 0, 157, 'UTF-8') . '...';
    }


    // Produkt-Type
    $shopify_product_type = '';
    if (stripos($title, 'shirt') !== false) {
        $shopify_product_type = 'Shirt';
    } else {
        $item_object = wc_get_product( $product_data['id'] );
        if ( $item_object && $item_object->is_type('variation') ) {
            $item_attributes = $item_object->get_variation_attributes();
            if (isset($item_attributes['attribute_pa_sound-carrier']) && !empty($item_attributes['attribute_pa_sound-carrier'])) {
                $shopify_product_type = ucfirst($item_attributes['attribute_pa_sound-carrier']);
            }
        }
        if (empty($shopify_product_type)) {
            $sound_carrier_terms = wp_get_post_terms( $parent_product_data['id'], 'pa_sound-carrier', array( 'fields' => 'names' ) );
            if ( ! empty( $sound_carrier_terms ) && is_array( $sound_carrier_terms ) ) {
                $shopify_product_type = $sound_carrier_terms[0];
            }
        }
    }

    // Produktgewicht
    $variant_grams = 100;
    if ( stripos( $shopify_product_type, 'Vinyl' ) !== false ) {
        $variant_grams = 1000;
    }

    // Published-Status
    $published_status = true;
    if ($product_data['stock_status'] === 'outofstock' || ($product_data['stock_quantity'] !== null && $product_data['stock_quantity'] <= 0)) {
        $published_status = false;
    }

    // Bilder
    $images_input = [];
    $all_image_urls = [];
    if (!empty($product_data['image_url'])) {
        $all_image_urls[] = $product_data['image_url'];
    }
    if (!empty($product_data['gallery_image_urls'])) {
        foreach ($product_data['gallery_image_urls'] as $gallery_url) {
            if ($gallery_url !== $product_data['image_url']) {
                $all_image_urls[] = $gallery_url;
            }
        }
    }
    $all_image_urls = array_unique($all_image_urls);

    foreach ($all_image_urls as $img_url) {
        $images_input[] = [
            'src'      => $img_url,
            'altText'  => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
        ];
    }

    // Attribute als Optionen für die individuelle Variante
    $product_options_names = [];
    $variant_options_values = [];

    if ($is_variation && !empty($product_data['attributes'])) {
        foreach ($product_data['attributes'] as $attr_name => $attr_value) {
            $cleaned_attr_name = ucfirst(str_replace('pa_', '', $attr_name));
            $product_options_names[] = $cleaned_attr_name;
            $variant_options_values[] = html_entity_decode($attr_value, ENT_QUOTES, 'UTF-8');
        }
    }


    $product_input_data = [ // Umbenannt, da das finale Objekt gewrappt wird
        'handle'          => $handle,
        'title'           => $title,
        'descriptionHtml' => $description_html,
        'vendor'          => get_bloginfo('name'),
        'productType'     => $shopify_product_type,
        'tags'            => implode(',', $parent_product_data['tags']),
        'status'          => $published_status ? 'ACTIVE' : 'DRAFT',
        'seo'             => [
            'title'       => $seo_title,
            'description' => $seo_description,
        ],
        'images'          => $images_input,
    ];

    if (!empty($product_options_names)) {
        $product_input_data['options'] = $product_options_names;
    }


    // Variante Details für die einzelne Variante
    $variant_input = [
        'sku'                  => $product_data['sku'],
        'price'                => number_format($product_data['price'], 2, '.', ''),
        'weight'               => $variant_grams,
        'weightUnit'           => 'GRAMS',
        'inventoryManagement'  => 'SHOPIFY',
        'inventoryPolicy'      => 'DENY',
        'inventoryItem'        => [
            'tracked'          => true,
        ],
        'requiresShipping'     => true,
        'taxable'              => true,
        'fulfillmentService'   => 'manual',
    ];

    if (!empty($variant_options_values)) {
        $variant_input['options'] = $variant_options_values;
    }

    // Korrigierte Logik für 'compareAtPrice'
    if (
        isset($product_data['regular_price']) && $product_data['regular_price'] !== '' &&
        isset($product_data['sale_price']) && $product_data['sale_price'] !== '' &&
        (float)$product_data['regular_price'] > (float)$product_data['sale_price']
    ) {
        $variant_input['compareAtPrice'] = number_format((float)$product_data['regular_price'], 2, '.', '');
    }
    if (isset($variant_input['compareAtPrice']) && $variant_input['compareAtPrice'] === null) {
        unset($variant_input['compareAtPrice']);
    }

    // `barcode` muss entfernt werden, wenn leer
    if (isset($variant_input['barcode']) && empty($variant_input['barcode'])) {
        unset($variant_input['barcode']);
    }

    $product_input_data['variants'][] = $variant_input;

    // Aufräumen: Felder entfernen, die leer sind und keine leeren Arrays sein sollten
    foreach ( $product_input_data as $key => $value ) { // Hier $product_input_data verwenden
        if ( is_array( $value ) ) {
            if ( empty( $value ) && !in_array($key, ['tags', 'images', 'variants', 'options']) ) {
                unset( $product_input_data[ $key ] );
            }
        } elseif ( $value === '' || $value === null ) {
            unset( $product_input_data[ $key ] );
        }
    }

    if (isset($product_input_data['options']) && empty($product_input_data['options'])) {
        unset($product_input_data['options']);
    }

    // *** DAS FINALE WRAPPING DES PRODUKT-INPUT-OBJEKTS ***
    return [ 'input' => $product_input_data ];
}


/**
 * Generiert die JSONL-Datei für den Shopify Bulk Import.
 * Jede Zeile der JSONL-Datei ist ein JSON-Objekt, das ein Produkt darstellt.
 *
 * @param array  $data     Das Array von WooCommerce-Produktdaten (resultat von get_all_woocommerce_products_with_variations).
 * @param string $filename Der Dateiname für die JSONL-Datei (z.B. 'shopify_products.jsonl').
 * @return string|false Der Pfad zur generierten JSONL-Datei bei Erfolg, false bei Fehler.
 */
function write_shopify_products_jsonl( $data, $filename = 'shopify_products.jsonl' ) {
    $upload_dir = wp_upload_dir();
    $dir_path   = $upload_dir['basedir'] . '/woo_product_exports/';
    $filepath   = $dir_path . $filename;

    if ( ! is_dir( $dir_path ) ) {
        wp_mkdir_p( $dir_path );
    }

    $file = fopen( $filepath, 'w' );
    if ( ! $file ) {
        woo_exporter_log_shopify_import( "Konnte JSONL-Datei nicht zum Schreiben öffnen: " . $filepath, 'error' );
        return false;
    }

    $product_count = 0;
    foreach ( $data as $product ) {
        $items_to_process = [];
        if ($product['type'] === 'variable' && !empty($product['variations'])) {
            foreach ($product['variations'] as $variation) {
                $items_to_process[] = $variation;
            }
        } else {
            $items_to_process[] = $product;
        }

        foreach ($items_to_process as $item_data) {
            // Hier kommt die Funktion, die die einzelnen Produktobjekte generiert
            $graphql_product_input = woo_exporter_format_product_for_shopify_graphql( $item_data, $product );
            if ( $graphql_product_input ) {
                // Jedes Produkt als separate JSON-Zeile schreiben
                if (fwrite( $file, json_encode( $graphql_product_input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) === false) { // JSON_PRETTY_PRINT entfernt, da es zusätzliche Zeilenumbrüche innerhalb des JSON-Objekts erzeugt, die bei JSONL-Parsern Probleme machen könnten
                    woo_exporter_log_shopify_import( "Fehler beim Schreiben in JSONL-Datei bei Produkt: " . $item_data['id'], 'error' );
                    fclose($file);
                    return false;
                }
                $product_count++;
            }
        }
    }

    fclose( $file );
    @chmod( $filepath, 0664 );

    woo_exporter_log_shopify_import( "JSONL-Datei erfolgreich generiert: " . $filepath . " mit " . $product_count . " Produkten.", 'info' );
    return $filepath;
}

