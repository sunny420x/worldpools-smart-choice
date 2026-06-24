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
        document.getElementById('chlorinator').style.display = "block";
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

        document.getElementById('chlorinator').style.display = "none";
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
        document.getElementById('auto_selecting_option').classList.remove('active')
        
        document.getElementById('readyToInstall').style.display = "none";
        document.getElementById('manualSelection').style.display = "flex";
        document.getElementById('autoSelection').style.display = "none";
    }

    if(selecting_option == "ready") {
        document.getElementById('manual_selecting_option').classList.remove('active')
        document.getElementById('auto_selecting_option').classList.remove('active')
        document.getElementById('ready_to_install_option').classList.add('active')

        document.getElementById('readyToInstall').style.display = "flex";
        document.getElementById('manualSelection').style.display = "none";
        document.getElementById('autoSelection').style.display = "none";
    }

    if(selecting_option == "auto") {
        document.getElementById('manual_selecting_option').classList.remove('active')
        document.getElementById('ready_to_install_option').classList.remove('active')
        document.getElementById('auto_selecting_option').classList.add('active')

        document.getElementById('readyToInstall').style.display = "none";
        document.getElementById('manualSelection').style.display = "none";
        document.getElementById('autoSelection').style.display = "flex";
    }
}

menuNavigation("pool_match");
optionNavigation("manual");

//----- Pool Match System -----
const wpPoolCalculatorAjaxUrl = wpPoolConfig.ajax_url;
const wpPoolCalculatorNonce = wpPoolConfig.nonce;

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

async function fetchRecommendedProducts(flowRate, volume, turnover, mode) {
    const formData = new FormData();
    formData.append('action', 'match_your_pool_recommend_products');
    formData.append('flow_rate', flowRate);
    formData.append('volume', volume);
    formData.append('turnover', turnover);
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

    if (!container) {
        return;
    }

    if (!items || items.length == 0) {
        return;
    }

    container.innerHTML = items.map(item => {
        const imageHtml = item.image ? `<div class="recommended_image" style="background: url('${item.image}'); background-size: cover;"></div>` : '';
        let metaText = "";
        if(type == "robot") {
            metaText = `เหมาสำหรับสระน้ำ: ${item.max_length} m`;
        }
        else if(type == "chlorinator") {
            metaText += `ผลิตคลอรีนได้ ${item.gram_per_hour} กรัม/ชั่วโมง`
            metaText += `<br>ต้องผลิตคลอรีน ${item.target_gram} กรัมสำหรับสระ ${item.target_volume} m³`
            metaText += `<br>ใช้เวลาผลิต ${item.hour_synthesized} ชั่วโมง สำหรับสระ ${item.target_volume} m³`
            spec = metaText;
        } else {
            metaText = `FlowRate: ${item.spec} m³/h`;
            spec = item.spec;
        }

        return `
        <div class="recommended_item ${type}">
            ${imageHtml}
            <div class="recommended_content">
                <a class="recommended_title">${item.title}</a>
                <div class="recommended_meta">${metaText} <br>${item.price || ''}</div>
                <div class="recommanded_badge">★ สินค้าแนะนำ</div>
                <button class="select_product_btn btn btn-primary" onclick="addToVirtualCart('${item.title}','${item.image}','${item.parent_id}', '${item.variant_id}', '${type}', '${spec}', '${item.esc_price}');" style="margin: 10px 0;">✅ เลือก</button>
                <button class="select_product_btn btn btn-primary" onclick="window.open('${item.link}', '_blank')" style="margin: 10px 0;">ℹ️ ดูข้อมูลเพิ่มเติม</button>
            </div>
        </div>`;
    }).join('');
}

