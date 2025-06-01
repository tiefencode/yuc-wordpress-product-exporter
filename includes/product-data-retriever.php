<?php
/**
 * Funktionen zum Abrufen von WooCommerce-Produktdaten.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Funktion zum Abrufen aller WooCommerce-Produkte mit Details und Variationen,
 * gefiltert nach einer spezifischen Kategorie-ID und ihren Unterkategorien.
 *
 * @param int $category_id Die ID der Kategorie (und ihrer Unterkategorien), aus der Produkte abgerufen werden sollen.
 * @return array Ein Array von Produkten, jedes mit seinen Daten und Variationen.
 */
function get_all_woocommerce_products_with_variations( $category_id = 31 ) { // Standardwert auf 31 gesetzt
    $products_data = [];

    // Argumente für die Abfrage aller Produkte
    $args = [
        'status'     => 'publish', // Nur veröffentlichte Produkte
        'limit'      => -1,         // Alle Produkte abrufen
        'type'       => ['simple', 'variable'], // Einfache und variable Produkte
        'return'     => 'ids',     // Nur IDs abrufen, um Speicher zu sparen und dann einzeln laden
        'orderby'    => 'ID',
        'order'      => 'ASC',
        'tax_query'  => [
            [
                'taxonomy' => 'product_cat',
                'field'    => 'id',
                'terms'    => $category_id,
                'include_children' => true, // Produkte aus Unterkategorien einschließen
            ],
        ],
    ];

    // Abfrage der Produkt-IDs
    $product_ids = wc_get_products( $args );

    foreach ( $product_ids as $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            continue; // Produkt konnte nicht geladen werden
        }

        $product_info = [
            'id'                => $product->get_id(),
            'sku'               => $product->get_sku(),
            'name'              => $product->get_name(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'permalink'         => $product->get_permalink(),
            'slug'              => $product->get_slug(),
            'status'            => $product->get_status(),
            'price'             => wc_get_price_to_display($product),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'weight'            => $product->get_weight(), // In kg
            'length'            => $product->get_length(),
            'width'             => $product->get_width(),
            'height'            => $product->get_height(),
            'categories'        => [],
            'tags'              => [],
            'image_url'         => '',
            'gallery_image_urls' => [],
            'type'              => $product->get_type(), // simple, variable, etc.
            'variations'        => [],
        ];

        // Produktkategorien
        $categories = wp_get_post_terms( $product->get_id(), 'product_cat' );
        foreach ( $categories as $category ) {
            $product_info['categories'][] = $category->name;
        }

        // Produkttags
        $tags = wp_get_post_terms( $product->get_id(), 'product_tag' );
        foreach ( $tags as $tag ) {
            $product_info['tags'][] = $tag->name;
        }

        // Hauptproduktbild
        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $product_info['image_url'] = wp_get_attachment_image_url( $image_id, 'full' );
        }

        // Produktgalerie-Bilder
        $gallery_image_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_image_ids as $gallery_image_id ) {
            $product_info['gallery_image_urls'][] = wp_get_attachment_image_url( $gallery_image_id, 'full' );
        }

        // --- Behandlung von variablen Produkten und deren Variationen ---
        if ( $product->is_type( 'variable' ) ) {
            /** @var WC_Product_Variable $product */
            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );

                if ( ! $variation ) {
                    continue;
                }

                $variation_info = [
                    'id'             => $variation->get_id(),
                    'parent_id'      => $product->get_id(),
                    'sku'            => $variation->get_sku(),
                    'name'           => $variation->get_name(),
                    'price'          => wc_get_price_to_display($variation),
                    'regular_price'  => $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price(),
                    'stock_status'   => $variation->get_stock_status(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'weight'         => $variation->get_weight(), // In kg
                    'length'         => $variation->get_length(),
                    'width'          => $variation->get_width(),
                    'height'         => $variation->get_height(),
                    'attributes'     => [],
                    'image_url'      => '',
                    'slug'           => $variation->get_slug(),
                ];

                // Variationsattribute (z.B. 'pa_color' => 'red')
                $attributes = $variation->get_variation_attributes();
                foreach ( $attributes as $key => $value ) {
                    $attribute_name = str_replace( 'attribute_', '', $key );
                    $variation_info['attributes'][ $attribute_name ] = $value;
                }

                // Variationsbild (falls vorhanden, sonst vom Parent Produkt)
                $variation_image_id = $variation->get_image_id();
                if ( $variation_image_id ) {
                    $variation_info['image_url'] = wp_get_attachment_image_url( $variation_image_id, 'full' );
                } elseif ( $image_id ) {
                    $variation_info['image_url'] = wp_get_attachment_image_url( $image_id, 'full' );
                }

                $product_info['variations'][] = $variation_info;
            }
        }

        $products_data[] = $product_info;
    }

    return $products_data;
}
