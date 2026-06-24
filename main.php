<?php
/**
 * Plugin Name: Match Your Pool - World Pools
 * Description: คำนวณปริมาตรสระน้ำเพื่อหาสินค้าที่เหมาะสม
 * Version: 1.0
 * Author: Jirakit Pawnsakunrungrot
 * Author URI: https://www.linkedin.com/in/sunny-jirakit
 * Plugin URI: https://github.com/sunny420x/match-your-pool
 */

//Deny access from URL.
if ( ! defined( 'ABSPATH' ) ) exit;

function match_your_pool_enqueue_assets() {
    //Load CSS
    if ( is_page( 'match-your-pool' ) ) {
        wp_enqueue_style( 
            'match_your_pool_style', 
            plugins_url( '/css/style.css', __FILE__ ), 
            array(), 
            time() 
        );

        wp_enqueue_script(
            'match_your_pool_js', 
            plugins_url( '/js/main.js', __FILE__ ), 
            array('jquery'), 
            time(), 
            true 
        );

        wp_localize_script( 'match_your_pool_js', 'wpPoolConfig', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'match_your_pool_recommend' )
        ));

        wp_enqueue_script(
            'sweetalert2-js', 
            'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js', 
            array(), 
            '11', 
            true // Loads in the footer for optimal page speed
        );
    }
    
}

register_activation_hook( __FILE__, 'match_your_pool_plugin_install' );

function match_your_pool_plugin_install() {
    global $wpdb;

    $myp_products_table = $wpdb->prefix . 'myp_products';
    $charset_collate = $wpdb->get_charset_collate();

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    
    // สร้างตาราง myp_products_table
    $sql_history = "CREATE TABLE $myp_products_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        title varchar(200),
        parent_id varchar(200),
        variant_id varchar(200) NOT NULL,
        spec varchar(20) NOT NULL,
        type varchar(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // รัน dbDelta เพื่ออัปเดต/สร้างตาราง
    dbDelta( $sql_history );
}

add_action( 'wp_enqueue_scripts', 'match_your_pool_enqueue_assets' );
add_action('admin_menu', 'match_your_pool_menu');
add_action('wp_ajax_match_your_pool_recommend_products', 'match_your_pool_recommend_products');
add_action('wp_ajax_nopriv_match_your_pool_recommend_products', 'match_your_pool_recommend_products');

function match_your_pool_recommend_products() {
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'match_your_pool_recommend' ) ) {
        wp_send_json_error( 'Invalid request.' );
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        wp_send_json_error( 'WooCommerce is not active.' );
    }

    $flow_rate = isset( $_POST['flow_rate'] ) ? floatval( wp_unslash( $_POST['flow_rate'] ) ) : 0;
    $volume = isset( $_POST['volume'] ) ? intval( wp_unslash( $_POST['volume'] ) ) : 0;
    $turnover = isset( $_POST['turnover'] ) ? floatval( wp_unslash( $_POST['turnover'] ) ) : 0;

    if ( $flow_rate <= 0 ) {
        wp_send_json_error( 'Flow rate is required.' );
    }
    
    $results = match_your_pool_get_recommended_products( $flow_rate, $volume, $turnover, $_POST['mode']);
    wp_send_json_success( $results );
}

function getProductsByFlowRate( $type, $minimum_flow_rate) {
    global $wpdb;
    $products_tables = $wpdb->prefix."myp_products";
    $data = $wpdb->get_results($wpdb->prepare("SELECT title,type,parent_id,variant_id,spec FROM $products_tables WHERE type = %s AND spec IS NOT NULL AND spec > %d", $type, $minimum_flow_rate));
    return $data;
}

function getProductsByType($type) {
    global $wpdb;
    $products_tables = $wpdb->prefix."myp_products";
    $data = $wpdb->get_results($wpdb->prepare("SELECT title,type,parent_id,variant_id,spec FROM $products_tables WHERE type = %s AND spec IS NOT NULL", $type));
    return $data;
}

function getPumpByFlowrate($minimum_flow_rate) {
    return getProductsByFlowRate('pump', $minimum_flow_rate);
}

function getFilterByPumpFlowrate($pump_flowrate) {
    return getProductsByFlowRate('filter', $pump_flowrate);
}

function getPumpsetByFlowrate($minimum_flow_rate) {
    return getProductsByFlowRate('pumpset', $minimum_flow_rate);
}

function getChlorinators() {
    return getProductsByType('chlorinator');
}

function getAutoSelectPumpsetByFlowrate($minimum_flow_rate) {
    $pumps = getProductsByFlowRate('pump', $minimum_flow_rate);
    $filters = getProductsByFlowRate('filter', $minimum_flow_rate);

    usort($pumps, function($a, $b) {
        return $a->spec <=> $b->spec;
    });

    usort($filters, function($a, $b) {
        return $a->spec <=> $b->spec;
    });

    $auto_selection = [
        'pumps' => [],
        'filters' => []
    ];

    // 2. วนลูปจับคู่
    foreach ($pumps as $pump) {
        $match_count = 0; // ตัวนับจำนวนฟิลเตอร์ที่จับคู่กับปั๊มตัวนี้

        foreach ($filters as $filter) {
            // ตรวจสอบเงื่อนไขการใช้งาน (ฟิลเตอร์ต้องรองรับ flow rate ของปั๊มได้)
            if ($pump->spec <= $filter->spec) {
                $auto_selection['pumps'][] = $pump;
                $auto_selection['filters'][] = $filter;
                
                $match_count++;
                
                if ($match_count >= 2) {
                    break; 
                }
            }
        }
    }

    return $auto_selection;
}

