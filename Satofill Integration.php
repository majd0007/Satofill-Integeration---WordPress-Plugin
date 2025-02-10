<?php
/*
Plugin Name: SatoFill Integration
Description: Integrate SatoFill API with WooCommerce.
Version: 1.0
Author: Majd Zahra
*/
function fetch_products_from_api() {
    $url = 'https://satofill.com/wp-json/mystore/v1/products/';
    $api_token = 'YOUR API TOKEN'; // استبدل YOUR_API_TOKEN بالتوكن الخاص بك

    $response = wp_remote_get($url, array(
        'headers' => array(
            'X-API-Token' => $api_token,
        ),
    ));
    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $products = json_decode($body, true);

    return $products;
}

function get_all_woocommerce_products() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );

    $query = new WP_Query($args);
    $products = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $products[get_post_meta(get_the_ID(), '_sku', true)] = get_the_ID();
        }
        wp_reset_postdata();
    }

    return $products;
}

// تابع لإضافة المنتجات إلى WooCommerce
function import_products_into_woocommerce() {
    $products = fetch_products_from_api();
	$existing_products = get_all_woocommerce_products();
    if (!empty($products)) {
        foreach ($products as $product) {
			$description = "Min Purchase Quantity: " . $product['min_purchase_quantity'];
            $description .= "\nMax Purchase Quantity: " . $product['max_purchase_quantity'];
            $stock_status = ($product['stock_status'] == 'instock') ? 'instock' : 'outofstock';

            if (isset($existing_products[$product['id']])) {
                $existing_product_id = $existing_products[$product['id']];
                // تحديث المنتج الموجود
                $post_data = array(
                    'ID' => $existing_product_id,
                    'post_title' => $product['name'],
                    'post_content' => "No Description",
                    'post_excerpt' => $description, // إضافة الوصف المختصر
                );
                wp_update_post($post_data);
			    update_post_meta($existing_product_id, '_stock_status', $stock_status); // تعيين حالة التوفر في المخزون
				update_post_meta($existing_product_id, 'min_purchase_quantity', $product['min_purchase_quantity']);
				update_post_meta($existing_product_id, 'max_purchase_quantity', $product['max_purchase_quantity']);
            } else {
                // إضافة منتج جديد
                $post_id = wp_insert_post(array(
                    'post_title' => $product['name'],
                    'post_content' => "No Description",
					'post_excerpt' => $description,
                    'post_status' => 'publish',
                    'post_type' => 'product',
                ));

                if ($post_id) {
                    update_post_meta($post_id, '_sku', $product['id']); // استخدام ID كـ SKU
					update_post_meta($post_id, '_stock_status', $stock_status); // تعيين حالة التوفر في المخزون
					update_post_meta($existing_product_id, 'min_purchase_quantity', $product['min_purchase_quantity']);
					update_post_meta($existing_product_id, 'max_purchase_quantity', $product['max_purchase_quantity']);
                // يمكنك إضافة المزيد من الحقول هنا حسب الحاجة
            }
        }
    }
}
}


// إضافة فاصل زمني جديد (كل ساعة)
add_filter('cron_schedules', 'custom_cron_schedules');
function custom_cron_schedules($schedules) {
    $schedules['hourly'] = array(
        'interval' => 3600,
        'display' => __('Once Hourly')
    );
    return $schedules;
}

// جدولة المهمة عند تنشيط القالب
add_action('after_setup_theme', 'custom_product_import_activation');
function custom_product_import_activation() {
    if (!wp_next_scheduled('custom_product_import_cron_job')) {
        wp_schedule_event(time(), 'hourly', 'custom_product_import_cron_job');
    }
}

// ربط المهمة المجدولة بالتابع
add_action('custom_product_import_cron_job', 'import_products_into_woocommerce');


add_action('admin_menu', 'custom_product_import_menu');
function custom_product_import_menu() {
    add_menu_page(
        'Custom Product Import', 
        'Product Import', 
        'manage_options', 
        'custom-product-import', 
        'custom_product_import_page'
    );
}

// عرض محتوى الصفحة الجديدة
function custom_product_import_page() {
    ?>
    <div class="wrap">
        <h1>Import Products from API</h1>
        <form method="post" action="">
            <input type="hidden" name="custom_product_import" value="1">
            <?php submit_button('Import Products'); ?>
        </form>
    </div>
    <?php
}

// معالجة طلب الاستيراد عند الضغط على الزر
add_action('admin_init', 'handle_custom_product_import');
function handle_custom_product_import() {
    if (isset($_POST['custom_product_import']) && $_POST['custom_product_import'] == '1') {
        import_products_into_woocommerce();
        add_action('admin_notices', 'custom_product_import_notice');
    }
}

// عرض رسالة نجاح بعد الاستيراد
function custom_product_import_notice() {
    ?>
    <div class="notice notice-success is-dismissible">
        <p>Products imported successfully!</p>
    </div>
    <?php
}

