<?php
/**
 * Plugin Name: World Pools Calculator
 * Description: คำนวณปริมาตรสระน้ำเพื่อหาสินค้าที่เหมาะสม
 * Version: 1.0
 * Author: Jirakit Pawnsakunrungrot
 * Author URI: https://www.linkedin.com/in/sunny-jirakit
 * Plugin URI: https://github.com/sunny420x/worldpools-calculator
 */

//Deny access from URL.
if ( ! defined( 'ABSPATH' ) ) exit;

function wp_pool_calculator_enqueue_assets() {
    //Load CSS
    wp_enqueue_style(
        'wp_pool_calculator_style', 
        plugins_url( '/css/style.css', __FILE__ ), 
        array(), 
        time()
    );
}

add_action( 'wp_enqueue_scripts', 'wp_pool_calculator_enqueue_assets' );
add_action('admin_menu', 'wp_pool_calculator_menu');
add_action('wp_ajax_wp_pool_calculator_recommend_products', 'wp_pool_calculator_recommend_products');
add_action('wp_ajax_nopriv_wp_pool_calculator_recommend_products', 'wp_pool_calculator_recommend_products');

function wp_pool_calculator_recommend_products() {
    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'wp_pool_calculator_recommend' ) ) {
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

    $results = wp_pool_calculator_get_recommended_products( $flow_rate, $pool_length, $pool_floor_area );
    wp_send_json_success( $results );
}