function match_your_pool_get_recommended_products($flow_rate, $volume, $turnover, $mode) {
    $recommended_products = [
        'pump'   => [],
        'pumpset'   => [],
        'filter' => [],
        'chlorinator' => [],
        'auto_pumpset' => []
    ];

    if($mode == "onlyPump" || $mode == "all") {
        $pumps = getPumpByFlowrate($flow_rate);
    
        // Helper to format product data
        foreach ($pumps as $pump) {
            $product = wc_get_product($pump->variant_id ?: $pump->parent_id);
            if ($product) {
                $recommended_products['pump'][] = [
                    'title' => $pump->title,
                    'price' => wc_price($product->get_price()),
                    'url'   => get_permalink($pump->parent_id),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'spec'  => $pump->spec,
                    'parent_id' => $pump->parent_id,
                    'variant_id' => $pump->variant_id,
                    'esc_price'=> $product->get_price(),
                    'link'=>$product->get_permalink()
                ];
            }
        }
    }
    if($mode == "onlyFilter" || $mode == "all") {
        $filters = getFilterByPumpFlowrate($flow_rate);

        foreach ($filters as $filter) {
            $product = wc_get_product($filter->variant_id ?: $filter->parent_id);
            if ($product) {
                $recommended_products['filter'][] = [
                    'title' => $filter->title,
                    'price' => wc_price($product->get_price()),
                    'url'   => get_permalink($filter->parent_id),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'spec'  => $filter->spec,
                    'parent_id' => $filter->parent_id,
                    'variant_id' => $filter->variant_id,
                    'esc_price'=> $product->get_price(),
                    'link'=>$product->get_permalink()
                ];
            }
        }
    }
    if($mode == "onlyAutoPumpset" || $mode == "all") {
        $pumpsets = getAutoSelectPumpsetByFlowrate($flow_rate);
        $recommended_products['auto_pumpset'] = [];

        // 1. Loop through pumps and grab their index key ($key)
        foreach ($pumpsets['pumps'] as $key => $pump) {
            // 2. Grab the matching filter using the same key
            $filter = $pumpsets['filters'][$key] ?? null;

            // Get WooCommerce products for both
            $pump_product   = wc_get_product($pump->variant_id ?: $pump->parent_id);
            $filter_product = $filter ? wc_get_product($filter->variant_id ?: $filter->parent_id) : null;

            // Only add to recommendations if both parts of the pair exist
            if ($pump_product && $filter_product) {
                $recommended_products['auto_pumpset'][] = [
                    // Pump Data
                    'pump' => [
                        'title'      => $pump->title,
                        'price'      => wc_price($pump_product->get_price()),
                        'esc_price'  => $pump_product->get_price(),
                        'url'        => get_permalink($pump->parent_id),
                        'image'      => wp_get_attachment_url($pump_product->get_image_id()),
                        'spec'       => $pump->spec,
                        'parent_id'  => $pump->parent_id,
                        'variant_id' => $pump->variant_id,
                    ],
                    // Filter Data
                    'filter' => [
                        'title'      => $filter->title,
                        'price'      => wc_price($filter_product->get_price()),
                        'esc_price'  => $filter_product->get_price(),
                        'url'        => get_permalink($filter->parent_id),
                        'image'      => wp_get_attachment_url($filter_product->get_image_id()),
                        'spec'       => $filter->spec,
                        'parent_id'  => $filter->parent_id,
                        'variant_id' => $filter->variant_id,
                    ],
                    // Combined total price helper (optional, but useful for packages)
                    'total_price' => wc_price($pump_product->get_price() + $filter_product->get_price())
                ];
            }
        }
    }

    if($mode == "onlyPumpset" || $mode == "all") {
        $pumpsets = getPumpsetByFlowrate($flow_rate);

        // Helper to format product data
        foreach ($pumpsets as $pumpset) {
            $product = wc_get_product($pumpset->variant_id ?: $pumpset->parent_id);
            if ($product) {
                $recommended_products['pumpset'][] = [
                    'title' => $pumpset->title,
                    'price' => wc_price($product->get_price()),
                    'url'   => get_permalink($pumpset->parent_id),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'spec'  => $pumpset->spec,
                    'parent_id' => $pumpset->parent_id,
                    'variant_id' => $pumpset->variant_id,
                    'esc_price'=> $product->get_price(),
                    'link'=>$product->get_permalink()
                ];
            }
        }
    }
    if($mode == "onlyChlorinator" || $mode == "all") {
        $chlorinators = getChlorinators();

        foreach ($chlorinators as $chlorinator) {
            $product = wc_get_product($chlorinator->variant_id ?: $chlorinator->parent_id);
            if ($product) {
                // $spec = $chlorinator->spec;
                // $gram_per_hour = explode(",", $spec)[0];
                $gram_per_hour = $chlorinator->spec;
                // $q_start = explode(":", explode(",", $spec)[1])[0];
                // $q_end = explode(":", explode(",", $spec)[count(explode(",", $spec))-1])[0];
                // $turnover_hours_step = count(explode(",", $spec))-1;
                // $turnover_hours = [];
                // $q_arr = [];

                // for($i = 1; $i < $turnover_hours_step; $i++) {
                //     $q_arr[] = explode(":", explode(",", $spec)[$i])[0];
                //     $turnover_hours[] = explode(":", explode(",", $spec)[$i])[1];
                // }

                // if(in_array($turnover, $turnover_hours)) {
                //     $turnover_index = array_search($turnover, $turnover);
                //     if($q_arr[$turnover_index] < $volume) {
                //         continue;
                //     }
                // }

                $target_gram = $volume * 2;
                $hour_synthesized = round(($volume * 2)/$gram_per_hour, 2);
                $distance = abs($turnover - $hour_synthesized);

                if($turnover - 1 > $hour_synthesized) {
                    continue;
                }

                $recommended_products['chlorinator'][] = [
                    'title' => $chlorinator->title,
                    'price' => wc_price($product->get_price()),
                    'url'   => get_permalink($chlorinator->parent_id),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'parent_id' => $chlorinator->parent_id,
                    'variant_id' => $chlorinator->variant_id,
                    'esc_price'=> $product->get_price(),
                    'link'=>$product->get_permalink(),
                    // 'q_start'=>$q_start,
                    // 'q_end'=>$q_end,
                    // 'turnover_hours'=>$turnover_hours,
                    // 'q_arr'=>$q_arr,
                    'gram_per_hour'=>$gram_per_hour,
                    'target_gram'=>$target_gram,
                    'target_volume'=>$volume,
                    'hour_synthesized'=>$hour_synthesized,
                    'distance'=>$distance
                ];
            }
        }
    }

    if(count($recommended_products['pump']) > 1) {
        usort($recommended_products['pump'], fn($a, $b) => $a['spec'] <=> $b['spec']);
    }
    if(count($recommended_products['filter']) > 1) {
        usort($recommended_products['filter'], fn($a, $b) => $a['spec'] <=> $b['spec']);
    }
    if(count($recommended_products['pumpset']) > 1) {
        usort($recommended_products['pumpset'], fn($a, $b) => $a['spec'] <=> $b['spec']);
    }
    if(count($recommended_products['chlorinator']) > 1) {
        usort($recommended_products['chlorinator'], fn($a, $b) => $a['distance'] <=> $b['distance']);
    }

        
    return $recommended_products;
}

