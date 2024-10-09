<?php
/**
 * Plugin Name: Convert Local Attributes to Global Attributes
 * Description: Converts local attributes into global attributes and replaces all product local attributes with global ones.
 * Version: 1.2
 * Author: Yousha
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Convert_Local_To_Global_Attributes {

    private $option_name = 'attributes_to_convert';
    private $interval_option_name = 'conversion_interval';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'process_conversion' ) );
        add_action( 'admin_post_save_attributes', array( $this, 'save_attributes' ) );
        add_action( 'admin_post_save_interval', array( $this, 'save_interval' ) );
        add_action( 'convert_local_to_global_event', array( $this, 'convert_local_to_global' ) );

        // Schedule the event if not already scheduled
        if ( ! wp_next_scheduled( 'convert_local_to_global_event' ) ) {
            $interval = get_option( $this->interval_option_name, 3600 ); // Default to 1 hour
            wp_schedule_event( time(), 'custom_interval', 'convert_local_to_global_event' );
        }

        // Add custom interval to cron schedules
        add_filter( 'cron_schedules', array( $this, 'add_custom_interval' ) );
    }

    public function add_admin_menu() {
        add_menu_page( 
            'Convert Attributes', 
            'Convert Attributes', 
            'manage_options', 
            'convert-attributes', 
            array( $this, 'conversion_page' ),
            'dashicons-update',
            20
        );
    }

    public function conversion_page() {
        $attributes_to_convert = get_option( $this->option_name, array( 'Color', 'Material', 'Diameter', 'Model', 'Gender' ) );
        $interval = get_option( $this->interval_option_name, 3600 ); // Default to 1 hour
        ?>
        <div class="wrap">
            <h1>Convert Local Attributes to Global Attributes</h1>
            <p>This tool converts local attributes into global attributes and replaces all product local attributes with global attributes.</p>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="save_attributes">
                <?php wp_nonce_field( 'save_attributes_nonce' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Attributes to Convert</th>
                        <td>
                            <textarea name="attributes_to_convert" rows="5" cols="50"><?php echo implode( "\n", $attributes_to_convert ); ?></textarea>
                            <p class="description">Enter one attribute per line.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Attributes' ); ?>
            </form>
            <form method="post" action="">
                <input type="hidden" name="convert_attributes" value="1">
                <?php submit_button( 'Start Conversion' ); ?>
            </form>
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                <input type="hidden" name="action" value="save_interval">
                <?php wp_nonce_field( 'save_interval_nonce' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Conversion Interval (seconds)</th>
                        <td>
                            <input type="number" name="conversion_interval" value="<?php echo esc_attr( $interval ); ?>" />
                            <p class="description">Enter the interval in seconds for automatic conversion. Default is 3600 seconds (1 hour).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Interval' ); ?>
            </form>
        </div>
        <?php
    }

    public function save_attributes() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'save_attributes_nonce' ) ) {
            wp_die( 'Invalid nonce.' );
        }

        if ( isset( $_POST['attributes_to_convert'] ) ) {
            $attributes = array_map( 'sanitize_text_field', explode( "\n", $_POST['attributes_to_convert'] ) );
            $attributes = array_filter( $attributes ); // Remove empty lines
            update_option( $this->option_name, $attributes );
        }

        wp_redirect( admin_url( 'admin.php?page=convert-attributes' ) );
        exit;
    }

    public function save_interval() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'save_interval_nonce' ) ) {
            wp_die( 'Invalid nonce.' );
        }

        if ( isset( $_POST['conversion_interval'] ) ) {
            $interval = intval( $_POST['conversion_interval'] );
            update_option( $this->interval_option_name, $interval );

            // Reschedule the event with the new interval
            wp_clear_scheduled_hook( 'convert_local_to_global_event' );
            wp_schedule_event( time(), 'custom_interval', 'convert_local_to_global_event' );
        }

        wp_redirect( admin_url( 'admin.php?page=convert-attributes' ) );
        exit;
    }

    public function add_custom_interval( $schedules ) {
        $interval = get_option( $this->interval_option_name, 3600 ); // Default to 1 hour
        $schedules['custom_interval'] = array(
            'interval' => $interval,
            'display'  => __( 'Custom Interval' ),
        );
        return $schedules;
    }

    public function process_conversion() {
        if ( isset( $_POST['convert_attributes'] ) ) {
            $this->convert_local_to_global();
        }
    }

    public function convert_local_to_global() {
        global $wpdb;

        $attributes_to_convert = get_option( $this->option_name, array() );

        // Create global attributes if they don't exist
        foreach ( $attributes_to_convert as $attribute_name ) {
            $attribute_id = wc_attribute_taxonomy_id_by_name( strtolower( $attribute_name ) );
            if ( ! $attribute_id ) {
                $this->create_global_attribute( $attribute_name );
            }
        }

        // Flush the WooCommerce attribute cache to ensure global attributes are registered
        delete_transient( 'wc_attribute_taxonomies' );

        // Get all products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1
        );
        $products = get_posts( $args );

        error_log('Total products found: ' . count($products));

        foreach ( $products as $product ) {
            $product_id = $product->ID;
            $product_obj = wc_get_product( $product_id );

            // Log all attributes of the product
            $attributes = $product_obj->get_attributes();
            error_log('Product ID: ' . $product_id . ' - Attributes: ' . print_r($attributes, true));

            // Loop through attributes to convert
            foreach ( $attributes_to_convert as $attribute_name ) {
                $local_attr = strtolower( $attribute_name );
                $global_attr = 'pa_' . strtolower( $attribute_name );

                // Check if product has local attribute
                if ( isset( $attributes[ $local_attr ] ) && $attributes[ $local_attr ]->is_taxonomy() == false ) {
                    // Get the local attribute values
                    $local_values = $attributes[ $local_attr ]->get_options();
                    error_log('Product ID: ' . $product_id . ' - Local attribute values for ' . $local_attr . ': ' . implode(', ', $local_values));

                    // Create global terms
                    $term_ids = array();
                    $term_names = array();
                    foreach ( $local_values as $value ) {
                        $term = term_exists( $value, $global_attr );
                        if ( ! $term ) {
                            $term = wp_insert_term( $value, $global_attr );
                            error_log('Inserting term: ' . $value . ' for ' . $global_attr);
                        } else {
                            error_log('Term already exists: ' . $value . ' for ' . $global_attr);
                        }
                        if ( ! is_wp_error( $term ) ) {
                            $term_ids[] = (int) $term['term_id'];
                            $term_names[] = $value;
                        } else {
                            error_log('Error inserting term: ' . $value . ' - ' . $term->get_error_message());
                        }
                    }

                    error_log('Product ID: ' . $product_id . ' - Term IDs for ' . $global_attr . ': ' . implode(', ', $term_ids));

                    // Replace local attribute with global
                    wp_set_object_terms( $product_id, $term_ids, $global_attr );

                    // Update product attributes
                    $attributes[ $global_attr ] = new WC_Product_Attribute();
                    $attributes[ $global_attr ]->set_id( wc_attribute_taxonomy_id_by_name( strtolower( $attribute_name ) ) );
                    $attributes[ $global_attr ]->set_name( $global_attr );
                    $attributes[ $global_attr ]->set_options( $term_names ); // Set term names instead of IDs
                    $attributes[ $global_attr ]->set_position( 0 );
                    $attributes[ $global_attr ]->set_visible( true );
                    $attributes[ $global_attr ]->set_variation( false );

                    // Remove local attribute from product
                    unset( $attributes[ $local_attr ] );
                    $product_obj->set_attributes( $attributes );
                    $product_obj->save();
                } else {
                    error_log('Product ID: ' . $product_id . ' - No local attribute found for ' . $local_attr);
                }
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>Attribute conversion completed successfully!</p></div>';
    }

    private function create_global_attribute( $name ) {
        global $wpdb;
        $attribute_slug = sanitize_title( $name );

        // Insert new attribute into the taxonomy table
        $result = $wpdb->insert(
            $wpdb->prefix . 'woocommerce_attribute_taxonomies',
            array(
                'attribute_label'   => $name,
                'attribute_name'    => $attribute_slug,
                'attribute_type'    => 'select',
                'attribute_orderby' => 'menu_order',
                'attribute_public'  => 1,
            )
        );

        if ( false === $result ) {
            error_log('Error creating global attribute: ' . $name);
            return false;
        }

        // Flush the WooCommerce attribute cache
        delete_transient( 'wc_attribute_taxonomies' );

        // Ensure the attribute is registered
        register_taxonomy(
            'pa_' . $attribute_slug,
            apply_filters( 'woocommerce_taxonomy_objects_' . 'pa_' . $attribute_slug, array( 'product' ) ),
            apply_filters( 'woocommerce_taxonomy_args_' . 'pa_' . $attribute_slug, array(
                'labels'       => array(
                    'name' => $name,
                ),
                'hierarchical' => true,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
            ) )
        );

        return wc_attribute_taxonomy_id_by_name( $attribute_slug );
    }
}

new Convert_Local_To_Global_Attributes();