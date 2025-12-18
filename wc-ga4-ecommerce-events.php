<?php

/**
 * Plugin Name: WooCommerce GA4 Ecommerce Events
 * Description: Scalable GA4 ecommerce events integration for WooCommerce (view_item_list, view_item, add_to_cart, remove_from_cart, begin_checkout, purchase).
 * Version: 1.3.0
 * Author: Your Team
 * Text Domain: wc-ga4-ecommerce-events
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_GA4_Ecommerce_Events {

    public function __construct() {

        // view_item_list – WooCommerce main loops
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_shop']);
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_category']);
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_search']);

        // view_item_list – homepage shortcodes [products]
        add_filter(
            'woocommerce_shortcode_products_query',
            [$this, 'capture_homepage_products_shortcode'],
            10,
            3
        );

        // view_item – single product
        add_action('wp_footer', [$this, 'view_item_single_product'], 20);
    }

    /**
     * =========================
     * VIEW ITEM LIST – SHOP
     * =========================
     */
    public function view_item_list_shop() {
        if (!is_shop() || is_search()) {
            return;
        }

        $this->output_view_item_list('shop_page');
    }

    /**
     * =========================
     * VIEW ITEM LIST – CATEGORY
     * =========================
     */
    public function view_item_list_category() {
        if (!is_product_category()) {
            return;
        }

        $this->output_view_item_list('category_page');
    }

    /**
     * =========================
     * VIEW ITEM LIST – SEARCH
     * =========================
     */
    public function view_item_list_search() {
        if (!is_search()) {
            return;
        }

        $this->output_view_item_list('search_page');
    }

    /**
     * ==================================================
     * VIEW ITEM LIST – HOMEPAGE [products] SHORTCODES
     * ==================================================
     */
    public function capture_homepage_products_shortcode($query_args, $atts, $type) {

        if (!is_front_page() && !is_home()) {
            return $query_args;
        }

        if ($type !== 'products' || empty($atts['category'])) {
            return $query_args;
        }

        add_action('wp_footer', function () use ($query_args, $atts) {

            $query = new WP_Query($query_args);
            if (!$query->have_posts()) {
                return;
            }

            $items = [];
            $index = 1;

            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                if (!$product) continue;

                [$cat1, $cat2] = $this->get_product_categories($product);

                $items[] = [
                    'item_id'        => (string) $product->get_id(),
                    'item_name'      => $product->get_name(),
                    'price'          => (float) $product->get_price(),
                    'item_brand'     => $product->get_attribute('pa_brand') ?: '',
                    'item_category'  => $cat1,
                    'item_category2' => $cat2,
                    'item_list_id'   => 'home_' . sanitize_title($atts['category']),
                    'item_list_name' => 'Home – ' . ucfirst($atts['category']),
                    'index'          => $index,
                    'google_business_vertical' => 'retail',
                ];

                $index++;
            }

            wp_reset_postdata();

            $this->print_datalayer('view_item_list', $items);
        }, 20);

        return $query_args;
    }

    /**
     * =========================
     * VIEW ITEM – SINGLE PRODUCT
     * =========================
     */
    public function view_item_single_product() {

        if (!is_product()) {
            return;
        }

        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        [$cat1, $cat2] = $this->get_product_categories($product);

        $item = [
            'item_id'        => (string) $product->get_id(),
            'item_name'      => $product->get_name(),
            'price'          => (float) $product->get_price(),
            'item_brand'     => $product->get_attribute('pa_brand') ?: '',
            'item_category'  => $cat1,
            'item_category2' => $cat2,
            'item_variant'   => $product->get_attribute('pa_color') ?: '',
            'google_business_vertical' => 'retail',
        ];

        $this->print_datalayer('view_item', [$item]);
    }

    /**
     * =========================
     * CORE: VIEW ITEM LIST
     * =========================
     */
    private function output_view_item_list($context) {
        global $wp_query;

        if (empty($wp_query->posts)) {
            return;
        }

        $items = [];
        $index = 1;

        foreach ($wp_query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            [$cat1, $cat2] = $this->get_product_categories($product);

            $items[] = [
                'item_id'        => (string) $product->get_id(),
                'item_name'      => $product->get_name(),
                'price'          => (float) $product->get_price(),
                'item_brand'     => $product->get_attribute('pa_brand') ?: '',
                'item_category'  => $cat1,
                'item_category2' => $cat2,
                'item_list_id'   => $this->get_list_id($context),
                'item_list_name' => $this->get_list_name($context),
                'index'          => $index,
                'google_business_vertical' => 'retail',
            ];

            $index++;
        }

        $this->print_datalayer('view_item_list', $items);
    }

    /**
     * =========================
     * PRINT DATALAYER
     * =========================
     */
    private function print_datalayer($event, $items) {
        if (empty($items)) {
            return;
        }
?>
        <script>
            window.dataLayer = window.dataLayer || [];
            dataLayer.push({
                ecommerce: null
            });
            dataLayer.push({
                event: "<?php echo esc_js($event); ?>",
                ecommerce: {
                    currency: "UAH",
                    items: <?php echo wp_json_encode($items, JSON_UNESCAPED_UNICODE); ?>
                }
            });
        </script>
<?php
    }

    /**
     * =========================
     * PRODUCT CATEGORIES HELPER
     * =========================
     */
    private function get_product_categories($product) {

        $terms = get_the_terms($product->get_id(), 'product_cat');
        $cat1 = '';
        $cat2 = '';

        if ($terms) {
            foreach ($terms as $term) {
                if ($term->parent == 0) {
                    $cat1 = $term->name;
                    break;
                }
            }
            foreach ($terms as $term) {
                if ($term->name !== $cat1) {
                    $cat2 = $term->name;
                    break;
                }
            }
        }

        return [$cat1, $cat2];
    }

    /**
     * =========================
     * LIST META
     * =========================
     */
    private function get_list_id($context) {
        switch ($context) {
            case 'shop_page':
                return 'shop_page';
            case 'category_page':
                return 'category_page';
            case 'search_page':
                return 'search_page';
        }
    }

    private function get_list_name($context) {
        switch ($context) {
            case 'shop_page':
                return 'Shop Page';
            case 'category_page':
                return 'Category Page';
            case 'search_page':
                return 'Search Page';
        }
    }
}

new WC_GA4_Ecommerce_Events();
