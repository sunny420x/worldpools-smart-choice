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
    $pool_length = isset( $_POST['pool_length'] ) ? floatval( wp_unslash( $_POST['pool_length'] ) ) : 0;
    $pool_floor_area = isset( $_POST['pool_floor_area'] ) ? floatval( wp_unslash( $_POST['pool_floor_area'] ) ) : 0;

    if ( $flow_rate <= 0 ) {
        wp_send_json_error( 'Flow rate is required.' );
    }
    
    $results = match_your_pool_get_recommended_products( $flow_rate, $pool_length, $pool_floor_area, $_POST['mode']);
    wp_send_json_success( $results );
}

function getProductsByFlowRate( $type, $minimum_flow_rate) {
    global $wpdb;
    $products_tables = $wpdb->prefix."myp_products";
    $data = $wpdb->get_results($wpdb->prepare("SELECT title,type,parent_id,variant_id,spec FROM $products_tables WHERE type = %s AND spec IS NOT NULL AND spec > %d", $type, $minimum_flow_rate));
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

function match_your_pool_get_recommended_products($flow_rate, $pool_length, $pool_floor_area, $mode) {
    $recommended_products = [
        'pump'   => [],
        'pumpset'   => [],
        'filter' => []
    ];

    if($mode == "onlyPump") {
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
    } elseif($mode == "onlyFilter") {
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
    } else {
        $pumps = getPumpByFlowrate($flow_rate);
        $filters = getFilterByPumpFlowrate($flow_rate);
        $pumpsets = getPumpsetByFlowrate($flow_rate);

        usort($pumps, fn($a, $b) => $a->spec <=> $b->spec);
        usort($filters, fn($a, $b) => $a->spec <=> $b->spec);
        usort($pumpsets, fn($a, $b) => $a->spec <=> $b->spec);
    
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
        'Pool Calculator Settings', // Title ของหน้า
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
                <a href="admin.php?page=pool-calculator-settings&option=products" style="width: 100%;">สินค้า</a>
                <a href="admin.php?page=pool-calculator-settings" style="width: 100%;">คู่มือการใช้งาน</a>
            </div>
            <div class="container">
                <?php
                if(isset($_GET['option']) && $_GET['option'] == "products") {
                ?>
                <div style="padding: 25px 25px 25px 25px;">
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
                <div style="padding: 25px 25px 25px 25px;">
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
                    <p id="pool_volume"></p>
                </div>
                <div class="col-lg result_card">
                    <h4>🧱 พื้นที่พื้นสระ (Floor Area)</h4>
                    <p id="pool_floor"></p>
                </div>
                <div class="col-lg result_card">
                    <h4>💧 อัตราการไหลของน้ำ (Flow Rate)</h4>
                    <p id="pool_flowrate"></p>
                </div>
            </div>
            <div class="row" id="recommended_products_specifications"> 
                <div class="col-lg result_card">
                    <h4>🚀 Flow Rate ของปั้มที่ต้องการ</h4>
                    <p> ≥ <span id="recommended_pool_pump_flowrate"></span></p>
                </div>
                <div class="col-lg result_card">
                    <h4>🛢️ Flow Rate ของถังกรองที่ต้องการ</h4>
                    <p> ≥ <span id="recommended_pool_filter_flowrate"></span></p>
                </div>
                <div class="col-lg result_card">
                    <h4>🤖 หุ่นยนต์ทำความสะอาดสระ</h4>
                    <p><span id="recommended_pool_robot_cleaner"></span></p>
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
                }
                if(val == "onlyFilter") {
                    document.getElementById('filterProducts').style.display = "block";
                    document.getElementById('pumpProducts').style.display = "none";
                }
                if(val == "all") {
                    document.getElementById('filterProducts').style.display = "block";
                    document.getElementById('pumpProducts').style.display = "block";
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
                <li id="manual_selecting_option" onclick="optionNavigation('manual')">จับคู่ปั๊มและถังกรอง</li>
                <li id="ready_to_install_option" onclick="optionNavigation('ready')">ชุดปั๊มและถังกรองสำเร็จรูป</li>
            </ul>
            <div class="row" id="manualSelection">
                <div class="card col-lg" id="pumpProducts">
                    <div class="card-header" id="headingOne">
                    <h4 class="mb-0">
                        <span id="pool_pump_status">💡 ปั๊มสระว่ายน้ำ (Pool Pumps) <span id="current_required_flowrate"></span></span>
                    </h4>
                    </div>
                    <div id="collapse_pool_pump_list" aria-labelledby="headingOne">
                        <div class="card-body">
                            <div style="display: flex">
                                <div id="recommended_pool_pump_list" class="scroll-container"></div>
                                <div id="pumpVariations"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card col-lg" id="filterProducts">
                    <div class="card-header" id="headingOne">
                    <h4 class="mb-0">
                        <span id="pool_filter_status">💡 ถังกรองสระว่ายน้ำ (Pool Filters) <span id="current_pump_flowrate"></span></span>
                    </h4>
                    </div>
                    <div id="collapse_pool_filter_list" aria-labelledby="headingOne">
                        <div class="card-body">
                            <div style="display: flex">
                                <div id="recommended_pool_filter_list" class="scroll-container"></div>
                                <div id="filterVariations"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row" id="readyToInstall">
                <div class="card col-lg" id="pumpProducts">
                    <div class="card-header" id="headingOne">
                    <h4 class="mb-0">
                        <span id="pool_pump_status">💡 ชุดปั๊มและถังกรองสระว่ายน้ำ <span id="current_required_set_flowrate"></span></span>
                    </h4>
                    </div>
                    <div id="collapse_pool_pump_list" aria-labelledby="headingOne">
                        <div class="card-body">
                            <div style="display: flex">
                                <div id="recommended_pool_pumpset_list" class="scroll-container"></div>
                                <div id="pumpsetVariations"></div>
                            </div>
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
            <div class="col-lg-4">
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
                <h4>🧪 คลอรีน 90%</h4>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_chlorine"></span></p>
            </div>
            <div class="col-lg result_card">
                <h4>🧪 Swimtrine แก้น้ำเขียว</h4>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_swimtrine"></span></p>
            </div>
            <div class="col-lg result_card">
                <h4>🧪 Cleartrine แก้น้ำขุ่น</h4>
                <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_cleartrine"></span></p>
            </div>
        </div>
        <div class="recommended_chemicals_products result_card">
            <?php
            $slugs = [
                'คลอรีน-90-ชนิดเม็ด-เกล็ด-chlorine-90',
                'น้ำยากำจัดตะไคร่น้ำ-swimtrine-plus',
                'น้ำยาป้องกันน้ำขุ่น-cleartrine',
                'liquid-shock-chlorine-ขนาด-1l'
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

    <script>
        // ----- Globle Variables -----
        let pumpSelected = false;
        let filterSelected = false;

        // ----- Navigation System -----
        let page;
        let selecting_option;

        function menuNavigation(target) {
            page = target;
            initMenu();
        }

        function optionNavigation(target) {
            selecting_option = target;
            initMenu();
        }

        function initMenu() {
            if(page == "pool_match") {
                document.getElementById('pool_match_link').classList.add('active')
                document.getElementById('flow_match_link').classList.remove('active')
                document.getElementById('maintenance_link').classList.remove('active')
                
                document.getElementById('flow_match_page').style.display = "none";
                document.getElementById('pool_size_input').style.display = "flex";
                document.getElementById('turnover_input').style.display = "block";
                document.getElementById('maintenance_page').style.display = "none";
                document.getElementById('pool_match_page').style.display = "block";

                if(pumpSelected == false) {
                    document.getElementById('filterProducts').style.display = "none";
                }

                document.getElementById('poolProducts').style.display = "block";
            }

            if(page == "flow_match") {
                document.getElementById('filterProducts').style.display = "block";

                document.getElementById('flow_match_link').classList.add('active')
                document.getElementById('pool_match_link').classList.remove('active')
                document.getElementById('maintenance_link').classList.remove('active')
            
                document.getElementById('pool_match_page').style.display = "none";
                document.getElementById('pool_size_input').style.display = "none";
                document.getElementById('maintenance_page').style.display = "none";
                document.getElementById('flow_match_page').style.display = "block";

                document.getElementById('poolProducts').style.display = "block";
            }

            if(page == "maintenance") {
                document.getElementById('pool_match_link').classList.remove('active')
                document.getElementById('flow_match_link').classList.remove('active')
                document.getElementById('maintenance_link').classList.add('active')

                document.getElementById('pool_match_page').style.display = "none";
                document.getElementById('flow_match_page').style.display = "none";
                document.getElementById('maintenance_page').style.display = "block";
                document.getElementById('pool_size_input').style.display = "block";
                document.getElementById('turnover_input').style.display = "none";
            
                document.getElementById('poolProducts').style.display = "none";
            }

            // In-page Option
            if(selecting_option == "manual") {
                document.getElementById('manual_selecting_option').classList.add('active')
                document.getElementById('ready_to_install_option').classList.remove('active')
                
                document.getElementById('readyToInstall').style.display = "none";
                document.getElementById('manualSelection').style.display = "flex";
            }

            if(selecting_option == "ready") {
                document.getElementById('manual_selecting_option').classList.remove('active')
                document.getElementById('ready_to_install_option').classList.add('active')

                document.getElementById('readyToInstall').style.display = "flex";
                document.getElementById('manualSelection').style.display = "none";
            }
        }

        menuNavigation("pool_match");
        optionNavigation("manual");

        //----- Pool Match System -----
        const wpPoolCalculatorAjaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
        const wpPoolCalculatorNonce = '<?php echo esc_js( wp_create_nonce( 'match_your_pool_recommend' ) ); ?>';

        let width = null;
        let length = null;
        let depth_start = null;
        let depth_end = null;
        let turnover = 6;
        let pool_shape = 'rectangular';

        let virtual_cart = [];

        document.getElementById('pool_spec').style.display = 'none';
        document.getElementById('recommended_products_specifications').style.display = 'none';
        document.getElementById('recommended_products').style.display = 'none';
        document.getElementById('recommended_chemicals').style.display = 'none';
        document.getElementById('addMultipleToCartBtn').style.display = 'none';

        async function fetchRecommendedProducts(flowRate, poolLength, poolFloorArea, mode) {
            const formData = new FormData();
            formData.append('action', 'match_your_pool_recommend_products');
            formData.append('flow_rate', flowRate);
            formData.append('pool_length', poolLength);
            formData.append('pool_floor_area', poolFloorArea);
            formData.append('mode', mode);
            formData.append('security', wpPoolCalculatorNonce);

            const response = await fetch(wpPoolCalculatorAjaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            });
            return response.json();
        }

        function renderProductList(containerId, items, type) {
            const container = document.getElementById(containerId);
            document.getElementById(containerId).classList.add('full');

            if (!container) {
                return;
            }

            if (!items || items.length == 0) {
                return;
            }

            container.innerHTML = items.map(item => {
                const imageHtml = item.image ? `<div class="recommended_image" style="background: url('${item.image}'); background-size: cover;"></div>` : '';
                const metaText = type == "robot" ? `เหมาสำหรับสระน้ำ: ${item.max_length} m` : `FlowRate: ${item.spec} m³/h`;

                return `
                <div class="recommended_item">
                    ${imageHtml}
                <div class="recommended_content">
                    <a class="recommended_title">${item.title}</a>
                    <div class="recommended_meta">${metaText} <br>${item.price || ''}</div>
                    <button class="select_product_btn btn btn-primary" onclick="addToVirtualCart('${item.title}','${item.image}','${item.parent_id}', '${item.variant_id}', '${type}', '${item.spec}', '${item.esc_price}');" style="margin: 10px 0;">✅ เลือก</button>
                    <button class="select_product_btn btn btn-primary" onclick="window.open('${item.link}', '_blank')" style="margin: 10px 0;">ℹ️ ดูข้อมูลเพิ่มเติม</button>
                    </div>
                </div>`;
            }).join('');
        }

        async function calculateVolume() {
            let width = parseFloat(document.getElementById('width').value);
            let length = parseFloat(document.getElementById('length').value);
            let depth_start = parseFloat(document.getElementById('depth_start').value);
            let depth_end = parseFloat(document.getElementById('depth_end').value);
            let pool_shape = document.getElementById('pool_shape').value;
            let mode = 'all';

            // Input Validation
            if (isNaN(width) || isNaN(length) || isNaN(depth_start) || isNaN(depth_end)) {
                document.getElementById('pool_volume').innerText = 'กรุณากรอกตัวเลขที่ถูกต้อง';
                return;
            }

            const depth = (depth_start + depth_end) / 2;
            let volume = width * length * depth;
            let floorArea = width * length;
            let flowRate = volume / turnover; 

            if(pool_shape == "lshape") {
                volume = volume * 0.85;
                floorArea = floorArea * 0.85; // พื้นที่พื้นสระเป็นตารางเมตร
                flowRate = flowRate * 0.85; // ปรับอัตราการไหลตามปริมาตรที่คำนวณใหม่
            }

            if(pool_shape == "circular") {
                volume = volume * 0.785;
                floorArea = floorArea * 0.785; // พื้นที่พื้นสระเป็นตารางเมตร
                flowRate = flowRate * 0.785; // ปรับอัตราการไหลตามปริมาตรที่คำนวณใหม่
            }

            if(pool_shape == "kidney") {
                volume = volume * 0.85;
                floorArea = floorArea * 0.85; // พื้นที่พื้นสระเป็นตารางเมตร
                flowRate = flowRate * 0.85; // ปรับอัตราการไหลตามปริมาตรที่คำนวณใหม่
            }

            document.getElementById('pool_volume').innerText = `${volume.toFixed(2)} m³`;
            document.getElementById('pool_floor').innerText = `${floorArea.toFixed(2)} m²`;
            document.getElementById('pool_flowrate').innerText = `${flowRate.toFixed(2)} m³/h`;
            document.getElementById('recommended_pool_pump_flowrate').innerText = `${flowRate.toFixed(2)} m³/h`;
            document.getElementById('recommended_pool_filter_flowrate').innerText = `${flowRate.toFixed(2)} m³/h`;
            document.getElementById('recommended_pool_robot_cleaner').innerText = `${floorArea.toFixed(0)} m² / ${length.toFixed(0)} m`;

            document.getElementById('recommended_chlorine').innerText = `${(volume * 2).toFixed(1)} ถึง ${(volume * 3).toFixed(1)} กรัม 2-3 ครั้งต่อสัปดาห์`;
            document.getElementById('recommended_swimtrine').innerText = `${((60 / 19) * volume).toFixed(1)} mL ในช่วงแรก ทุก ๆ 7 วัน
            ${((30 / 19) * volume).toFixed(1)} mL เพื่อป้องกัน ทุก ๆ 10 - 14 วัน 
            `;
            document.getElementById('recommended_cleartrine').innerText = `${((60 / 19) * volume).toFixed(1)} mL ทุก 24 ชั่วโมงจนกว่าน้ำจะใส
            ${((30 / 19) * volume).toFixed(1)} mL เพื่อป้องกัน ทุก ๆ 10 - 14 วัน 
            `;

            document.getElementById('pool_spec').style.display = 'flex';
            document.getElementById('recommended_products_specifications').style.display = 'flex';
            document.getElementById('recommended_products').style.display = 'block';
            document.getElementById('recommended_chemicals').style.display = 'flex';

            document.getElementById('current_required_flowrate').innerHTML = "สำหรับสระน้ำที่ต้องการ Flow Rate ≥ "+flowRate.toFixed(2)+" m³/h";

            if(pumpSelected && page == "pool_match") {
                mode = 'onlyFilter'
            }
            
            await searchByAttributes(flowRate, length, floorArea, mode);
        }

        async function searchByAttributes(flowRate, length, floorArea, mode) {
            const result = await fetchRecommendedProducts(flowRate, length, floorArea, mode);
            if ( result && result.success && result.data ) {
                renderProductList('recommended_pool_pump_list', result.data.pump, 'pump');
                renderProductList('recommended_pool_pumpset_list', result.data.pumpset, 'pumpset');
                renderProductList('recommended_pool_filter_list', result.data.filter, 'filter');
                renderProductList('recommended_pool_robot_cleaner_list', result.data.robot, 'robot');
            } else {
                renderProductList('recommended_pool_pump_list', []);
                renderProductList('recommended_pool_filter_list', []);
                renderProductList('recommended_pool_robot_cleaner_list', []);
            }
        }

        function setTurnover(hours) {
            turnover = hours;

            if(hours === 4) {
                document.getElementById('turnover-4').classList.add('active');
                document.getElementById('turnover-6').classList.remove('active');
                document.getElementById('turnover-8').classList.remove('active');
            } else if(hours === 6) {
                document.getElementById('turnover-6').classList.add('active');
                document.getElementById('turnover-4').classList.remove('active');
                document.getElementById('turnover-8').classList.remove('active');
            } else if(hours === 8) {
                document.getElementById('turnover-8').classList.add('active');
                document.getElementById('turnover-4').classList.remove('active');
                document.getElementById('turnover-6').classList.remove('active');
            }

            calculateVolume();
        }

        async function addToCart(product_id, variation_id, type, spec) {
            const params = new URLSearchParams();
            params.append('action', 'custom_add_to_cart'); // เรียก Action ที่เราสร้างใน PHP
            params.append('product_id', product_id);         // ส่ง ID สินค้าหลัก
            params.append('variation_id', variation_id);     // ส่ง ID รุ่นย่อยที่ผู้ใช้เลือก
            params.append('quantity', 1);                  // จำนวนชิ้น

            fetch("/wp-admin/admin-ajax.php", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params
            })
            .then(response => {
                if (!response.ok) throw new Error('Network error');
                return response.json();
            })
            .then(async result => {
                if (result) {
                    document.getElementById('pool_'+type+'_status').innerHTMl = "✅";

                    jQuery(document.body).trigger('added_to_cart', [result.fragments, null, null]);
                    jQuery(document.body).trigger('wc_fragments_refreshed');
                    const quickBadges = document.querySelectorAll('.mini-cart-count, .cart-contents, .count');
                    quickBadges.forEach(badge => {
                        if (badge && !badge.innerHTML.includes('฿')) {
                            badge.innerText = result.cart_count;
                        }
                    });
                } else {
                    alert('เกิดข้อผิดพลาด: ' + result.data);
                }
            })
            .catch(error => {
                console.error('Add to Cart error:', error);
            });
        }

        function toggleProductRecommendation(type) {
            const collapse_pool_pump_list = document.getElementById('collapse_pool_pump_list')
            const collapse_pool_filter_list = document.getElementById('collapse_pool_filter_list')
            const collapse_pool_robot_cleaner_list = document.getElementById('collapse_pool_robot_cleaner_list')

            if(type == "pump") {
                // collapse_pool_pump_list.classList.remove('show');
                collapse_pool_filter_list.classList.add('show');
            }

            if(type == "filter") {
                // collapse_pool_filter_list.classList.remove('show');
                collapse_pool_robot_cleaner_list.classList.add('show');
            }

            if(type == "robot") {
                collapse_pool_robot_cleaner_list.classList.remove('show');
            }
        }

        function backto(type) {
            const collapse_pool_pump_list = document.getElementById('collapse_pool_pump_list')
            const collapse_pool_filter_list = document.getElementById('collapse_pool_filter_list')
            const collapse_pool_robot_cleaner_list = document.getElementById('collapse_pool_robot_cleaner_list')

            collapse_pool_pump_list.classList.remove('show');
            collapse_pool_filter_list.classList.remove('show');
            collapse_pool_robot_cleaner_list.classList.remove('show');

            if(type == "pump") {
                collapse_pool_pump_list.classList.add('show');
            }

            if(type == "filter") {
                collapse_pool_filter_list.classList.add('show');
            }
            
            if(type == "robot") {
                collapse_pool_robot_cleaner_list.classList.add('show');
            }
        }

        async function addToVirtualCart(title, img, product_id, variation_id, type, spec, price) {
            if(type == "pump") {
                if(pumpSelected == true) {
                    virtual_cart = []
                }

                virtual_cart.push({
                    title: title,
                    img: img,
                    parent_id: product_id,
                    variation_id: variation_id,
                    type: type,
                    spec: spec,
                    price: price,
                })

                initVirtualCart();

                Swal.fire({
                    title: 'เลือกปั๊มสระว่ายน้ำแล้ว !',
                    text: 'เลือกถังกรองสระว่ายน้ำต่อได้เลย !',
                    icon: 'success',
                    showConfirmButton: false,
                    timer: 1500
                });

                toggleProductRecommendation(type);

                pumpSelected = true;
                document.getElementById('filterProducts').style.display = "block";
                document.getElementById('current_pump_flowrate').innerHTML = "เหมาะสำหรับปั๊ม Flow Rate = "+spec+" m³/h"

                const result = await fetchRecommendedProducts(spec, 0, 0, 'onlyFilter');
                if ( result && result.success && result.data ) {
                    renderProductList('recommended_pool_filter_list', result.data.filter, 'filter');
                } else {
                    renderProductList('recommended_pool_filter_list', []);
                }
            }

            if(type == "filter" && (pumpSelected || page == "flow_match")) {
                if(filterSelected) {
                    virtual_cart.pop();
                }

                virtual_cart.push({
                    title: title,
                    img: img,
                    parent_id: product_id,
                    variation_id: variation_id,
                    type: type,
                    spec: spec,
                    price: price,
                })
                
                initVirtualCart();
                
                if(pumpSelected) {
                    Swal.fire({
                        title: 'เลือกถังกรองและปั๊มแล้ว !',
                        text: 'กดเพิ่มลงในตะกร้าได้เลย !',
                        icon: 'success',
                        showConfirmButton: false,
                        timer: 1500
                    });
                } else {
                    Swal.fire({
                        title: 'เลือกถังกรองแล้ว !',
                        text: 'กดเพิ่มลงในตะกร้าได้เลย !',
                        icon: 'success',
                        showConfirmButton: false,
                        timer: 1500
                    });
                }

                filterSelected = true;
            }

            if(type == "filter" && !pumpSelected && page != "flow_match") {
                Swal.fire({
                    title: 'โปรดเลือกปั๊มสระว่ายน้ำก่อน !',
                    icon: 'info',
                    showConfirmButton: false,
                    timer: 1500
                });
                return;
            }

            if(type == "pumpset") {
                virtual_cart.push({
                    title: title,
                    img: img,
                    parent_id: product_id,
                    variation_id: variation_id,
                    type: type,
                    spec: spec,
                    price: price,
                })
                
                initVirtualCart();

                Swal.fire({
                    title: 'เลือกปั๊มและถังกรองสระว่ายน้ำแล้ว !',
                    text: 'กดเพิ่มลงในตะกร้าได้เลย !',
                    icon: 'success',
                    showConfirmButton: false,
                    timer: 1500
                });
            }
        }

        function initVirtualCart() {
            let sum = 0

            if(virtual_cart.length == 0) {
                document.getElementById("virtualCartTable").innerHTML = "<tr><td colspan='6'>ยังไม่มีอุปกรณ์สระว่ายน้ำที่คุณเลือก...</td></tr>";
            } else {
                document.getElementById("virtualCartTable").innerHTML = "";
            }

            for(i = 0; i < virtual_cart.length; i++) {
                document.getElementById("virtualCartTable").innerHTML += `
                <tr>
                    <td><img src="${virtual_cart[i].img}"></td>
                    <td>${virtual_cart[i].title}</td>
                    <td>${virtual_cart[i].spec} m³/h</td>
                    <td>${parseInt(virtual_cart[i].price).toLocaleString()} บาท</td>
                    <td><button class="btn" onclick="removeFromVirtualCart('${virtual_cart[i].title}')">❌</td>
                </tr>
                `;
                sum += parseInt(virtual_cart[i].price);
            }

            document.getElementById('virtualCartSummery').innerHTML = `🛒 รวมทั้งสิ้น <b>${sum.toLocaleString()}</b> บาท`;

            if(virtual_cart.length > 0) {
                document.getElementById('addMultipleToCartBtn').style.display = "inline";
            } else {
                document.getElementById('addMultipleToCartBtn').style.display = "none";
            }
        }

        function removeFromVirtualCart(title) {
            let updatedCart = [];
            
            for (let i = 0; i < virtual_cart.length; i++) {
                // Keep items that DO NOT match the title
                if (virtual_cart[i].title != title) {
                    updatedCart.push({
                        title: virtual_cart[i].title,
                        img: virtual_cart[i].img,
                        parent_id: virtual_cart[i].parent_id,
                        variation_id: virtual_cart[i].variation_id,
                        type: virtual_cart[i].type,
                        spec: virtual_cart[i].spec,
                        price: virtual_cart[i].price,
                    });
                }

                if(virtual_cart[i].title == title) {
                    if(virtual_cart[i].type == 'pump') {
                        pumpSelected = false;
                    }
                    if(virtual_cart[i].type == 'filter') {
                        filterSelected = false;
                    }
                }
            }
            
            virtual_cart = updatedCart;
            initVirtualCart();
        }

        async function addMultipleToCart() {
            for (const item of virtual_cart) {
                const params = new URLSearchParams();
                params.append('action', 'custom_add_to_cart'); // สร้าง action สำหรับเพิ่มทีละชิ้น
                params.append('parent_id', item.parent_id);
                params.append('variation_id', item.variation_id);

                try {
                    const response = await fetch("/wp-admin/admin-ajax.php", {
                        method: 'POST',
                        body: params
                    });
                    const result = await response.json();
                    jQuery(document.body).trigger('added_to_cart', [result.fragments, null, null]);
                    const quickBadges = document.querySelectorAll('.mini-cart-count, .cart-contents, .count');
                    quickBadges.forEach(badge => {
                        if (badge && !badge.innerHTML.includes('฿')) {
                            badge.innerText = result.cart_count;
                        }
                    });
                } catch (error) {
                    console.error('Error adding item:', error);
                }
            }
            
            // หลังจากวนลูปครบแล้วค่อยอัปเดต UI ครั้งเดียว
            jQuery(document.body).trigger('wc_fragment_refresh');
        }
        
        function initProductsScroller() {
            // Select ALL elements with the class
            const sliders = document.querySelectorAll('.scroll-container');
            
            sliders.forEach(slider => {
                let isDown = false;
                let startX;
                let scrollLeft;

                slider.addEventListener('mousedown', (e) => {
                    isDown = true;
                    slider.classList.add('active');
                    // Get coordinates relative to THIS specific slider
                    startX = e.pageX - slider.offsetLeft;
                    scrollLeft = slider.scrollLeft;
                });

                slider.addEventListener('mouseleave', () => {
                    isDown = false;
                    slider.classList.remove('active'); // Clean up active class
                });

                slider.addEventListener('mouseup', () => {
                    isDown = false;
                    slider.classList.remove('active'); // Clean up active class
                });

                slider.addEventListener('mousemove', (e) => {
                    if (!isDown) return; 
                    e.preventDefault();
                    
                    // Calculate distance dragged for THIS slider
                    const x = e.pageX - slider.offsetLeft;
                    const walk = (x - startX) * 1; // Multiplier adjusts scroll sensitivity
                    
                    slider.scrollLeft = scrollLeft - walk;
                });
            });
        }

        // เรียกคำนวณครั้งแรกเมื่อโหลดหน้า
        initProductsScroller();
        setTurnover(6);
        calculateVolume();


    </script>
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