add_action( 'wp_ajax_custom_add_to_cart', 'match_your_pool_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_custom_add_to_cart', 'match_your_pool_ajax_add_to_cart' );

function match_your_pool_ajax_add_to_cart() {
    $product_id   = isset($_POST['parent_id']) ? absint( wp_unslash( $_POST['parent_id'] ) ) : 0; 
    $variation_id = isset($_POST['variation_id']) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0; 
    $quantity     = isset($_POST['quantity']) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;     

    if ( ! class_exists( 'WooCommerce' ) || ! WC()->cart ) {
        wp_send_json_error( 'ระบบตะกร้าสินค้าไม่พร้อมใช้งาน' );
    }

    $passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id );
    
    if ( $passed_validation ) {
        $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
        
        if ( $cart_item_key ) {
            // สร้าง Array เริ่มต้นสำหรับเก็บบล็อก HTML ชิ้นส่วนตะกร้า
            $fragments = array();
            
            // เรียกข้อมูล Fragments อย่างปลอดภัยผ่าน WC_AJAX (ถ้ามี)
            if ( class_exists( 'WC_AJAX' ) ) {
                $refreshed = WC_AJAX::get_refreshed_fragments();
                if ( isset( $refreshed['fragments'] ) ) {
                    $fragments = $refreshed['fragments'];
                }
            }

            // ถ้าหา Fragments ไม่เจอจริงๆ ให้สร้างออปชันเสริมผ่านฟิลเตอร์เริ่มต้นของ WooCommerce
            if ( empty( $fragments ) ) {
                $fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );
            }

            wp_send_json_success( array(
                'message'    => 'เพิ่มสินค้าลงตะกร้าเรียบร้อยแล้ว',
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'fragments'  => $fragments
            ) );
        }
    }

    wp_send_json_error( 'ไม่สามารถเพิ่มสินค้าลงตะกร้าได้ (สินค้าอาจหมด หรือติดเงื่อนไข)' );
}

add_action('wp_ajax_custom_add_to_cart_single', 'custom_add_to_cart_single');
add_action('wp_ajax_nopriv_custom_add_to_cart_single', 'custom_add_to_cart_single');

function custom_add_to_cart_single() {
    $parent_id = intval($_POST['parent_id']);
    $variation_id = intval($_POST['variation_id']);

    if (WC()->cart->add_to_cart($parent_id, 1, $variation_id)) {
        wp_send_json_success('Added');
    } else {
        wp_send_json_error('Failed');
    }
}

function match_your_pool_menu() {
    add_menu_page(
        'Match Your Pool Settings', // Title ของหน้า
        'Match Your Pool', // ชื่อเมนูที่โชว์ในแถบข้าง
        'manage_options', //สิทธิ์การเข้าถึง (Admin)
        'pool-calculator-settings', // Slug ของหน้า
        'match_your_pool_settings_page', // ฟังก์ชันที่ใช้พ่น HTML หน้า Setting
        'dashicons-admin-tools', // ไอคอน
        '80' // ตำแหน่งเมนู
    );
}