add_filter('woocommerce_checkout_fields', 'add_urlsocial_field');

function add_urlsocial_field($fields) {
    $fields['billing']['urlsocial'] = array(
        'label'       => 'معرف الحساب',
        'placeholder' => 'أدخل معرف الحساب',
        'required'    => true,
        'class'       => array('form-row-wide'),
        'clear'       => true,
    );

    return $fields;
}
add_action('woocommerce_checkout_update_order_meta', 'save_urlsocial_field');
function save_urlsocial_field($order_id) {
    if (!empty($_POST['urlsocial'])) {
       $urlsocial = sanitize_text_field($_POST['urlsocial']);
        update_post_meta($order_id, 'urlsocial', $urlsocial);
    }
	else {
		error_log('URLSocial Missing ');
	}
}

add_action('woocommerce_thankyou', 'send_order_to_api');
function send_order_to_api($order_id) {
    if (!$order_id) {
        return;
    }

    $order = wc_get_order($order_id);
    $items = $order->get_items();
    $urlsocial = get_post_meta($order->get_id(), 'urlsocial', true); // الحصول على قيمة حقل urisocial
	
    foreach ($items as $item) {
        $product_id = $item->get_product()->get_sku(); // الحصول على SKU كـ product_id
        $quantity = $item->get_quantity();

        // البيانات التي سيتم إرسالها في الطلب
        $data = array(
            'product_id' => $product_id,
            'quantity' => $quantity,
            'urlsocial' => $urlsocial,
        );

        // تحويل البيانات إلى JSON
        $json_data = json_encode($data);

        // عنوان URL لواجهة برمجة التطبيقات
        $api_url = 'https://satofill.com/wp-json/mystore/v1/create-order'; // استبدل هذا بعنوان URL الخاص بك

        // تهيئة جلسة cURL
        $ch = curl_init($api_url);

        // تعيين خيارات cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-Token: YOUR API TOKEN', // استبدل YOUR_API_TOKEN بالتوكن الخاص بك
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);

        // تنفيذ طلب cURL
        $response = curl_exec($ch);

        // التحقق من وجود أخطاء
        if (curl_errno($ch)) {
            error_log('Error:' . curl_error($ch));
        } else {
            // معالجة الاستجابة
            $response_data = json_decode($response, true);

            // إضافة الطلب المرسل كملاحظة إلى الطلب في WooCommerce
            $order->add_order_note('Sent request to API: ' . print_r($data, true));
            // إضافة ملاحظة استجابة الطلب إلى الطلب في WooCommerce
            $order->add_order_note('Response from API: ' . print_r($response_data, true));
        }

        // إغلاق جلسة cURL
        curl_close($ch);
    }
}

function get_order_details_from_api($order_id) {
    // عنوان URL لواجهة برمجة التطبيقات
    $api_url = 'https://satofill.com/wp-json/mystore/v1/order' . $order_id; // استبدل order_id برقم الطلب

    // ترويسة التوكن
    $api_token = 'YOUR API TOKEN'; // استبدل YOUR_API_TOKEN بالتوكن الخاص بك

    // تهيئة جلسة cURL
    $ch = curl_init($api_url);

    // تعيين خيارات cURL
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-API-Token: ' . $api_token, // التوكن الخاص بك
    ));

    // تنفيذ طلب cURL
    $response = curl_exec($ch);

    // التحقق من وجود أخطاء
    if (curl_errno($ch)) {
        return null;
    }

    // معالجة الاستجابة
    $order_details = json_decode($response, true);
    curl_close($ch);

    return $order_details;
}

add_action('woocommerce_thankyou', 'add_order_details_note');

function add_order_details_note($order_id) {
    if (!$order_id) {
        return;
    }

    // جلب تفاصيل الطلب من API
    $order_details = get_order_details_from_api($order_id);

    if (is_null($order_details)) {
        return;
    }

    $order = wc_get_order($order_id);

    // إعداد نص الملاحظة لتفاصيل الطلب
    $order_details_note = "Order Details:\n";
    foreach ($order_details as $key => $value) {
        $order_details_note .= "$key: $value\n";
    }

    // إضافة الملاحظة إلى الطلب في WooCommerce
    $order->add_order_note($order_details_note);

}

add_action('woocommerce_thankyou', 'update_order_status_based_on_api');

