<?php

/**
 * Plugin Name: WooCommerce GA4 Ecommerce Events
 * Description: Scalable GA4 ecommerce events integration for WooCommerce (view_item_list, view_item, add_to_cart, remove_from_cart, begin_checkout, purchase).
 * Version: 1.1.0
 * Author: Your Team
 * Text Domain: wc-ga4-ecommerce-events
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 * Architecture prepared for future GA4 ecommerce events
 */
class WC_GA4_Ecommerce_Events {

    public function __construct() {
        // view_item_list
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_shop']);
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_category']);
        add_action('woocommerce_after_shop_loop', [$this, 'view_item_list_search']);

        // homepage (WooCommerce blocks / shop loop)
        add_action('wp_footer', [$this, 'view_item_list_homepage'], 20);
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
     * =========================
     * VIEW ITEM LIST – HOMEPAGE
     * =========================
     * Fires only if WooCommerce products are present in main query
     */
    public function view_item_list_homepage() {
        if (!is_front_page() && !is_home()) {
            return;
        }

        if (!is_woocommerce()) {
            return;
        }

        $this->output_view_item_list('home_page');
    }

    /**
     * =========================
     * CORE: Build & Output view_item_list
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

            // Categories without HTML
            $terms = get_the_terms($product->get_id(), 'product_cat');
            $main_category = '';
            if ($terms) {
                foreach ($terms as $term) {
                    if ($term->parent == 0) {
                        $main_category = $term->name;
                        break;
                    }
                }
            }
            $item_category  = $main_category;
            $item_category2 = '';
            if ($terms) {
                foreach ($terms as $term) {
                    if ($term->name !== $main_category) {
                        $item_category2 = $term->name;
                        break;
                    }
                }
            }

            // List meta
            $list_id   = $this->get_list_id($context);
            $list_name = $this->get_list_name($context);

            $items[] = [
                'item_id'        => (string) $product->get_id(),
                'item_name'      => $product->get_name(),
                'price'          => (float) $product->get_price(),
                'item_brand'     => $product->get_attribute('pa_brand') ?: '',
                'item_category'  => $item_category,
                'item_category2' => $item_category2,
                'item_list_id'   => $list_id,
                'item_list_name' => $list_name,
                'index'          => $index,
                'google_business_vertical' => 'retail',
            ];

            $index++;
        }

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
                event: 'view_item_list',
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
     * Helpers: list id & name
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
            case 'home_page':
                return 'homepage_page';
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
            case 'home_page':
                return 'Home Page';
        }
    }
}

new WC_GA4_Ecommerce_Events();