function autoMatchProduct(containerId, items) {
    const container = document.getElementById(containerId);

    if (!container) {
        return;
    }

    // Check if items array exists and has contents
    if (!items || items.length === 0) {
        container.innerHTML = '<p>No matching packages found.</p>';
        return;
    }

    // Map through the array of pairs
    container.innerHTML = items.map((pair) => {
        const pump = pair.pump;
        const filter = pair.filter;

        // Generate images safely
        const pumpImageHtml = pump.image ? `<div class="recommended_image" style="background: url('${pump.image}'); background-size: cover; width: 80px; height: 80px; margin: 0 auto;"></div>` : '';
        const filterImageHtml = filter.image ? `<div class="recommended_image" style="background: url('${filter.image}'); background-size: cover; width: 80px; height: 80px; margin: 0 auto;"></div>` : '';

        return `
        <div class="recommended_pair_item">
            <div class="pair_components">
                
                <div class="pump_component" style="flex: 1;">
                    ${pumpImageHtml}
                    <div class="recommended_title">${pump.title}</div>
                    <div class="recommended_meta">
                        ${pump.spec} m³/h <br> ${pump.price}
                    </div>
                </div>

                <div class="filter_component" style="flex: 1;">
                    ${filterImageHtml}
                    <div class="recommended_title">${filter.title}</div>
                    <div class="recommended_meta"">
                        ${filter.spec} m³/h <br> ${filter.price}
                    </div>
                </div>

            </div>

            <div class="pair_footer">
                <div class="total_price_label">
                    <span style="font-size: 13px; color:#666;">รวมทั้งชุด:</span> 
                    <strong style="font-size: 18px;">${pair.total_price}</strong>
                </div>
                
                <button class="select_product_btn btn btn-primary" 
                    onclick="addPairToVirtualCart(${JSON.stringify(pump).replace(/"/g, '&quot;')}, ${JSON.stringify(filter).replace(/"/g, '&quot;')});" 
                    style="margin: 0;">
                    ✅ เลือกแพ็คเกจนี้
                </button>
            </div>
            <div class="recommanded_badge">★ สินค้าแนะนำ</div>
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

    document.getElementById('recommended_chlorine').innerHTML = `<span class="colored">${(volume * 2).toFixed(1)} ถึง ${(volume * 3).toFixed(1)}</span> กรัม 2-3 ครั้งต่อสัปดาห์`;
    document.getElementById('recommended_speedflocc').innerHTML = `<span class="colored">${(10 * volume).toFixed(1)} mL</span> เพื่อช่วยตกตะกอน`;
    document.getElementById('recommended_swimtrine').innerHTML = `<span class="colored">${((60 / 19) * volume).toFixed(1)} mL</span> ในช่วงแรก ทุก ๆ 7 วัน<br>
    <span class="colored">${((30 / 19) * volume).toFixed(1)} mL</span> เพื่อป้องกัน ทุก ๆ 10 - 14 วัน 
    `;
    document.getElementById('recommended_cleartrine').innerHTML = `<span class="colored">${((60 / 19) * volume).toFixed(1)} mL</span> ทุก 24 ชั่วโมงจนกว่าน้ำจะใส<br>
    <span class="colored">${((30 / 19) * volume).toFixed(1)} mL</span> เพื่อป้องกัน ทุก ๆ 10 - 14 วัน 
    `;

    document.getElementById('recommended_blacktrine').innerHTML = `<span class="colored">${((180 / 19) * volume).toFixed(1)} mL</span> ทุก ๆ 5 วัน<br>
    <span class="colored">${((90 / 19) * volume).toFixed(1)} mL</span> เพื่อป้องกัน ทุก ๆ 7 วัน 
    `;

    document.getElementById('pool_spec').style.display = 'flex';
    document.getElementById('recommended_products_specifications').style.display = 'flex';
    document.getElementById('recommended_products').style.display = 'block';
    document.getElementById('recommended_chemicals').style.display = 'flex';

    document.getElementById('current_required_flowrate').innerHTML = "สำหรับสระน้ำที่ต้องการ Flow Rate ≥ "+flowRate.toFixed(2)+" m³/h";

    if(pumpSelected && page == "pool_match") {
        mode = 'onlyFilter'
    }
    
    await searchByAttributes(flowRate, volume.toFixed(0), turnover, mode);
}

async function searchByAttributes(flowRate, volume, turnover, mode) {
    const result = await fetchRecommendedProducts(flowRate, volume, turnover, mode);
    if ( result && result.success && result.data ) {
        renderProductList('recommended_pool_pump_list', result.data.pump, 'pump');
        renderProductList('recommended_pool_pumpset_list', result.data.pumpset, 'pumpset');
        renderProductList('recommended_pool_filter_list', result.data.filter, 'filter');
        renderProductList('recommended_pool_chlorinator_list', result.data.chlorinator, 'chlorinator');
        renderProductList('recommended_pool_robot_cleaner_list', result.data.robot, 'robot');
        
        autoMatchProduct('recommended_auto_pool_pumpset_list', result.data.auto_pumpset);
    } else {
        renderProductList('recommended_pool_pump_list', []);
        renderProductList('recommended_pool_filter_list', []);
        renderProductList('recommended_pool_chlorinator_list', []);
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
    const pool_pump_list = document.getElementById('pumpProducts')
    const pool_filter_list = document.getElementById('filterProducts')

    if(type == "pump") {
        pool_filter_list.style.display = "flex";
    }
}

async function addToVirtualCart(title, img, product_id, variation_id, type, spec, price) {
    if(type == "pump") {
        if(pumpSelected == true) {
            virtual_cart = []
        }

        Swal.fire({
            title: 'เลือกปั๊มสระว่ายน้ำแล้ว !',
            text: 'เลือกถังกรองสระว่ายน้ำต่อได้เลย !',
            icon: 'success',
            showConfirmButton: false,
            timer: 1500
        });
        
        addVirtualCart();
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

        addVirtualCart();

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
        addVirtualCart();

        Swal.fire({
            title: 'เลือกปั๊มและถังกรองสระว่ายน้ำแล้ว !',
            text: 'กดเพิ่มลงในตะกร้าได้เลย !',
            icon: 'success',
            showConfirmButton: false,
            timer: 1500
        });
    }

    if(type == "chlorinator") {
        addVirtualCart();

        Swal.fire({
            title: 'เลือกเครื่องผลิตคลอรีนแล้ว !',
            text: 'กดเพิ่มลงในตะกร้าได้เลย !',
            icon: 'success',
            showConfirmButton: false,
            timer: 1500
        });   
    }
    
    function addVirtualCart() {                
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
    }
}

function addPairToVirtualCart(pump, filter) {
    addToVirtualCart(pump.title, pump.image, pump.parent_id, pump.variation_id, "pump", pump.spec, pump.esc_price);
    addToVirtualCart(filter.title, filter.image, filter.parent_id, filter.variation_id, "filter", filter.spec, filter.esc_price);
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
            <td style="padding: 0; width: 80px;"><img src="${virtual_cart[i].img}"></td>
            <td>${virtual_cart[i].title}</td>
            <td>${virtual_cart[i].type != "chlorinator" ? virtual_cart[i].spec + "m³/h" : "-"}</td>
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
initVirtualCart();