function update_order_status_based_on_api($order_id) {
    if (!$order_id) {
        return;
    }

    // جلب تفاصيل الطلب من API
    $order_details = get_order_details_from_api($order_id);

    if (is_null($order_details)) {
        return;
    }

    $order = wc_get_order($order_id);

    // تحقق من حالة الطلب
    if (isset($order_details['status']) && $order_details['status'] === 'completed') {
        // تحديث حالة الطلب إلى مكتمل في WooCommerce
        $order->update_status('completed');
        $order->add_order_note('Order status updated to completed based on API response.');
    } else {
        $order->add_order_note('Order status is not completed based on API response.');
    }

    // إعداد نص الملاحظة لتفاصيل الطلب
    $order_details_note = "Order Details:\n";
    foreach ($order_details as $key => $value) {
        $order_details_note .= "$key: $value\n";
    }

    // إضافة الملاحظة إلى الطلب في WooCommerce
    $order->add_order_note($order_details_note);
}

// تابع لجلب رصيد المحفظة من API
function get_wallet_balance_from_api() {
    // عنوان URL لواجهة برمجة التطبيقات
    $api_url = 'https://satofill.com/wp-json/mystore/v1/wallet-balance'; // استبدل بالعنوان الفعلي لواجهة API لجلب رصيد المحفظة

    // ترويسة التوكن
    $api_token = 'YOUR API TOKEN'; // استبدل YOUR_API_TOKEN بالتوكن الخاص بك

    // تهيئة جلسة cURL
    $ch = curl_init($api_url);

    // تعيين خيارات cURL
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'X-API-Token: ' . $api_token, // التوكن الخاص بك
    ));

    // تنفيذ طلب cURL
    $response = curl_exec($ch);

    // التحقق من وجود أخطاء
    if (curl_errno($ch)) {
        return null;
    }

    // معالجة الاستجابة
    $wallet_balance = json_decode($response, true);
    curl_close($ch);

    return isset($wallet_balance['balance']) ? $wallet_balance['balance'] : null;
}

// إضافة رصيد المحفظة في لوحة التحكم
add_action('wp_dashboard_setup', 'add_wallet_balance_dashboard_widget');

function add_wallet_balance_dashboard_widget() {
    wp_add_dashboard_widget('wallet_balance_widget', 'رصيد المحفظة', 'display_wallet_balance');
}

// عرض رصيد المحفظة
function display_wallet_balance() {
    // جلب رصيد المحفظة من API
    $wallet_balance = get_wallet_balance_from_api();

    if (is_null($wallet_balance)) {
        echo '<h3>رصيد المحفظة الحالي:</h3>';
        echo '<p>غير متاح حالياً. تحقق من إعدادات API.</p>';
    } else {
        echo '<h3>رصيد المحفظة الحالي:</h3>';
        echo '<p>' . $wallet_balance . ' USD</p>';
    }
}

function add_custom_meta_boxes() {
    add_meta_box('product_purchase_limits', 'Purchase Limits', 'display_purchase_limits_meta_box', 'product', 'normal', 'high');
}
add_action('add_meta_boxes', 'add_custom_meta_boxes');

function display_purchase_limits_meta_box($post) {
    $min_purchase_quantity = get_post_meta($post->ID, 'min_purchase_quantity', true);
    $max_purchase_quantity = get_post_meta($post->ID, 'max_purchase_quantity', true);
    ?>
    <label for="min_purchase_quantity">Min Purchase Quantity:</label>
    <input type="number" id="min_purchase_quantity" name="min_purchase_quantity" value="<?php echo esc_attr($min_purchase_quantity); ?>">
    <br>
    <label for="max_purchase_quantity">Max Purchase Quantity:</label>
    <input type="number" id="max_purchase_quantity" name="max_purchase_quantity" value="<?php echo esc_attr($max_purchase_quantity); ?>">
    <?php
}

function save_purchase_limits($post_id) {
    if (isset($_POST['min_purchase_quantity'])) {
        update_post_meta($post_id, 'min_purchase_quantity', sanitize_text_field($_POST['min_purchase_quantity']));
    }
    if (isset($_POST['max_purchase_quantity'])) {
        update_post_meta($post_id, 'max_purchase_quantity', sanitize_text_field($_POST['max_purchase_quantity']));
    }
}
add_action('save_post', 'save_purchase_limits');

// تحقق من كمية الشراء قبل إضافة المنتج إلى السلة
add_filter('woocommerce_add_to_cart_validation', 'validate_purchase_limits', 10, 3);
function validate_purchase_limits($passed, $product_id, $quantity) {
    $min_purchase_quantity = get_post_meta($product_id, 'min_purchase_quantity', true);
    $max_purchase_quantity = get_post_meta($product_id, 'max_purchase_quantity', true);

    if ($min_purchase_quantity && $quantity < $min_purchase_quantity) {
        wc_add_notice('يجب أن تكون الكمية المحددة أكبر أو تساوي الحد الأدنى للشراء: ' . $min_purchase_quantity, 'error');
        return false;
    }

    if ($max_purchase_quantity && $quantity > $max_purchase_quantity) {
        wc_add_notice('يجب أن تكون الكمية المحددة أقل أو تساوي الحد الأقصى للشراء: ' . $max_purchase_quantity, 'error');
        return false;
    }

    return $passed;
}