function wp_pool_calculator_get_recommended_products( $minimum_flow_rate, $pool_length = 0, $pool_floor_area = 0 ) {
    $product_lists = get_option('product_lists', array());
    // สร้าง Array map เพื่อให้หา type จาก slug ได้รวดเร็ว (ไม่ต้องวนลูปซ้ำ)
    $slug_to_profile = [];
    foreach ($product_lists as $p) {
        $slug_to_profile[$p['name']] = $p;
    }
    
    $slugs = array_keys($slug_to_profile);
    if ( empty($slugs) ) return array('pump' => [], 'filter' => [], 'robot' => []);

    $args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'post_name__in'  => $slugs,
        'posts_per_page' => -1,
    );

    $query = new WP_Query( $args );
    $recommended = array('pump' => array(), 'filter' => array(), 'robot' => array());

    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $slug = get_post_field( 'post_name', get_the_ID() );
            $profile = $slug_to_profile[$slug];
            $type = $profile['type'];
            $product = wc_get_product( get_the_ID() );
            $terms   = wp_get_post_terms( get_the_ID(), 'product_cat', array( 'fields' => 'names' ) );

            // Get product content from multiple sources
            $content = wp_strip_all_tags( get_the_content() );
            if ( $product ) {
                $short_desc = $product->get_short_description();
                if ( ! empty( $short_desc ) ) {
                    $content .= ' ' . wp_strip_all_tags( $short_desc );
                }
                // Also check product meta
                $product_meta = get_post_meta( get_the_ID() );
                foreach ( $product_meta as $key => $values ) {
                    if ( is_array( $values ) && ! empty( $values[0] ) ) {
                        $content .= ' ' . wp_strip_all_tags( $values[0] );
                    }
                }
                // Check WooCommerce attributes
                $attributes = $product->get_attributes();
                if ( ! empty( $attributes ) ) {
                    foreach ( $attributes as $attribute ) {
                        if ( is_object( $attribute ) ) {
                            $options = $attribute->get_options();
                            if ( ! empty( $options ) ) {
                                $content .= ' ' . implode( ' ', $options );
                            }
                        } elseif ( is_array( $attribute ) ) {
                            $content .= ' ' . implode( ' ', $attribute );
                        }
                    }
                }
            }

            $thumbnail = get_the_post_thumbnail_url( get_the_ID(), 'medium' );
            if ( ! $thumbnail && function_exists( 'wc_placeholder_img_src' ) ) {
                $thumbnail = wc_placeholder_img_src();
            }

            $product_data = array(
                'id'    => get_the_ID(),
                'name'  => get_the_title(),
                'link'  => get_permalink(),
                'price' => $product ? $product->get_price_html() : '',
                'image' => $thumbnail,
            );

            // For pump and filter: filter by flowrate (check all parsed values)
            if ($type === 'filter' ) {
                $matched_flows = wp_pool_calculator_parse_flow_rate( get_the_ID(), $content, $type );

                if ( !empty( $matched_flows ) ) {
                    $passes = false;
                    
                    // ถ้า $matched_flows มี 2 ค่า (คือ Min, Max จาก product_lists)
                    if ( count($matched_flows) === 2 && $matched_flows[0] > 0 ) {
                        // เช็คว่า Flow ที่ต้องการ อยู่ในช่วงที่สินค้าทำได้ไหม
                        if ( floatval($minimum_flow_rate) <= $matched_flows[1] ) {
                            $passes = true;
                        }
                    } else {
                        // ถ้าเป็นค่าจาก Regex (Array ของตัวเลข)
                        foreach ( $matched_flows as $f ) {
                            if ( floatval( $f ) >= floatval( $minimum_flow_rate ) ) {
                                $passes = true;
                                break;
                            }
                        }
                    }

                    if ( $passes ) {
                        $product_data['flow_rate'] = max( $matched_flows ); // แสดงค่าสูงสุดที่สินค้าทำได้
                        $recommended[ $type ][] = $product_data;
                    }
                }
            }
            elseif ($type === 'pump' ) {
                $matched_flows = wp_pool_calculator_parse_flow_rate( get_the_ID(), $content, $type );

                if ( !empty( $matched_flows ) ) {
                    $passes = false;
                    
                    // ถ้า $matched_flows มี 2 ค่า (คือ Min, Max จาก product_lists)
                    if ( count($matched_flows) === 2 && $matched_flows[0] > 0 ) {
                        // เช็คว่า Flow ที่ต้องการ อยู่ในช่วงที่สินค้าทำได้ไหม
                        if ( floatval($minimum_flow_rate) <= $matched_flows[1] ) {
                            $passes = true;
                        }
                    } else {
                        // ถ้าเป็นค่าจาก Regex (Array ของตัวเลข)
                        foreach ( $matched_flows as $f ) {
                            if ( floatval( $f ) >= floatval( $minimum_flow_rate ) ) {
                                $passes = true;
                                break;
                            }
                        }
                    }

                    if ( $passes ) {
                        $product_data['flow_rate'] = max( $matched_flows ); // แสดงค่าสูงสุดที่สินค้าทำได้
                        $recommended[ $type ][] = $product_data;
                    }
                }
            }
            // For robot: filter by pool size
            elseif ( $type === 'robot' ) {
                if ( $pool_length <= 0 ) continue;

                $max_length = isset($profile['max_flow_rate']) ? floatval($profile['max_flow_rate']) : 0;

                if ( $max_length > 0 && $max_length >= floatval( $pool_length ) ) {
                    $product_data['max_length'] = $max_length;
                    $recommended[ $type ][] = $product_data;
                }
            }
        }
        wp_reset_postdata();
    }

    return $recommended;
}

function wp_pool_calculator_parse_pool_size( $text ) {
    $text = str_replace( array( ',', '\r', '\t' ), array( '.', "\n", ' ' ), $text );
    $clean_text = wp_strip_all_tags( $text );
    $clean_text = preg_replace( '/[ \f\v]+/', ' ', $clean_text );
    $clean_text = preg_replace( '/\n+/', "\n", $clean_text );

    // Try patterns: "ความยาวไม่เกิน 15 เมตร" or "สูงสุด 15 เมตร" or "maximum 15m"
    if ( preg_match( '/(?:ความยาว|สูงสุด|maximum|max|up to)\s*(?:ไม่\s*)?(?:เกิน|\D)?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:mเตอร|เมตร|m|meter)/i', $clean_text, $matches ) ) {
        return floatval( $matches[1] );
    }
    if ( preg_match( '/([0-9]+(?:\.[0-9]+)?)\s*(?:mเตอร|เมตร|m|meter)/i', $clean_text, $matches ) ) {
        return floatval( $matches[1] );
    }
    return null;
}