function match_your_pool_settings_page() {
?>
    <style>
        .white-label-zone {
            width: calc(100% + 20px);
            height: auto;
            background: #fff;
            display: flex;
            margin: 0 0 0 -20px;
        }
        .white-label-zone h1,p {
            padding: 0 20px;
        }
        .container {
            background: #fff; 
            width: 1200px;
        }
        .container h1 {
            display: block;
            font-size: 16px;
            padding: 10px 20px;
            margin: 0 0 20px 0;
            background: #555;
            color: #fff;
        }
        .container p {
            padding: 10px 0;
            margin: 0;
        }
        .leftside {
            width: 350px;
            background: #f8f8f8;
            height: max-content;
        }
        .leftside h1 {
            background: #009FE3;
            color: #fff;
            font-size: 16px;
            padding: 10px 20px;
            margin: 0;
        }
        .leftside a {
            padding: 10px 20px;
            font-size: 14px;
            background: #f8f8f8;
            color: #000;
            transition: .2s ease-in-out;
            display: block;
            width: 100%;
            text-decoration: none;
        }
        .leftside a:hover {
            background: #fff;
            cursor: pointer;
        }
        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
    <div class="white-label-zone no-print">
        <span style="padding: 60px 10px 60px 40px;float: left;font-size: 60px;">📦</span>
        <div style="padding: 20px 0;">
            <h1>Match Your Pool</h1>
            <p>ตั้งค่าการคำนวณปริมาตรสระน้ำและสินค้าที่เหมาะสม
            <br>
            <strong>Github Repository:</strong> <a href="https://github.com/sunny420x/match-your-pool" target="_blank">https://github.com/sunny420x/match-your-pool</a>
            </p>
        </div>
    </div>
    <div class="wrap">
        <div style="display: flex;">
            <div class="leftside">
                <h1>Match Your Pool</h1>
                <a href="admin.php?page=pool-calculator-settings&option=products" style="width: 100%;">🛍️ สินค้า</a>
                <a href="admin.php?page=pool-calculator-settings&option=sync_products" style="width: 100%;">🔄 Sync</a>
                <a href="admin.php?page=pool-calculator-settings" style="width: 100%;">📜 คู่มือการใช้งาน</a>
            </div>
            <div class="container">
                <?php
                if(isset($_GET['option']) && $_GET['option'] == "products") {
                ?>
                <h1>รายการสินค้าทั้งหมดในระบบ Match Your Pool <button class="button button-primary button-small" onclick="window.location.href='admin.php?page=pool-calculator-settings&option=add_product'">เพิ่มสินค้าใหม่</button></h1>
                <div style="padding: 0 25px 25px 25px;">
                    <?php
                    global $wpdb;
                    $products_table = $wpdb->prefix."myp_products";
                    $products = $wpdb->get_results("SELECT id, parent_id, variant_id, title, spec FROM $products_table ORDER BY id ASC");
                    ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <th>Parant</th>
                            <th>Variant</th>
                            <th>ชื่อสินค้า</th>
                            <th>สเปค</th>
                            <th>จัดการ</th>
                        </thead>
                        <tbody>
                            <?php foreach($products as $row) { ?>
                            <tr>
                                <td><?=$row->parent_id?></td>
                                <td><?=$row->variant_id?></td>
                                <td><?=$row->title?></td>
                                <td><?=$row->spec?></td>
                                <td>
                                    <button onclick="window.location.href='admin.php?page=pool-calculator-settings&option=edit_products&id=<?=$row->id?>'" class="button">จัดการ</button>
                                    <button onclick="window.location.href='admin.php?page=pool-calculator-settings&option=delete_products&id=<?=$row->id?>'" class="button">ลบ</button>
                                </td>
                            </tr>
                            <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <?php
                } elseif(isset($_GET['option']) && $_GET['option'] == "edit_products" && isset($_GET['id'])) {
                    global $wpdb;
                    $products_table = $wpdb->prefix."myp_products";

                    if(isset($_POST['editProduct'])) {
                        $id = sanitize_text_field( $_GET['id'] );
                        $title = sanitize_text_field( $_POST['title'] );
                        $parent_id = sanitize_text_field( $_POST['parent_id'] );
                        $variant_id = sanitize_text_field( $_POST['variant_id'] );
                        $spec = sanitize_text_field( $_POST['spec'] );
                        $wpdb->query($wpdb->prepare("UPDATE $products_table SET title = %s, parent_id = %s, variant_id = %s, spec = %s WHERE id = %d", $title, $parent_id, $variant_id, $spec, $id));
                        wp_redirect( admin_url('admin.php?page=pool-calculator-settings&option=edit_products&id='.$id.'&success') );
                        exit;
                    }

                    $product_id = sanitize_text_field( $_GET['id'] );
                    $product = $wpdb->get_row($wpdb->prepare("SELECT id, parent_id, variant_id, title, spec FROM $products_table WHERE id = %d", $product_id));
                ?>
                <h1>แก้ไขสินค้า</h1>
                <div style="padding: 0 25px 25px 25px;">
                    <form action="" method="post">
                        <label for="title">ชื่อสินค้า:</label><br>
                        <input type="text" name="title" id="title" value="<?=$product->title?>" style="width: 100%;">
                        <br>
                        <label for="parent_id">parent_id:</label><br>
                        <input type="text" name="parent_id" id="parent_id" value="<?=$product->parent_id?>" style="width: 100%;">
                        <br>
                        <label for="variant_id">variant_id:</label><br>
                        <input type="text" name="variant_id" id="variant_id" value="<?=$product->variant_id?>" style="width: 100%;">
                        <br>
                        <label for="spec">Spec:</label><br>
                        <input type="text" name="spec" id="spec" value="<?=$product->spec?>" style="width: 100%;">
                        <br><br>
                        <input type="submit" name="editProduct" class="button" value="บันทึกการเปลี่ยนแปลง">
                    </form>
                </div>
                <?php
                } elseif(isset($_GET['option']) && $_GET['option'] == "add_product") {
                    global $wpdb;
                    $products_table = $wpdb->prefix."myp_products";

                    if(isset($_POST['addProduct'])) {
                        $id = sanitize_text_field( $_GET['id'] );
                        $title = sanitize_text_field( $_POST['title'] );
                        $parent_id = sanitize_text_field( $_POST['parent_id'] );
                        $variant_id = sanitize_text_field( $_POST['variant_id'] );
                        $spec = sanitize_text_field( $_POST['spec'] );
                        $wpdb->query($wpdb->prepare("INSERT INTO $products_table(title,parent_id,variant_id,spec) VALUES(%s, %s, %s, %s)", $title, $parent_id, $variant_id, $spec));
                        wp_redirect( admin_url('admin.php?page=pool-calculator-settings&option=products') );
                        exit;
                    }
                ?>
                <h1>เพิ่มสินค้า</h1>
                <div style="padding: 0 25px 25px 25px;">
                    <form action="" method="post">
                        <label for="title">ชื่อสินค้า:</label><br>
                        <input type="text" name="title" id="title" style="width: 100%;">
                        <br>
                        <label for="parent_id">parent_id:</label><br>
                        <input type="text" name="parent_id" id="parent_id" style="width: 100%;">
                        <br>
                        <label for="variant_id">variant_id:</label><br>
                        <input type="text" name="variant_id" id="variant_id" style="width: 100%;">
                        <br>
                        <label for="spec">Spec:</label><br>
                        <input type="text" name="spec" id="spec" style="width: 100%;">
                        <br><br>
                        <input type="submit" name="addProduct" class="button" value="เพิ่มสินค้า">
                    </form>
                </div>
                <?php
                } elseif(isset($_GET['option']) && $_GET['option'] == "sync_products") {
                    global $wpdb;
                    $products_table = $wpdb->prefix."myp_products";
                    $posts_table =  $wpdb->prefix."posts";

                    $product_id = sanitize_text_field( $_GET['id'] );
                    $products = $wpdb->get_results("SELECT DISTINCT 
                        parent.ID AS parent_product_id,
                        variation.ID AS variation_id,
                        variation.post_title as variation_title
                    FROM 
                        $posts_table AS variation
                    INNER JOIN 
                        $posts_table AS parent ON variation.post_parent = parent.ID
                    WHERE 
                        variation.post_type = 'product_variation'
                        AND variation.post_status = 'publish'
                        AND parent.post_type = 'product'
                        AND parent.post_title NOT LIKE '%Parts%'
                        AND parent.post_title NOT LIKE '%Multiport%'
                        AND parent.post_title NOT LIKE '%valve%'
                        AND parent.post_title NOT LIKE '%ไฟ%'
                        AND parent.post_title NOT LIKE '%อะไหล่%';");

                    $existing_products = $wpdb->get_results("SELECT id, parent_id, variant_id, title, spec FROM $products_table");

                    function findExistingProduct($existing_products, $product_id, $variation_id) {
                        foreach($existing_products as $existing_product) {
                            if($variation_id == $existing_product->variant_id && $product_id == $existing_product->parent_id) {
                                return true;
                            }
                        }
                        return false;
                    }

                    if(isset($_POST['importSelected'])) {
                        $import_items = [];
                        foreach ($_POST as $name => $value) {
                            $safe_name = $name;
                            
                            if($safe_name != "importSelected") {
                                if(count(explode(':', $safe_name)) == 3) {
                                    $import_items[] = [
                                        'parent_id' => explode(':', $safe_name)[0],
                                        'variant_id' => explode(':', $safe_name)[1],
                                        'title' => explode(':', $safe_name)[2],
                                    ];
                                }
                            }
                        }

                        foreach( $import_items as $item ) {
                            $wpdb->query($wpdb->prepare("INSERT INTO $products_table (parent_id, variant_id, title) VALUES(%s, %s, %s)", $item['parent_id'], $item['variant_id'], $item['title']));
                        }
                    }
                ?>
                <h1>รายการสินค้าที่ต้องการจะนำเข้า</h1>
                <div style="padding: 0 25px 25px 25px;">
                    <form action="admin.php?page=pool-calculator-settings&option=sync_products" method="post">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <th>นำเข้า</th>
                                <th>Parant</th>
                                <th>Variant</th>
                                <th style="width: 800px;">ชื่อสินค้า</th>
                            </thead>
                            <tbody>
                                <?php foreach($products as $row) {
                                    if(findExistingProduct($existing_products, $row->parent_product_id, $row->variation_id) == false) { 
                                ?>
                                    <tr>
                                        <td><input type="checkbox" name="<?=$row->parent_product_id?>:<?=$row->variation_id?>:<?=$row->variation_title?>" value="1" /></td>
                                        <td><?=$row->parent_product_id?></td>
                                        <td><?=$row->variation_id?></td>
                                        <td><?=$row->variation_title?></td>
                                    </tr>
                                <?php
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                        <button type="submit" class="button" name="importSelected">นำเข้าที่เลือก</button>
                    </form>
                </div>
                <?php
                } else {
                ?>
                <h1>คู่มือการใช้งาน Match Your Pool</h1>
                <div style="padding: 0 25px 25px 25px;">
                    <h2>ระบบนี้คืออะไร ?</h2>
                    <p>
                        ระบบ Match Your Pool เป็นระบบที่ช่วยเลือกอุปกรณ์สระว่ายน้ำ ตามสเปคของสระว่ายน้ำ ได้แก่ ความกว้าง ความยาว ความลึก โดยจะคำนวณ Flow Rate ที่ต้องการ ตามชั่วโมงของ Turnover
                        ซึ่งเมื่อได้ Flow Rate แล้ว จะนำไปเทียบกับข้อมูลในตารางที่ชื่อ myp_products ซึ่งจะมีการเก็บข้อมูล Flow Rate ที่สินค้าต่าง ๆ รองรับ
                    </p>
                    <h2>วิธีการติดตั้ง</h2>
                    <p>
                        สามารถติดตั้งปลั้กอินนี้ได้โดยการดาวน์โหลดไฟล์นี้จาก Github หน้านี้ และอัพโหลดลงในหน้า /wp-admin/plugin-install.php หลังจากอัพโหลด 
                        และเปิดใช้งาน (Activate) ระบบจะทำการสร้างตารางและคอลัมน์ใหม่จากตารางเดิมโดยอัตโนมัติ
                    </p>
                    <h2>สำหรับนักพัฒนาเว็บไซต์</h2>
                    <p>
                        ข้อมูลสินค้าย่อย จะถูกดึงข้อมูลโดยใช้ SQL Query ดังนี้
                    </p>
                    <pre style="background: #333; color: #fff; padding: 10px;"><?= str_replace("                    ", "", "INSERT INTO wpln_myp_products (parent_id, variant_id, title)
                    SELECT DISTINCT 
                        parent.ID AS parent_product_id,
                        variation.ID AS variation_id,
                        variation.post_title as variation_title
                    FROM 
                        wpln_posts AS variation
                    INNER JOIN 
                        wpln_posts AS parent ON variation.post_parent = parent.ID
                    WHERE 
                        variation.post_type = 'product_variation'
                        AND variation.post_status = 'publish'
                        AND parent.post_type = 'product'
                        AND parent.post_title NOT LIKE '%Parts%'
                        AND parent.post_title NOT LIKE '%Multiport%'
                        AND parent.post_title NOT LIKE '%valve%'
                        AND parent.post_title NOT LIKE '%ไฟ%'
                        AND parent.post_title NOT LIKE 'คลอรีน%'
                        AND parent.post_title NOT LIKE '%อะไหล่%';"); ?>
                    </pre>
                </div>
                <?php
                }
                ?>
            </div>
        </div>
    </div>
    <?php
}

function match_your_pool_page() {
?>
<div class="match_your_pool_page">
    <div class="jumbotron">
        <img src="https://www.worldpools.co.th/wp-content/uploads/2026/05/worldpools_logo_trans_small.webp" alt="" class="logo">
        <h1>Match Your Pool</h1>
        <p>เครื่องมือช่วยเลือกอุปกรณ์สระว่ายน้ำที่เหมาะสมกับสระของคุณ</p>
        <ul class="match_your_pool_menu">
            <li id="pool_match_link" onclick="menuNavigation('pool_match')"><span class=icon>🏖️</span>
                <div>
                    <span class="title">Pool Match</span> 
                    <span class="text">แนะนำตามขนาดสระน้ำ</span> 
                </div>    
            </li>
            <li id="flow_match_link" onclick="menuNavigation('flow_match')"><span class=icon>🌊</span> 
                <div>
                    <span class="title">Flow Match</span> 
                    <span class="text">แนะนำสินค้าตาม Flow Rate ที่ต้องการ</span>
                </div>    
            </li>
            <li id="maintenance_link" onclick="menuNavigation('maintenance')"><span class=icon>🔧</span> 
                <div>
                    <span class="title">Maintenance</span> 
                    <span class="text">แนะนำสินค้าช่วยดูแลสระ</span>
                </div>
            </li>
        </ul>
    </div>
    <div class="row input_card" id="pool_size_input">
        <div class="col-lg-8">
            <div class="row input_group" style="margin: 12px 0 0 0;">
                <div class="col-lg">
                    <label for="width">ความกว้าง (เมตร):</label>
                    <input type="number" id="width" value="4" oninput="calculateVolume()" class="form-control">   
                    </div>
                    <div class="col-lg">
                        <label for="length">ความยาว (เมตร):</label>
                        <input type="number" id="length" value="8" oninput="calculateVolume()" class="form-control">   
                    </div>
                    <div class="col-lg">
                        <label for="depth">ความลึกตื้น (เมตร):</label>
                        <input type="number" id="depth_start" value="1" oninput="calculateVolume()" class="form-control">
                    </div>
                    <div class="col-lg">
                        <label for="depth">ความลึกลึก (เมตร):</label>
                        <input type="number" id="depth_end" value="2" oninput="calculateVolume()" class="form-control">
                    </div>
                    <div class="col-lg p-0">
                        <label for="depth">รูปแบบสระ:</label>
                        <select id="pool_shape" class="form-control" onchange="calculateVolume()">
                            <option value="rectangular">สระรูปสี่เหลี่ยม</option>
                            <option value="lshape">สระ L-Shape</option>
                            <option value="oval">สระรูปวงรี</option>
                            <option value="kidney">สระรูปไต</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-lg-4" id="turnover_input">
                <p>ระยะเวลาหมุนเวียนน้ำ (Turnover Time)</p>
                <div class="row turnover-btn-group" style="padding: 0 10px;">
                    <div class="col-lg">
                        <button onclick="setTurnover(4)" class="turnover-btn" id="turnover-4">🕓 4 ชั่วโมง</button>
                    </div>
                    <div class="col-lg">
                        <button onclick="setTurnover(6)" class="turnover-btn" id="turnover-6">🕓 6 ชั่วโมง</button>
                    </div>
                    <div class="col-lg">
                        <button onclick="setTurnover(8)" class="turnover-btn" id="turnover-8">🕓 8 ชั่วโมง</button>
                    </div>
                </div>
            </div>
        </div>
        <div id="pool_match_page">
            <div class="row" id="pool_spec">
                <div class="col-lg result_card">
                    <h4>💦 ปริมาตรสระน้ำ (Volume)</h4>
                    <p id="pool_volume" class="colored"></p>
                </div>
                <div class="col-lg result_card">
                    <h4>🧱 พื้นที่พื้นสระ (Floor Area)</h4>
                    <p id="pool_floor" class="colored"></p>
                </div>
                <div class="col-lg result_card">
                    <h4>💧 อัตราการไหลของน้ำ (Flow Rate)</h4>
                    <p id="pool_flowrate" class="colored"></p>
                </div>
            </div>
            <div class="row" id="recommended_products_specifications"> 
                <div class="col-lg result_card">
                    <h4>🚀 Flow Rate ของปั้มที่ต้องการ</h4>
                    <p class="colored"> ≥ <span id="recommended_pool_pump_flowrate"></span></p>
                </div>
                <div class="col-lg result_card">
                    <h4>🛢️ Flow Rate ของถังกรองที่ต้องการ</h4>
                    <p class="colored"> ≥ <span id="recommended_pool_filter_flowrate"></span></p>
                </div>
                <div class="col-lg result_card">
                    <h4>🤖 หุ่นยนต์ทำความสะอาดสระ</h4>
                    <p class="colored"><span id="recommended_pool_robot_cleaner"></span></p>
                </div>
            </div>
    </div>

    <div id="flow_match_page">
        <script>
            let searchByAttributesFilter = "all";

            function setSearchByAttributesFilter(val) {
                searchByAttributesFilter = val;
                if(val == "onlyPump") {
                    document.getElementById('filterProducts').style.display = "none";
                    document.getElementById('pumpProducts').style.display = "block";
                    document.getElementById('pumpsetProducts').style.display = "block";
                    document.getElementById('chlorinatorProducts').style.display = "none";
                }
                if(val == "onlyFilter") {
                    document.getElementById('filterProducts').style.display = "block";
                    document.getElementById('pumpProducts').style.display = "none";
                    document.getElementById('pumpsetProducts').style.display = "none";
                    document.getElementById('chlorinatorProducts').style.display = "none";
                }
                if(val == "onlyChlorinator") {
                    document.getElementById('filterProducts').style.display = "none";
                    document.getElementById('pumpProducts').style.display = "none";
                    document.getElementById('pumpsetProducts').style.display = "none";
                    document.getElementById('chlorinatorProducts').style.display = "block";
                }
                if(val == "all") {
                    document.getElementById('filterProducts').style.display = "block";
                    document.getElementById('pumpProducts').style.display = "block";
                    document.getElementById('pumpsetProducts').style.display = "block";
                    document.getElementById('autoSelectedPumpsetProducts').style.display = "block";
                    document.getElementById('chlorinatorProducts').style.display = "block";
                }
            }
        </script>
        <div class="row input_card" style="gap: 10px; margin: 12px 0;">
            <div class="col-auto">
                กรอกจำนวน Flow Rate ที่ต้องการ: <br>
                <input type="number" id="search_by_flowrate" oninput="searchByAttributes(this.value, 0, 0, searchByAttributesFilter)" value="8"> m³/h
            </div>
            <div class="col-auto">
                ต้องการกรองสินค้าประเภทไหนบ้าง ? <br>
                <select name="" id="" onchange="setSearchByAttributesFilter(this.value)">
                    <option value="all">ทั้งหมด</option>
                    <option value="onlyPump">เฉพาะปั๊มสระว่ายน้ำ</option>
                    <option value="onlyFilter">เฉพาะถังกรอง</option>
                </select>
            </div>
        </div>
    </div>
    <div id="poolProducts">
        <div id="recommended_products">
            <ul class="match_your_pool_menu">
                <li id="manual_selecting_option" onclick="optionNavigation('manual')">จับคู่ปั๊มและถังกรองเอง</li>
                <li id="ready_to_install_option" onclick="optionNavigation('ready')">ชุดปั๊มและถังกรองสำเร็จรูป</li>
                <li id="auto_selecting_option" onclick="optionNavigation('auto')">จับคู่ปั๊มและถังกรองอัตโนมัติ</li>
            </ul>
            <div class="row" id="manualSelection">
                <div class="card col-lg" id="pumpProducts">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <span id="pool_pump_status">💡 ปั๊มสระว่ายน้ำ (Pool Pumps) <span id="current_required_flowrate"></span></span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_pump_list" class="scroll-container"></div>
                        </div>
                    </div>
                </div>
                <div class="card col-lg" id="filterProducts">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <span id="pool_filter_status">💡 ถังกรองสระว่ายน้ำ (Pool Filters) <span id="current_pump_flowrate"></span></span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_filter_list" class="scroll-container"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="readyToInstall">
                <div class="card col-lg" id="pumpsetProducts">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <span id="pool_pumpset_status">💡 ชุดปั๊มและถังกรองสระว่ายน้ำ <span id="current_required_set_flowrate"></span></span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_pumpset_list" class="scroll-container"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="autoSelection">
                <div class="card col-lg" id="autoSelectedPumpsetProducts">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <span id="pool_auto_pumpset_status">💡 ชุดปั๊มและถังกรองสระว่ายน้ำจับคู่อัตโนมัติ</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_auto_pool_pumpset_list" class="scroll-container"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- <div class="card">
                <div class="card-header" id="headingOne">
                <h2 class="mb-0">
                    <button class="btn btn-link">
                        <span id="pool_robot_status"></span>หุ่นยนต์ทำความสะอาดสระ (Pool Robot Cleaners)
                    </button>
                </h2>
                </div>
                <div id="collapse_pool_robot_cleaner_list" class="collapse" aria-labelledby="headingOne" data-parent="#accordion">
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_robot_cleaner_list"></div>
                            <div id="robotVariations"></div>
                        </div>
                    </div>
                </div>
            </div> -->
            <div class="row" id="chlorinator">
                <div class="card col-lg" id="chlorinatorProducts">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <span id="pool_chlorinator_status">💡 เครื่องผลิตคลอรีนจากเกลือ <span id="current_volume"></span></span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_chlorinator_list" class="scroll-container"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <br>
        <div class="row products_list">
            <div class="table-responsive col-lg-8">
                <table class="table">
                    <thead>
                        <th colspan="2">รายการ</th>
                        <th>สเปค</th>
                        <th>ราคา</th>
                        <th>จัดการ</th>
                    </thead>
                    <tbody id="virtualCartTable">
                        <tr><td colspan='6'>ยังไม่มีอุปกรณ์สระว่ายน้ำที่คุณเลือก...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-lg-4" style="border: 1px solid #eee;">
                <h4>
                    <span id="virtualCartSummery"></span> 
                </h4>
                <button class="btn btn-primary" onclick="addMultipleToCart()" id="addMultipleToCartBtn" style="width: 100%;">เพิ่มลงในตะกร้า</button>
            </div>
        </div>
    </div>
        
    <div id="maintenance_page">
        <div class="row" id="recommended_chemicals">
            <div class="col-lg result_card">
                <h4>⚪ คลอรีน 90%</h4>
                <h5 style="border-left: 2.5px solid #009FE3; padding: 10px; background: #f9f9f95c;">ช่วยฆ่าเชื้อและป้องกันตะไคร่</h5>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_chlorine"></span></p>
            </div>
            <div class="col-lg result_card">
                <h4>🔵 Speed Flocc</h4>
                <h5 style="border-left: 2.5px solid #009FE3; padding: 10px; background: #f9f9f95c;">ช่วยตกตะกอน</h5>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_speedflocc"></span></p>
            </div>
            <div class="col-lg result_card">
                <h4>🟢 Swimtrine</h4>
                <h5 style="border-left: 2.5px solid #009FE3; padding: 10px; background: #f9f9f95c;">ป้องกันตะไคร่เขียว</h5>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_swimtrine"></span></p>
            </div>
            <div class="col-lg result_card">
                <h4>🔵 Cleartrine</h4>
                <h5 style="border-left: 2.5px solid #009FE3; padding: 10px; background: #f9f9f95c;">แก้ปัญหาน้ำขุ่น</h5>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_cleartrine"></span></p>
            </div>
            <div class="col-lg result_card">
                <h4>⚫ Blacktrine</h4>
                <h5 style="border-left: 2.5px solid #009FE3; padding: 10px; background: #f9f9f95c;">กำจัดตะไคร่น้ำดำ</h5>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_blacktrine"></span></p>
            </div>
        </div>
        <div class="recommended_chemicals_products result_card">
            <?php
            $slugs = [
                'คลอรีน-90-ชนิดเม็ด-เกล็ด-chlorine-90',
                'น้ำยาเร่งตกตะกอน-speed-flocc',
                'น้ำยากำจัดตะไคร่น้ำ-swimtrine-plus',
                'น้ำยาป้องกันน้ำขุ่น-cleartrine',
                'black-algaetrine',
            ];

            foreach ($slugs as $slug) {
                // หา ID จาก Slug โดยใช้ฟังก์ชันหลักของ WordPress
                $product_post = get_page_by_path($slug, OBJECT, 'product');
                
                if ($product_post) {
            ?>
                <div><?= do_shortcode('[product id="' . $product_post->ID . '"]'); ?></div>
            <?php
                } else {
                    echo '<p>ไม่พบสินค้า: ' . esc_html($slug) . '</p>';
                }
            }
            ?>
        </div>
    </div>
</div>
<br><br>
<?php
}

add_shortcode( 'match_your_pool_page', 'match_your_pool_page' );

// Get Product and add to custom table

// INSERT INTO wpln_myp_products (parent_id, variant_id, title)
// SELECT DISTINCT 
//     parent.ID AS parent_product_id,
//     variation.ID AS variation_id,
//     variation.post_title as variation_title
// FROM 
//     wpln_posts AS variation
// INNER JOIN 
//     wpln_posts AS parent ON variation.post_parent = parent.ID
// WHERE 
//     variation.post_type = 'product_variation'
//     AND variation.post_status = 'publish'
//     AND parent.post_type = 'product'
//     AND parent.post_title NOT LIKE '%Parts%'
//     AND parent.post_title NOT LIKE '%Multiport%'
//     AND parent.post_title NOT LIKE '%valve%'
//     AND parent.post_title NOT LIKE '%ไฟ%'
//     AND parent.post_title NOT LIKE '%อะไหล่%';