<?php

/**
 * Plugin Name: WooCommerce GA4 Ecommerce Events
 * Description: Scalable GA4 ecommerce events integration for WooCommerce (view_item_list, view_item, add_to_cart, remove_from_cart, begin_checkout, purchase).
 * Version: 1.5.0
 * Author: Your Team
 * Text Domain: wc-ga4-ecommerce-events
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_GA4_Ecommerce_Events {

    private $add_to_cart_items = [];
    private $remove_from_cart_items = [];

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


        // add_to_cart – capture (works for both AJAX and non-AJAX)
        add_action('woocommerce_add_to_cart', [$this, 'capture_add_to_cart'], 10, 6);

        // add_to_cart – output for non-AJAX (page reload)
        add_action('wp_footer', [$this, 'output_add_to_cart'], 30);

        // add_to_cart – AJAX support via fragments
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'add_to_cart_fragments'], 10, 1);

        // add_to_cart – JavaScript for AJAX handling
        add_action('wp_footer', [$this, 'add_to_cart_ajax_script'], 10);


        // remove_from_cart – capture BEFORE item is removed (to get product data)
        add_action('woocommerce_remove_cart_item', [$this, 'capture_remove_from_cart'], 10, 2);

        // remove_from_cart – output (non-AJAX)
        add_action('wp_footer', [$this, 'output_remove_from_cart'], 35);

        // remove_from_cart – AJAX: add to get_refreshed_fragments response
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'remove_from_cart_fragments'], 10, 1);

        // remove_from_cart – JS handler
        add_action('wp_footer', [$this, 'remove_from_cart_ajax_script'], 15);

        // begin_checkout
        add_action('wp_footer', [$this, 'begin_checkout_event'], 20);

        // purchase - зберегти дані при створенні замовлення
        add_action('woocommerce_checkout_order_processed', [$this, 'store_purchase_data'], 10, 1);

        // purchase - вивести JS для обробки AJAX checkout
        add_action('wp_footer', [$this, 'purchase_ajax_script'], 25);

        // purchase - AJAX endpoint для отримання даних
        add_action('wp_ajax_ga4_get_purchase_data', [$this, 'ajax_get_purchase_data']);
        add_action('wp_ajax_nopriv_ga4_get_purchase_data', [$this, 'ajax_get_purchase_data']);
    }

    /* ======================================================
     * VIEW ITEM LIST – SHOP / CATEGORY / SEARCH
     * ====================================================== */

    public function view_item_list_shop() {
        if (!is_shop() || is_search()) return;
        $this->output_view_item_list('shop_page');
    }

    public function view_item_list_category() {
        if (!is_product_category()) return;
        $this->output_view_item_list('category_page');
    }

    public function view_item_list_search() {
        if (!is_search()) return;
        $this->output_view_item_list('search_page');
    }

    /* ======================================================
     * VIEW ITEM LIST – HOMEPAGE SHORTCODES
     * ====================================================== */

    public function capture_homepage_products_shortcode($query_args, $atts, $type) {

        if (!is_front_page() && !is_home()) return $query_args;
        if ($type !== 'products' || empty($atts['category'])) return $query_args;

        add_action('wp_footer', function () use ($query_args, $atts) {

            $query = new WP_Query($query_args);
            if (!$query->have_posts()) return;

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

    /* ======================================================
     * VIEW ITEM – SINGLE PRODUCT
     * ====================================================== */

    public function view_item_single_product() {

        if (!is_product()) return;

        global $product;
        if (!$product instanceof WC_Product) return;

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

    /* ======================================================
     * ADD TO CART – CAPTURE (GLOBAL)
     * ====================================================== */

    public function capture_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {

        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return;

        [$cat1, $cat2] = $this->get_product_categories($product);

        $this->add_to_cart_items[] = [
            'item_id'        => (string) $product->get_id(),
            'item_name'      => $product->get_name(),
            'price'          => (float) $product->get_price(),
            'item_brand'     => $product->get_attribute('pa_brand') ?: '',
            'item_category'  => $cat1,
            'item_category2' => $cat2,
            'item_variant'   => $product->get_attribute('pa_color') ?: '',
            'quantity'       => (int) $quantity,
            'google_business_vertical' => 'retail',
        ];
    }

    /* ======================================================
     * ADD TO CART – OUTPUT
     * ====================================================== */

    public function output_add_to_cart() {

        if (empty($this->add_to_cart_items)) {
            return;
        }

        $this->print_datalayer('add_to_cart', $this->add_to_cart_items);
    }

    /* ======================================================
     * ADD TO CART – AJAX FRAGMENTS SUPPORT
     * ====================================================== */

    public function add_to_cart_fragments($fragments) {

        if (empty($this->add_to_cart_items)) {
            return $fragments;
        }

        // Return data as JSON in a hidden div (not a script to avoid auto-execution)
        $fragments['div#ga4-add-to-cart-data'] = '<div id="ga4-add-to-cart-data" style="display:none;" data-items="' . esc_attr(wp_json_encode($this->add_to_cart_items, JSON_UNESCAPED_UNICODE)) . '"></div>';

        // Clear the items after adding to fragments
        $this->add_to_cart_items = [];

        return $fragments;
    }

    /* ======================================================
     * ADD TO CART – AJAX JAVASCRIPT HANDLER
     * ====================================================== */

    public function add_to_cart_ajax_script() {
        ?>
        <div id="ga4-add-to-cart-data" style="display:none;"></div>
        <script>
            (function() {
                // Listen for WooCommerce AJAX add to cart events
                jQuery(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button) {
                    // Check if our data fragment exists
                    if (fragments && fragments['div#ga4-add-to-cart-data']) {
                        var tempDiv = document.createElement('div');
                        tempDiv.innerHTML = fragments['div#ga4-add-to-cart-data'];
                        var dataEl = tempDiv.querySelector('#ga4-add-to-cart-data');
                        
                        if (dataEl && dataEl.dataset.items) {
                            try {
                                var items = JSON.parse(dataEl.dataset.items);
                                if (items && items.length > 0) {
                                    window.dataLayer = window.dataLayer || [];
                                    dataLayer.push({ ecommerce: null });
                                    dataLayer.push({
                                        event: "add_to_cart",
                                        ecommerce: {
                                            currency: "UAH",
                                            items: items
                                        }
                                    });
                                }
                            } catch (e) {
                                console.error('GA4 add_to_cart error:', e);
                            }
                        }
                    }
                });
            })();
        </script>
        <?php
    }

    /* ======================================================
     * REMOVE FROM CART – CAPTURE (BEFORE REMOVAL)
     * ====================================================== */

    public function capture_remove_from_cart($cart_item_key, $cart) {

        // Get cart contents directly (item still exists at this point)
        $cart_contents = $cart->cart_contents;
        
        if (empty($cart_contents[$cart_item_key])) {
            return;
        }
        
        $cart_item = $cart_contents[$cart_item_key];
        $product = $cart_item['data'] ?? null;
        
        if (!$product instanceof WC_Product) {
            return;
        }

        [$cat1, $cat2] = $this->get_product_categories($product);

        $item_data = [
            'item_id'       => (string) $product->get_id(),
            'item_name'     => $product->get_name(),
            'price'         => (float) $product->get_price(),
            'item_brand'    => $product->get_attribute('pa_brand') ?: '',
            'item_category' => $cat1,
            'item_category2' => $cat2,
            'item_variant'  => $product->get_attribute('pa_color') ?: '',
            'quantity'      => (int) ($cart_item['quantity'] ?? 1),
        ];

        $this->remove_from_cart_items[] = $item_data;

        // Also store in WC session for AJAX fragment retrieval
        if (WC()->session) {
            $session_items = WC()->session->get('ga4_remove_from_cart_items', []);
            $session_items[] = $item_data;
            WC()->session->set('ga4_remove_from_cart_items', $session_items);
        }
    }


    /* ======================================================
     * REMOVE FROM CART – OUTPUT
     * ====================================================== */

    public function output_remove_from_cart() {

        // Try class property first, then WC session
        $items = $this->remove_from_cart_items;
        
        if (empty($items) && WC()->session) {
            $items = WC()->session->get('ga4_remove_from_cart_items', []);
        }

        if (empty($items)) {
            return;
        }

        $this->print_datalayer('remove_from_cart', $items);
        
        // Clear both storage locations
        $this->remove_from_cart_items = [];
        if (WC()->session) {
            WC()->session->set('ga4_remove_from_cart_items', []);
        }
    }

    /* ======================================================
     * REMOVE FROM CART – AJAX FRAGMENTS SUPPORT
     * ====================================================== */

    public function remove_from_cart_fragments($fragments) {

        // Try to get items from class property first, then from WC session
        $items = $this->remove_from_cart_items;
        
        if (empty($items) && WC()->session) {
            $items = WC()->session->get('ga4_remove_from_cart_items', []);
        }

        if (!empty($items)) {
            $fragments['div#ga4-remove-from-cart-data'] =
                '<div id="ga4-remove-from-cart-data" style="display:none;" data-items="' .
                esc_attr(wp_json_encode($items, JSON_UNESCAPED_UNICODE)) .
                '"></div>';
            
            // Clear both storage locations
            $this->remove_from_cart_items = [];
            if (WC()->session) {
                WC()->session->set('ga4_remove_from_cart_items', []);
            }
        } else {
            $fragments['div#ga4-remove-from-cart-data'] =
                '<div id="ga4-remove-from-cart-data" style="display:none;"></div>';
        }

        return $fragments;
    }

    /* ======================================================
     * REMOVE FROM CART – AJAX JAVASCRIPT HANDLER
     * ====================================================== */

    public function remove_from_cart_ajax_script() {
        ?>
        <div id="ga4-remove-from-cart-data" style="display:none;"></div>
            <script>
            (function() {

                jQuery(document.body).on('removed_from_cart', function(event, fragments) {

                    if (!fragments || !fragments['div#ga4-remove-from-cart-data']) return;

                    var temp = document.createElement('div');
                    temp.innerHTML = fragments['div#ga4-remove-from-cart-data'];

                    var el = temp.querySelector('#ga4-remove-from-cart-data');
                    if (!el || !el.dataset.items) return;

                    try {
                        var items = JSON.parse(el.dataset.items);
                        if (!items.length) return;

                        window.dataLayer = window.dataLayer || [];
                        dataLayer.push({ ecommerce: null });
                        dataLayer.push({
                            event: "remove_from_cart",
                            ecommerce: {
                                currency: "UAH",
                                items: items
                            }
                        });

                    } catch (e) {
                        console.error('GA4 remove_from_cart error:', e);
                    }
                });

            })();
            </script>
        <?php
    }

    /* ======================================================
     * CORE VIEW ITEM LIST
     * ====================================================== */

    private function output_view_item_list($context) {
        global $wp_query;
        if (empty($wp_query->posts)) return;

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

    /* ======================================================
     * CORE BEGIN CHECKOUT
     * ====================================================== */

    public function begin_checkout_event() {

        if (!is_checkout() || is_order_received_page()) {
            return;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $items = [];

        foreach (WC()->cart->get_cart() as $cart_item) {

            $product = $cart_item['data'] ?? null;
            if (!$product instanceof WC_Product) continue;

            [$cat1, $cat2] = $this->get_product_categories($product);

            $items[] = [
                'item_id'        => (string) $product->get_id(),
                'item_name'      => $product->get_name(),
                'price'          => (float) $product->get_price(),
                'item_brand'     => $product->get_attribute('pa_brand') ?: '',
                'item_category'  => $cat1,
                'item_category2' => $cat2,
                'item_variant'   => $product->get_attribute('pa_color') ?: '',
                'quantity'       => (int) ($cart_item['quantity'] ?? 1),
                'google_business_vertical' => 'retail',
            ];
        }

        if (empty($items)) return;
            ?>
            <script>
                (function() {

                    // fire only once per session
                    if (sessionStorage.getItem('ga4_begin_checkout_fired')) {
                        return;
                    }

                    sessionStorage.setItem('ga4_begin_checkout_fired', '1');

                    window.dataLayer = window.dataLayer || [];
                    dataLayer.push({ ecommerce: null });
                    dataLayer.push({
                        event: "begin_checkout",
                        ecommerce: {
                            currency: "UAH",
                            items: <?php echo wp_json_encode($items, JSON_UNESCAPED_UNICODE); ?>
                        }
                    });

                })();
            </script>
            <?php
    }

    /**
     * Зберігає дані purchase для AJAX-передачі
     */
    public function store_purchase_data($order_id) {

        if (!$order_id) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $items = [];

        foreach ($order->get_items() as $item) {

            $product = $item->get_product();
            if (!$product) continue;

            [$cat1, $cat2] = $this->get_product_categories($product);

            $items[] = [
                'item_id'        => (string) $product->get_id(),
                'item_name'      => $product->get_name(),
                'price'          => (float) $order->get_item_total($item, false),
                'item_brand'     => $product->get_attribute('pa_brand') ?: '',
                'item_category'  => $cat1,
                'item_category2' => $cat2,
                'item_variant'   => $product->get_attribute('pa_color') ?: '',
                'quantity'       => (int) $item->get_quantity(),
                'google_business_vertical' => 'retail',
            ];
        }

        if (empty($items)) return;

        $purchase_data = [
            'transaction_id' => $order->get_order_number(),
            'value'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'items'          => $items,
        ];

        // Зберігаємо в сесію WooCommerce
        if (WC()->session) {
            WC()->session->set('ga4_purchase_data', $purchase_data);
        }
    }

    /**
     * JavaScript для відправки purchase події при успішному AJAX checkout
     */
    public function purchase_ajax_script() {
        if (!is_checkout()) return;
        ?>
        <script>
            (function($) {
                'use strict';

                var ga4PurchaseSent = false;

                // Функція для відправки purchase події
                function sendPurchaseEvent() {
                    if (ga4PurchaseSent) return;

                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'ga4_get_purchase_data'
                        },
                        success: function(data) {
                            if (data && data.success && data.data) {
                                window.dataLayer = window.dataLayer || [];
                                dataLayer.push({ ecommerce: null });
                                dataLayer.push({
                                    event: 'purchase',
                                    ecommerce: data.data
                                });
                                console.log('GA4 purchase event pushed', data.data);
                                ga4PurchaseSent = true;
                            }
                        },
                        error: function() {
                            console.error('GA4 purchase: failed to get data');
                        }
                    });
                }

                // Перехоплення через jQuery AJAX global events
                $(document).ajaxComplete(function(event, xhr, settings) {
                    // Перевіряємо чи це checkout запит
                    if (settings.url && settings.url.indexOf('wc-ajax=checkout') !== -1) {
                        try {
                            var response = xhr.responseJSON || JSON.parse(xhr.responseText);
                            if (response && response.result === 'success') {
                                sendPurchaseEvent();
                            }
                        } catch (e) {
                            // Ignore parse errors
                        }
                    }
                });

            })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX endpoint для отримання даних purchase
     */
    public function ajax_get_purchase_data() {
        $data = null;

        if (WC()->session) {
            $data = WC()->session->get('ga4_purchase_data');
            // Очищаємо після отримання
            WC()->session->set('ga4_purchase_data', null);
        }

        if ($data) {
            wp_send_json_success($data);
        } else {
            wp_send_json_error('No purchase data found');
        }
    }

    /* ======================================================
     * HELPERS
     * ====================================================== */

    private function print_datalayer($event, $items) {
        if (empty($items)) return;
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

    private function get_product_categories($product) {

        // Для варіацій - отримуємо категорії батьківського товару
        $product_id = $product->get_id();
        
        if ($product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $product_id = $parent_id;
            }
        }

        $terms = get_the_terms($product_id, 'product_cat');
        $cat1 = '';
        $cat2 = '';

        if ($terms && !is_wp_error($terms)) {
            // Спочатку шукаємо кореневу категорію (parent == 0)
            foreach ($terms as $term) {
                if ($term->parent == 0) {
                    $cat1 = $term->name;
                    break;
                }
            }
            
            // Якщо кореневої категорії немає - беремо першу доступну
            if (empty($cat1) && !empty($terms)) {
                $first_term = reset($terms);
                $cat1 = $first_term->name;
            }
            
            // Шукаємо другу категорію (відмінну від першої)
            foreach ($terms as $term) {
                if ($term->name !== $cat1) {
                    $cat2 = $term->name;
                    break;
                }
            }
        }

        return [$cat1, $cat2];
    }

    private function get_list_id($context) {
        return match ($context) {
            'shop_page'     => 'shop_page',
            'category_page' => 'category_page',
            'search_page'   => 'search_page',
        };
    }

    private function get_list_name($context) {
        return match ($context) {
            'shop_page'     => 'Shop Page',
            'category_page' => 'Category Page',
            'search_page'   => 'Search Page',
        };
    }
}

new WC_GA4_Ecommerce_Events();