function wp_pool_calculator_parse_flow_rate( $post_id, $content = '', $type = '' ) {
    // 1. ดึงข้อมูลจาก option ที่เราเก็บไว้ (Priority 1)
    $product_lists = get_option('product_lists', array());
    $slug = get_post_field( 'post_name', $post_id );
    
    // ค้นหาสินค้าใน $product_lists ด้วย slug
    foreach ( $product_lists as $profile ) {
        if ( isset($profile['name']) && $profile['name'] === $slug && isset($profile['type']) && $profile['type'] === $type ) {
            // คืนค่าแบบเป็น Object หรือ Array ที่ระบุรายละเอียดทั้งหมด
            return array(
                'min'  => floatval($profile['min_flow_rate']),
                'max'  => floatval($profile['max_flow_rate']),
                'type' => $profile['type'] // ส่ง type กลับไปด้วยเลย
            );
        }
    }

    // 2. ถ้าไม่เจอใน $product_lists ค่อยมาทำ Regex สแกนเนื้อหา (Priority 2)
    // โค้ด Regex เดิมของคุณเอามาใส่ตรงนี้ได้เลยครับ (ผมตัดมาให้เฉพาะส่วนที่ดึงข้อมูล)
    $text = wp_strip_all_tags($content);
    $flows = array();

    // ตัวอย่าง Regex กวาดหาตัวเลข m3/h
    if ( preg_match_all( '/([0-9]+(?:\.[0-9]+)?)\s*(?:m3|m³)\s*\/\s*h/i', $text, $matches ) ) {
        foreach ( $matches[1] as $m ) { $flows[] = floatval( $m ); }
    }
    
    // ส่งคืนค่า Array ของ Flow ที่เจอ
    return !empty($flows) ? $flows : array();
}

function wp_pool_calculator_classify_product_type( $title, $categories = array() ) {
    $combined = strtolower( $title . ' ' . implode( ' ', $categories ) );

    if ( preg_match( '/pump|ปั้ม|ปั๊ม|pump\b/i', $combined ) ) {
        return 'pump';
    }
    if ( preg_match( '/filter|ถังกรอง|ชุดถังกรองทรายและปั๊ม|ถังกรองทราย|sand|filter\b/i', $combined ) ) {
        return 'filter';
        $total_checked = 0;
        $sample_non_matches = array();
        $sample_matches = array();

    }
    if ( preg_match( '/robot|cleaner|หุ่นยนต์|robot\b|cleaner\b/i', $combined ) ) {
        return 'robot';
    }
    return null;
}

add_action( 'wp_ajax_get_variants_by_id', 'fetch_variants_by_id' );
add_action( 'wp_ajax_nopriv_get_variants_by_id', 'fetch_variants_by_id' );

function fetch_variants_by_id() {
    $product_id = isset($_POST['product_id']) ? absint( $_POST['product_id'] ) : 0;

    if ( ! $product_id ) {
        wp_send_json_error( 'Invalid or missing Product ID.' );
    }

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        wp_send_json_error( 'Product not found.' );
    }

    if ( ! $product->is_type( 'variable' ) ) {
        wp_send_json_error( 'Product is not variable.' );
    }

    $variations = $product->get_available_variations();
    
    wp_send_json_success( $variations ); 
}

add_action( 'wp_ajax_custom_add_to_cart', 'wp_pool_calculator_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_custom_add_to_cart', 'wp_pool_calculator_ajax_add_to_cart' );

function wp_pool_calculator_ajax_add_to_cart() {
    $product_id   = isset($_POST['product_id']) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; 
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

function wp_pool_calculator_menu() {
    add_menu_page(
        'Pool Calculator Settings', // Title ของหน้า
        'ระบบคำนวณสระน้ำ', // ชื่อเมนูที่โชว์ในแถบข้าง
        'manage_options', //สิทธิ์การเข้าถึง (Admin)
        'pool-calculator-settings', // Slug ของหน้า
        'wp_pool_calculator_settings_page', // ฟังก์ชันที่ใช้พ่น HTML หน้า Setting
        'dashicons-admin-tools', // ไอคอน
        '80' // ตำแหน่งเมนู
    );
}

function wp_pool_calculator_settings_page() {
    if (isset($_POST['saveProfile'])) {
        $profiles = get_option('product_lists', array());
        $id = sanitize_text_field($_POST['id']);

        foreach ($profiles as &$profile) {
            if ((string)$profile['id'] === (string)$id) {
                $profile['id'] = $profile['id'];
                $profile['name'] = sanitize_text_field($_POST['name']);
                $profile['type'] = sanitize_text_field($_POST['type']);
                $profile['min_flow_rate'] = floatval($_POST['min_flow_rate']);
                $profile['max_flow_rate'] = floatval($_POST['max_flow_rate']);
                break;
            }
        }

        update_option('product_lists', $profiles);
        wp_redirect(admin_url('admin.php?page=pool-calculator-settings'));
        exit;
    }

    if (isset($_POST['newProfile'])) {
        $profiles = get_option('product_lists', array());

        $profiles[] = array(
            'id' => rand(),
            'name' => sanitize_text_field($_POST['name']),
            'type' => sanitize_text_field($_POST['type']),
            'min_flow_rate' => floatval($_POST['min_flow_rate']),
            'max_flow_rate' => floatval($_POST['max_flow_rate']),
        );

        update_option('product_lists', $profiles);
        wp_redirect(admin_url('admin.php?page=pool-calculator-settings'));
        exit;
    }

    if (isset($_POST['deleteProfile'])) {
        $profiles = get_option('product_lists', array());
        $id = sanitize_text_field($_POST['id']);
        $found = false;

        foreach ($profiles as $index => $profile) {
            if ((string)$profile['id'] === (string)$id) {
                unset($profiles[$index]);
                $found = true;
                break;
            }
        }

        if ($found) {
            $profiles = array_values($profiles);

            update_option('product_lists', $profiles);
            wp_redirect(admin_url('admin.php?page=pool-calculator-settings'));
            exit;
        }
    }
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
    </style>
    <div class="white-label-zone no-print">
        <span style="padding: 60px 10px 60px 40px;float: left;font-size: 60px;">📦</span>
        <div style="padding: 20px 0;">
            <h1>World Pools Calculator</h1>
            <p>ตั้งค่าการคำนวณปริมาตรสระน้ำและสินค้าที่เหมาะสม
            <br>
            <strong>Github Repository:</strong> <a href="https://github.com/sunny420x/worldpools-calculator" target="_blank">https://github.com/sunny420x/worldpools-calculator</a>
            </p>
        </div>
    </div>
    <div class="wrap">
        <div style="padding: 25px 25px 25px 25px;">
            ตัวกรอง (Filter) : 
            <select name="" id="" onchange="window.location.href='admin.php?page=pool-calculator-settings&type='+this.value">
                <option value="">เลือกประเภทสินค้า</option>
                <?php
                $profiles = get_option('product_lists', array());
                $types = [];
                foreach ($profiles as $profile) {
                    $selected = (isset($_GET['type']) && $_GET['type'] === $profile['type']) ? 'selected' : '';
                    if(in_array($profile['type'], $types)) {
                        continue;
                    }
                ?>
                <option value="<?= esc_attr($profile['type']) ?>" <?= $selected ?>><?= esc_html(ucfirst($profile['type'])) ?></option>
                <?php
                    $types[] = $profile['type'];
                }
                ?>
            </select>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <th>#</th>
                    <th>รายการ</th>
                    <th>ประเภท</th>
                    <th>Min</th>
                    <th>Max</th>
                    <th>จัดการ</th>
                </thead>
                <tbody>
                    <?php
                        $replace_list = get_option('product_lists', array());

                        if(isset($_GET['type']) && !empty($_GET['type'])) {
                            $selected_type = sanitize_text_field($_GET['type']);
                            $replace_list = array_filter(get_option('product_lists', array()), function($profile) use ($selected_type) {
                                return isset($profile['type']) && $profile['type'] === $selected_type;
                            });
                        }
                        
                        foreach($replace_list as $row) {
                        ?>
                        <form action="" method="post">
                            <tr>
                                <input type="hidden" name="id" value="<?=$row['id']?>">
                                <td><?=$row['id']?></td>
                                <td><input type="text" value="<?=$row['name']?>" name="name" style="width: 100%;"></td>
                                <td><input type="text" value="<?=$row['type']?>" name="type" style="width: 100%;"></td>
                                <td><input type="text" value="<?=$row['min_flow_rate']?>" name="min_flow_rate" style="width: 100%;"></td>
                                <td><input type="text" value="<?=$row['max_flow_rate']?>" name="max_flow_rate" style="width: 100%;"></td>
                                <td>
                                    <button class="button" name="saveProfile" type="submit">บันทึกการเปลี่ยนแปลง</button>
                                    <button class="button button-primary" name="deleteProfile" type="submit">ลบ</button>
                                </td>
                            </tr>
                        </form>
                        <?php
                        }
                        ?>
                        <form action="" method="post">
                            <tr>
                                <td></td>
                                <td><input type="text" name="name" style="width: 100%;"></td>
                                <td><input type="text" name="type" style="width: 100%;"></td>
                                <td><input type="text" name="min_flow_rate" style="width: 100%;"></td>
                                <td><input type="text" name="max_flow_rate" style="width: 100%;"></td>
                                <td><button class="button" name="newProfile" type="submit">เพิ่มโปรไฟล์ใหม่</button></td>
                            </tr>
                        </form>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function wp_pool_calculator_page() {
?>
<div class="wp_pool_calculator_page">
    <h2>World Pools Smart Choice | ตัวช่วยเลือกอุปกรณ์สระว่ายน้ำ</h2>
    <hr>
    <div class="row">
        <div class="col-lg-8">
            <h3 style="margin-top: 0;">📏 ขนาดของสระน้ำ</h3>
            <div class="row">
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
                <div class="col-lg">
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
        <div class="col-lg-4">
            <h3>🕓 Turnover Time</h3>
            <div class="row turnover-btn-group">
                <div class="col-lg">
                    <button onclick="setTurnover(4)" class="turnover-btn" id="turnover-4">4 ชั่วโมง/รอบ</button>
                </div>
                <div class="col-lg">
                    <button onclick="setTurnover(6)" class="turnover-btn" id="turnover-6">6 ชั่วโมง/รอบ</button>
                </div>
                <div class="col-lg">
                    <button onclick="setTurnover(8)" class="turnover-btn" id="turnover-8">8 ชั่วโมง/รอบ</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row" id="pool_spec">
        <div class="col-lg result_card">
            <h4>ปริมาตรสระน้ำ (Volume)</h4>
            <div id="pool_volume" style="margin-top: 20px; font-size: 18px;"></div>
        </div>
        <div class="col-lg result_card">
            <h4>พื้นที่พื้นสระ (Floor Area)</h4>
            <div id="pool_floor" style="margin-top: 20px; font-size: 18px;"></div>
        </div>
        <div class="col-lg result_card">
            <h4>อัตราการไหลของน้ำ (Flow Rate)</h4>
            <div id="pool_flowrate" style="margin-top: 20px; font-size: 18px;"></div>
        </div>
    </div>
    <div class="row" id="recommended_products_specifications"> 
        <div class="col-lg result_card">
            <h4>Flow Rate ของปั้มที่ต้องการ</h4>
            <p style="font-size: 18px; margin-top: 20px;"> ≥ <span id="recommended_pool_pump_flowrate"></span></p>
        </div>
        <div class="col-lg result_card">
            <h4>Flow Rate ของถังกรองที่ต้องการ</h4>
            <p style="font-size: 18px; margin-top: 20px;"> ≥ <span id="recommended_pool_filter_flowrate"></span></p>
        </div>
        <div class="col-lg result_card">
            <h4>หุ่นยนต์ทำความสะอาดสระ</h4>
            <p style="font-size: 18px; margin-top: 20px;"><span id="recommended_pool_robot_cleaner"></span></p>
        </div>
    </div>

    <div id="recommended_products">
        <h2>🛍️ สินค้าที่แนะนำ</h2>
        <div id="accordion">
            <div class="card">
                <div class="card-header" id="headingOne">
                <h2 class="mb-0">
                    <button class="btn btn-link" onclick="backto('pump')">
                        <span id="pool_pump_status"></span>ปั๊มสระว่ายน้ำ (Pool Pumps)
                    </button>
                </h2>
                </div>
                <div id="collapse_pool_pump_list" class="collapse show" aria-labelledby="headingOne" data-parent="#accordion">
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_pump_list"></div>
                            <div id="pumpVariations"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header" id="headingOne" onclick="backto('filter')">
                <h2 class="mb-0">
                    <button class="btn btn-link">
                        <span id="pool_filter_status"></span>ถังกรองสระว่ายน้ำ (Pool Filters)
                    </button>
                </h2>
                </div>
                <div id="collapse_pool_filter_list" class="collapse" aria-labelledby="headingOne" data-parent="#accordion">
                    <div class="card-body">
                        <div style="display: flex">
                            <div id="recommended_pool_filter_list"></div>
                            <div id="filterVariations"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header" id="headingOne">
                <h2 class="mb-0">
                    <button class="btn btn-link" onclick="backto('robot')">
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
            </div>
        </div>
    </div>

    <script>
        const wpPoolCalculatorAjaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
        const wpPoolCalculatorNonce = '<?php echo esc_js( wp_create_nonce( 'wp_pool_calculator_recommend' ) ); ?>';

        let width = null;
        let length = null;
        let depth_start = null;
        let depth_end = null;
        let turnover = 6;
        let pool_shape = 'rectangular';

        let selectedPump = null;
        let selectedFilter = null;
        let selectedRobotCleaner = null;
        let summery = 0;

        document.getElementById('pool_spec').style.display = 'none';
        document.getElementById('recommended_products_specifications').style.display = 'none'; // ซ่อนส่วนแนะนำสินค้าตั้งแต่เริ่มต้น
        document.getElementById('recommended_products').style.display = 'none'; // ซ่อนส่วนแนะนำสินค้าตั้งแต่เริ่มต้น

        async function fetchRecommendedProducts(flowRate, poolLength, poolFloorArea) {
            const formData = new FormData();
            formData.append('action', 'wp_pool_calculator_recommend_products');
            formData.append('flow_rate', flowRate);
            formData.append('pool_length', poolLength);
            formData.append('pool_floor_area', poolFloorArea);
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
            if (!items || items.length === 0) {
                container.innerHTML = '<p>ไม่พบสินค้าที่ตรงตามเกณฑ์</p>';
                return;
            }
            container.innerHTML = items.map(item => {
                const imageHtml = item.image ? `<div class="recommended_image"><a href="${item.link}" target="_blank" rel="noopener noreferrer"><img src="${item.image}" alt="${item.name}" loading="lazy"></a></div>` : '';
                const metaText = type == "robot" ? `เหมาสำหรับสระน้ำ: ${item.max_length} m` : `FlowRate: ${item.flow_rate} m³/h`;
                return `
                <div class="recommended_item">
                    ${imageHtml}
                <div class="recommended_content">
                    <a href="${item.link}" target="_blank" rel="noopener noreferrer" class="recommended_title">${item.name}</a>
                    <div class="recommended_meta">${metaText} <br>ราคา: ${item.price || ''}</div>
                    <button class="select_product_btn btn btn-outline-primary" onclick="selectProductToGetVariation('${item.id}', '${type}');" style="margin: 10px 0;">เพิ่มลงในตะกร้า</button>
                    </div>
                </div>`;
            }).join('');
        }

        async function calculateVolume() {
            width = parseFloat(document.getElementById('width').value);
            length = parseFloat(document.getElementById('length').value);
            depth_start = parseFloat(document.getElementById('depth_start').value);
            depth_end = parseFloat(document.getElementById('depth_end').value);
            pool_shape = document.getElementById('pool_shape').value;

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

            document.getElementById('pool_spec').style.display = 'flex';
            document.getElementById('recommended_products_specifications').style.display = 'flex';
            document.getElementById('recommended_products').style.display = 'block';

            const result = await fetchRecommendedProducts(flowRate, length, floorArea);
            if ( result && result.success && result.data ) {
                renderProductList('recommended_pool_pump_list', result.data.pump, 'pump');
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

        function selectProductToGetVariation(id, type) {
            const collapse_pool_pump_list = document.getElementById('collapse_pool_pump_list');
            const collapse_pool_filter_list = document.getElementById('collapse_pool_filter_list');
            const collapse_pool_robot_cleaner_list = document.getElementById('collapse_pool_robot_cleaner_list');

            const pumpVariations = document.getElementById('pumpVariations');
            const filterVariations = document.getElementById('filterVariations');
            const robotVariations = document.getElementById('robotVariations');

            const params = new URLSearchParams();
            params.append('action', 'get_variants_by_id');
            params.append('product_id', id);

            fetch("/wp-admin/admin-ajax.php", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json(); // Parse JSON response from WordPress
            })
            .then(result => {
                if (result.success) {
                    console.log('Variations data:', result.data);
                    
                    if(type == "pump") {
                        document.getElementById('recommended_pool_pump_list').classList.remove('full');
                        pumpVariations.classList.add('active');
                        
                        pumpVariations.innerHTML = "";
                        result.data.forEach(data => {                            
                            pumpVariations.innerHTML += `
                            <div style="display: flex;">
                                <img src="${data.image.full_src}" width="150" alt="${data.image.alt}">
                                <div style='width: 500px;'>
                                <h3>${Object.values(data.attributes)[0]}</h3>
                                    <h4>${data.price_html}</h4>
                                    <button class="btn btn-outline-primary" onclick="addVariationsToCart('${id}', '${data.variation_id}', 'pump')">เพิ่มลงในตะกร้า</botton>
                                </div>
                            </div>
                            `;
                        });
                    }
                    if(type == "filter") {
                        document.getElementById('recommended_pool_filter_list').classList.remove('full');
                        filterVariations.classList.add('active');
                        filterVariations.innerHTML = "";
                        result.data.forEach(data => {                            
                            filterVariations.innerHTML += `
                            <div style="display: flex;">
                                <img src="${data.image.full_src}" width="150" alt="${data.image.alt}">
                                <div style='width: 500px;'>
                                <h3>${Object.values(data.attributes)[0]}</h3>
                                    <h4>${data.price_html}</h4>
                                    <button class="btn btn-outline-primary" onclick="addVariationsToCart('${id}', '${data.variation_id}', 'filter')">เพิ่มลงในตะกร้า</botton>
                                </div>
                            </div>
                            `;
                        });
                    }
                    if(type == "robot") {
                        document.getElementById('recommended_pool_robot_list').classList.remove('full');
                        robotVariations.classList.add('active');
                        robotVariations.innerHTML = "";
                        result.data.forEach(data => {                            
                            robotVariations.innerHTML += `
                            <div style="display: flex;">
                                <img src="${data.image.full_src}" width="150" alt="${data.image.alt}">
                                <div style='width: 500px;'>
                                <h3>${Object.values(data.attributes)[0]}</h3>
                                    <h4>${data.price_html}</h4>
                                    <button class="btn btn-outline-primary" onclick="addVariationsToCart('${id}', '${data.variation_id}', 'robot')">เพิ่มลงในตะกร้า</botton>
                                </div>
                            </div>
                            `;
                        });
                    }
                } else {
                    if(result.data == "Product is not variable.") {
                        addVariationsToCart(id, 0, type)
                        toggleProductRecommendation(type);
                        return;
                    }
                    console.error('Error from server:', result.data);
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
            });
        }

        function addVariationsToCart(product_id, variation_id, type) {
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
            .then(result => {
                if (result) {
                    toggleProductRecommendation(type);
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
                collapse_pool_pump_list.classList.remove('show');
                collapse_pool_filter_list.classList.add('show');
            }

            if(type == "filter") {
                collapse_pool_filter_list.classList.remove('show');
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

        // เรียกคำนวณครั้งแรกเมื่อโหลดหน้า
        setTurnover(6);
        calculateVolume();
    </script>
    <br><br>
</div>
<?php
}

add_shortcode( 'wp_pool_calculator_page', 'wp_pool_calculator_page' );