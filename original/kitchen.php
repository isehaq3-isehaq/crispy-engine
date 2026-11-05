<?php
// purchases.php
// نظام المشتريات الذكية بالذكاء الاصطناعي والاعتماد المخزني والمالي - شاي سيّار

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔒 التحقق من صلاحية المدير التنفيذي (executive) حصرياً وحجب بقية الطواقم
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'executive') {
    header("Location: login.php");
    exit;
}

// 🛡️ إنشاء رمز حماية CSRF لمنع تزوير الطلبات الحساسة (تحليل الفاتورة وترحيلها مالياً)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// استدعاء ملف الاتصال الآمن
if (file_exists('db.php')) {
    include_once 'db.php';
}

$success_message = '';
$error_message = '';
$ai_result = null;

// 🔑 مفتاح الـ API يُقرأ الآن من متغير بيئة النظام وليس مكتوباً صراحة بالكود
// يجب ضبطه على السيرفر عبر: putenv() في ملف تهيئة، أو إعداد Apache/Nginx/PHP-FPM،
// أو ملف .env مستبعد تماماً من Git ومحمي خارج نطاق الويب public_html
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');

// 👑 [دالة حقن رمز الريال العُماني القياسي الموحد لشاي سيار]
if (!function_exists('get_omr_symbol')) {
    function get_omr_symbol() {
        $base_path = 'Ro/RO.svg';
        if (file_exists($base_path)) {
            $version = filemtime($base_path);
            return '<img src="' . $base_path . '?v=' . $version . '" class="omr-official-svg-icon" alt="﷼" style="height: 1.35em; width: auto; vertical-align: middle; margin-left: 4px; display: inline-block; background: transparent !important; background-color: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; box-shadow: none !important; filter: invert(21%) sepia(85%) saturate(3501%) hue-rotate(346deg) brightness(85%) contrast(85%) !important;">';
        } else {
            return '<span style="font-weight:bold; font-size:12px; margin-left:3px; color:#B82929; background: transparent !important; border: none !important;">ريال</span>';
        }
    }
}

// 🎬 [قراءة توقيت فيديو التحميل تلقائياً لمنع احتجاز الكاش عند الاستبدال]
$video_path = 'tea_loading.mp4';
$video_version_param = file_exists($video_path) ? filemtime($video_path) : time();

// 🏗️ تأسيس الجداول الموحدة للمشتريات وذاكرة التحويلات تلقائياً مع نظام الـ 3 خانات عشرية
if (isset($pdo)) {
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;");

        // 1. جدول الفواتير الكلية للمشتريات
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(150) NOT NULL,
            invoice_date DATE NOT NULL,
            total_amount DECIMAL(10,3) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            invoice_image VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 2. جدول تفاصيل الفاتورة ومواد الصرف التشغيلية والمخزنية
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            purchased_qty DECIMAL(10,3) NOT NULL,
            consumption_qty DECIMAL(10,3) NOT NULL,
            unit VARCHAR(30) NOT NULL,
            item_type VARCHAR(30) NOT NULL,
            price DECIMAL(10,3) NOT NULL,
            FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 3. جدول الذاكرة الذكية للنظام لتذكر التحويلات التشغيلية للمدير مستقبلاً
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_inventory_memory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raw_item_name VARCHAR(150) NOT NULL UNIQUE,
            mapped_unit VARCHAR(30) NOT NULL,
            conversion_factor DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            mapped_type VARCHAR(30) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    } catch (\PDOException $e) {
        error_log("Purchases DB Init Error: " . $e->getMessage());
        $error_message = 'خطأ في تأسيس الجداول الحسابية، يرجى مراجعة سجلات النظام.';
    }
}

// 🛡️ التحقق الموحّد من CSRF لكل عمليات POST الحساسة بهذه الصفحة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = 'خطأ أمني: طلب غير صالح (CSRF)، يرجى إعادة تحميل الصفحة والمحاولة مجدداً.';
        $_SERVER['REQUEST_METHOD'] = 'GET'; // منع تنفيذ أي منطق POST لاحق بهذا الطلب
    }
}

// Phase 1: استقبال الصورة وإرسالها لـ Google AI Studio بالمسار الأحدث والمستقر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['analyze_invoice'])) {

    if (empty(GEMINI_API_KEY)) {
        $error_message = 'مفتاح خدمة الذكاء الاصطناعي غير مُهيأ على السيرفر. يرجى مراجعة مسؤول النظام.';
    } elseif (isset($_FILES['invoice_img']) && $_FILES['invoice_img']['error'] === 0) {

        // 🛡️ حدود وتحقق فعلي من الملف المرفوع (وليس فقط الاعتماد على النوع المُرسل من المتصفح)
        $max_file_size = 8 * 1024 * 1024; // 8 ميجابايت كحد أقصى
        $tmp_path = $_FILES['invoice_img']['tmp_name'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = finfo_file($finfo, $tmp_path);
        finfo_close($finfo);

        if ($_FILES['invoice_img']['size'] > $max_file_size) {
            $error_message = 'حجم الصورة كبير جداً، الحد الأقصى المسموح به 8 ميجابايت.';
        } elseif (!in_array($real_mime, $allowed_mimes, true)) {
            $error_message = 'صيغة الملف غير مدعومة، يرجى رفع صورة فاتورة بصيغة JPG أو PNG أو WEBP فقط.';
        } else {
            $img_data = base64_encode(file_get_contents($tmp_path));

            // بناء أمر التوجيه الهيكلي الصارم لـ Gemini لبث البيانات كـ JSON صافي ومحمي
            $prompt = "قم بتحليل فاتورة المشتريات العربية المرفقة واستخرج البيانات بدقة بصيغة JSON فقط بدون أي نصوص أو علامات ماركداون خارج الجيسون وبدون إضافة وسم الخصائص المرجعية. الهيكل المطلوب كالتالي:
            {
              \"supplier\": \"اسم المورد المكتوب الفعلي\",
              \"date\": \"تاريخ الفاتورة بصيغة YYYY-MM-DD (إذا لم تجده ضع تاريخ اليوم)\",
              \"total\": \"الإجمالي النهائي كرقّم مجرد\",
              \"items\": [
                 { \"name\": \"اسم المنتج المستخرج الفعلي\", \"qty\": \"الكمية كرقّم\", \"price\": \"السعر الإجمالي للمنتج\" }
              ]
            }";

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            ["text" => $prompt],
                            [
                                "inlineData" => [
                                    "mimeType" => $real_mime,
                                    "data" => $img_data
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // المسمى المستقر سحابياً لـ Gemini 2.5 Flash للتحليل وحفظ الفاتورة سحابياً
            $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;

            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            // 🛡️ التحقق من شهادة SSL مُفعّل الآن (لا تُعطّل هذين السطرين أبداً في بيئة الإنتاج)
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                error_log("Gemini cURL Error: " . $curl_error);
                $error_message = 'تعذر الاتصال بخدمة الذكاء الاصطناعي، يرجى المحاولة لاحقاً.';
            } elseif ($http_code === 200) {
                $res_arr = json_decode($response, true);
                $raw_json = $res_arr['candidates'][0]['content']['parts'][0]['text'] ?? '';

                $clean_json = trim($raw_json);
                if (preg_match('/^```json(.*)```$/ss', $clean_json, $matches)) {
                    $clean_json = trim($matches[1]);
                } elseif (preg_match('/^```(.*)```$/ss', $clean_json, $matches)) {
                    $clean_json = trim($matches[1]);
                }

                $parsed = json_decode($clean_json, true);
                if (!$parsed) {
                    $parsed = json_decode(trim($raw_json), true);
                }

                // 🛡️ التحقق من اكتمال بنية البيانات المُستخرجة قبل الاعتماد عليها بالواجهة
                if (!$parsed || !isset($parsed['items']) || !is_array($parsed['items']) || empty($parsed['items'])) {
                    $error_message = 'نجح الاتصال بالذكاء الاصطناعي لكنه فشل في استخراج بنود فاتورة صالحة. حاول برفع صورة أوضح.';
                } else {
                    $ai_result = $parsed;
                    $success_message = 'تمت قراءة الفاتورة بنجاح! راجع بنود الحسابات بالأسفل واعتمدها للمخزن 🎯';
                }
            } else {
                error_log("Gemini API HTTP Error: " . $http_code . " - " . $response);
                $error_message = 'فشل السيرفر في فحص الفاتورة عبر خدمة الذكاء الاصطناعي، يرجى المحاولة مجدداً.';
            }
        }
    } else {
        $error_message = 'الرجاء اختيار صورة فاتورة واضحة لرفعها وفحصها.';
    }
}

// Phase 2: اعتماد المشتريات، ضرب المعاملات، ترحيلها للمخزن وتحديث ذاكرة النظام الذكية
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_final_invoice'])) {
    $supplier_name  = trim($_POST['supplier_name'] ?? '');
    $invoice_date   = $_POST['invoice_date'] ?? date('Y-m-d');
    $total_amount   = floatval($_POST['total_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'نقدي';

    if (!isset($pdo)) {
        $error_message = 'تعذر الاتصال بقاعدة البيانات، لم يتم ترحيل الفاتورة.';
    } elseif (empty($supplier_name)) {
        $error_message = 'اسم المورد حقل إلزامي ولا يمكن تركه فارغاً.';
    } elseif (empty($_POST['items'])) {
        $error_message = 'لا توجد بنود لترحيلها، يرجى مراجعة الفاتورة أولاً.';
    } else {
        try {
            // فتح ترانزأكشن واحد فقط لحماية القيد المالي
            $pdo->beginTransaction();

            // 1. إدخال رأس الفاتورة الكلية في جدول المشتريات العامة
            $stmt = $pdo->prepare("INSERT INTO purchases (supplier_name, invoice_date, total_amount, payment_method) VALUES (?, ?, ?, ?)");
            $stmt->execute([$supplier_name, $invoice_date, $total_amount, $payment_method]);
            $purchase_id = $pdo->lastInsertId();

            // 2. معالجة البنود المحللة وتطبيق معاملات التحويل بدقة الـ 3 خانات
            foreach ($_POST['items'] as $index => $item) {
                $raw_name   = trim($item['raw_name'] ?? '');
                $qty        = floatval($item['qty'] ?? 0);
                $price      = floatval($item['price'] ?? 0);

                $unit       = $_POST['unit'][$index] ?? 'جرام';
                $factor     = floatval($_POST['factor'][$index] ?? 1.000);
                $item_type  = $_POST['item_type'][$index] ?? 'consumable';

                if (empty($raw_name)) {
                    continue; // تخطي أي بند بلا اسم صنف صالح
                }

                // 🛡️ حماية من معامل تحويل صفري أو سالب يؤدي لتصفير الكمية المحفوظة بالمخزون
                if ($factor <= 0) {
                    $factor = 1.000;
                }

                // ضرب الكمية الموردة في معامل السعة لتحويل الصناديق/الكراتين إلى جرامات أو حبات
                $consumption_qty = $qty * $factor;

                // أ. حفظ تفاصيل البند وجزيئات الصرف
                $stmtItem = $pdo->prepare("INSERT INTO purchase_items (purchase_id, item_name, purchased_qty, consumption_qty, unit, item_type, price) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtItem->execute([$purchase_id, $raw_name, $qty, $consumption_qty, $unit, $item_type, $price]);

                // ب. ضخ وتغذية مخزون البند في جدول المخزن الرئيسي المركزي الفعلي تلقائياً (main_hub)
                $checkInv = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE item_name = ? AND inventory_type = 'main_hub'");
                $checkInv->execute([$raw_name]);

                if ($checkInv->fetchColumn() > 0) {
                    $updInv = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE item_name = ? AND inventory_type = 'main_hub'");
                    $updInv->execute([$consumption_qty, $raw_name]);
                } else {
                    $main_cat = ($item_type === 'consumable') ? 'food' : 'packaging';
                    $sub_cat  = ($item_type === 'consumable') ? 'dry' : '';

                    $insInv = $pdo->prepare("INSERT INTO inventory (item_name, inventory_type, main_category, sub_category, quantity, unit) VALUES (?, 'main_hub', ?, ?, ?, ?)");
                    $insInv->execute([$raw_name, $main_cat, $sub_cat, $consumption_qty, $unit]);
                }

                // ج. تحديث ذاكرة النظام الذكية لتذكر هذه المMapping تلقائياً في المرات القادمة للسرعة
                $stmtMem = $pdo->prepare("INSERT INTO ai_inventory_memory (raw_item_name, mapped_unit, conversion_factor, mapped_type) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE mapped_unit = ?, conversion_factor = ?, mapped_type = ?");
                $stmtMem->execute([$raw_name, $unit, $factor, $item_type, $unit, $factor, $item_type]);
            }

            // اعتماد الترحيل نهائياً في قاعدة البيانات
            $pdo->commit();
            $success_message = 'تم ترحيل الفاتورة بنجاح مالي تام، وضخ الكميات المحولة بالجرام والحبة للمخزن الرئيسي المركزي وتحديث الذاكرة الذكية! 🚀📊';
        } catch (\Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Purchase Posting Error: " . $e->getMessage());
            $error_message = 'حدث خطأ مالي تشغيلي أثناء ترحيل الفاتورة، لم يتم اعتماد أي بيانات.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>شاي سيّار - إدارة المشتريات والاعتماد الذكي</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-outer: #0E121A; --bg-main: #EFEBE3; --bg-card: #FFFFFF;
            --primary-red: #B82929; --success-green: #0F754C;
            --text-dark: #222222; --text-muted: #666666; --border-color: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Tajawal', sans-serif; }
        body { background-color: var(--bg-outer); min-height: 100vh; overflow-x: hidden; position: relative; }
        
        .main-wrapper { display: flex; width: 100%; min-height: 100vh; position: relative; overflow-x: hidden !important; }
        
        .sys-sidebar {
            position: fixed !important; top: 0; right: -240px !important; left: auto !important; bottom: 0; width: 240px; z-index: 99999 !important;
            transition: right 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important; box-shadow: -5px 0 25px rgba(0,0,0,0.3);
        }
        body.sidebar-show .sys-sidebar { right: 0 !important; }

        .dashboard-container { 
            background-color: var(--bg-main); flex-grow: 1; width: 100%; padding: 25px; text-align: right; transition: all 0.4s ease; position: relative; right: 0;
        }
        @media (min-width: 992px) { 
            body.sidebar-show .dashboard-container { right: 240px; width: calc(100% - 240px); } 
        }
        
        .header-zone { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(184, 41, 41, 0.15); padding-bottom: 15px; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .header-title-wrapper { display: inline-flex; align-items: center; gap: 12px; }
        .logo-text { color: var(--primary-red); font-size: 24px; font-weight: 700; margin: 0; }
        
        .cup-toggle-btn { width: 44px !important; height: 44px !important; min-width: 44px !important; max-width: 44px !important; display: inline-flex !important; justify-content: center; align-items: center; cursor: pointer; border-radius: 50%; background: rgba(184, 41, 41, 0.08); transition: all 0.3s; z-index: 100000; border: 1px solid rgba(184, 41, 41, 0.15); padding: 0; margin: 0; }
        .cup-toggle-btn:hover { background: rgba(184, 41, 41, 0.15); transform: scale(1.05); }
        .cup-toggle-btn img { width: 24px !important; height: 24px !important; object-fit: contain; }

        .section-card { background-color: var(--bg-card); border-radius: 16px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); margin-bottom: 25px; border: 1px solid var(--border-color); }
        .section-title { font-size: 14px; font-weight: 700; color: var(--primary-red); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; border-bottom: 1px dashed #E2E8F0; padding-bottom: 8px; }

        .uploader-zone { 
            border: 2px dashed #B82929; background-color: transparent; border-radius: 24px; padding: 40px 20px; text-align: center; cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; min-height: 200px; display: flex; flex-direction: column; justify-content: center; align-items: center; 
        }
        .uploader-zone i { font-size: 44px; color: var(--primary-red); margin-bottom: 12px; }
        .uploader-zone .main-txt { font-size: 16px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .uploader-zone .sub-txt { font-size: 12px; color: var(--text-muted); }
        
        .loading-video-container {
            display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-color: var(--bg-card); flex-direction: column; align-items: center; justify-content: center; z-index: 10;
        }
        .ai-loading-video { height: 100%; max-height: 160px; object-fit: contain; border-radius: 12px; background-color: transparent; }
        .loading-text-glow { font-size: 13px; font-weight: 700; color: var(--primary-red); margin-top: 8px; animation: pulse 1.5s infinite; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group label { font-size: 12px; font-weight: bold; color: var(--text-dark); display: flex; align-items: center; gap: 4px; }
        .form-grid input, .form-grid select { width: 100%; padding: 11px; border: 1px solid #CBD5E0; border-radius: 8px; font-size: 13px; outline: none; background-color: white; }

        .btn-submit { background-color: var(--primary-red); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        
        .table-responsive { background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow-x: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        table { width: 100%; border-collapse: collapse; text-align: right; font-size: 13px; min-width: 800px; }
        th { background-color: #F1ECE4; color: var(--text-dark); padding: 14px 12px; font-weight: 700; border-bottom: 2px solid var(--border-color); }
        td { padding: 12px; border-bottom: 1px solid #EDF2F7; vertical-align: middle; }
        .table-input { padding: 8px; border: 1px solid #CBD5E0; border-radius: 6px; width: 100%; font-size: 13px; background: white; outline: none; color: var(--text-dark); }
        
        .alert-box { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: bold; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .alert-success { background-color: #D1FAE5; color: var(--success-green); border: 1px solid #A7F3D0; }
        .alert-error { background-color: #FEE2E2; color: var(--primary-red); border: 1px solid #FCA5A5; }
    </style>
</head>
<body>

<div class="main-wrapper" id="appWrapper">
    <?php if (file_exists('sidebar.php')) { include 'sidebar.php'; } ?>

    <div class="dashboard-container">
        <div class="header-zone">
            <div class="header-title-wrapper">
                <div class="cup-toggle-btn" id="cupToggle">
                    <?php if (file_exists('logo_cup.png')): ?>
                        <img src="logo_cup.png" alt="الشعار">
                    <?php else: ?>
                        <i class="bi bi-cup-hot-fill"></i>
                    <?php endif; ?>
                </div>
                <h1 class="logo-text">المشتريات والتحليل الذكي بالفواتير (AI)</h1>
            </div>
            <span style="font-size:12px; background:#E5E1D8; padding:6px 12px; border-radius:20px; font-weight:bold; color:var(--text-dark);">إدارة الموردين والاعتماد الذكي بقوة 2.5 🔮</span>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert-box alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert-box alert-error"><i class="bi bi-exclamation-octagon-fill"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="section-card" id="uploadCardSection">
            <h2 class="section-title"><i class="bi bi-cloud-arrow-up"></i> رفع وصورة فاتورة الموردين</h2>
            
            <form method="POST" action="purchases.php" enctype="multipart/form-data" id="invoiceForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="uploader-zone" id="dropZone" onclick="triggerFileInput()">
                    
                    <div id="defaultUploaderContent">
                        <i class="bi bi-file-earmark-image"></i>
                        <p class="main-txt">اضغط هنا لاختيار أو تصوير فاتورة المشتريات بالجوال</p>
                        <p class="sub-txt">يدعم الصور والمستندات المطبوعة واليدوية</p>
                    </div>

                    <!-- 🎬 [حاوية فيديو التحميل المحدث بالكسر الآلي لذاكرة التخزين Cache] -->
                    <div class="loading-video-container" id="videoLoadingContainer">
                        <video class="ai-loading-video" id="aiActionVideo" loop muted playsinline preload="auto">
                            <source src="<?php echo $video_path . '?v=' . $video_version_param; ?>" type="video/mp4">
                            الاستعراض غير مدعوم بمتصفحك.
                        </video>
                        <p class="loading-text-glow">🔮 يجري تحليل وفك الفاتورة الحين عبر الـ AI...</p>
                    </div>

                    <input type="file" name="invoice_img" id="fileInput" accept="image/*" style="display:none;" onchange="executeAiAnalysis()">
                </div>
                <button type="submit" name="analyze_invoice" id="submitUploadBtn" style="display:none;"></button>
            </form>
        </div>

        <?php if ($ai_result): ?>
            <div class="section-card" style="border: 2px solid var(--success-green);">
                <h2 class="section-title" style="color:var(--success-green);"><i class="bi bi-cpu"></i> مراجعة مخرجات الذكاء الاصطناعي واعتماد السداد والمخزن</h2>
                <form method="POST" action="purchases.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>اسم المورد (الفواتير)</label>
                            <input type="text" name="supplier_name" value="<?php echo htmlspecialchars($ai_result['supplier'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>تاريخ الفاتورة الفعلي</label>
                            <input type="date" name="invoice_date" value="<?php echo htmlspecialchars($ai_result['date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>إجمالي قيمة الفاتورة (<?php echo get_omr_symbol(); ?>)</label>
                            <input type="number" step="0.001" name="total_amount" value="<?php echo number_format(floatval($ai_result['total'] ?? 0), 3, '.', ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>💳 نوع السداد المالي للعملية</label>
                            <select name="payment_method" required>
                                <option value="نقدي">1. نقدي (Cash)</option>
                                <option value="فيزا">2. فيزا / بطاقة المنشأة</option>
                                <option value="حوالة/شيك">3. حوالة بنكية / شيك</option>
                                <option value="دائن">4. دائن / حساب آجل للمورد</option>
                            </select>
                        </div>
                    </div>

                    <h3 style="font-size:14px; font-weight:700; color:var(--text-dark); margin-bottom:10px; margin-top:20px;">📦 تشريح البنود وربط معاملات الصرف بالمخزن (جرام / حبة):</h3>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>اسم البند المستخرج</th>
                                    <th style="width:110px;">الكمية (فاتورة)</th>
                                    <th style="width:160px;">نوع الصنف مخزنياً</th>
                                    <th style="width:130px;">وحدة الصرف</th>
                                    <th style="width:160px;">معامل التحويل (السعة)</th>
                                    <th style="width:140px;">السعر الإجمالي (<?php echo get_omr_symbol(); ?>)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ai_result['items'] as $index => $item):
                                    $raw_name = trim($item['name'] ?? '');
                                    if ($raw_name === '') { continue; }

                                    $saved_unit = 'جرام'; $saved_factor = 1.000; $saved_type = 'consumable';
                                    if (isset($pdo)) {
                                        $stmtCheck = $pdo->prepare("SELECT * FROM ai_inventory_memory WHERE raw_item_name = ? LIMIT 1");
                                        $stmtCheck->execute([$raw_name]);
                                        $mem = $stmtCheck->fetch();
                                        if ($mem) {
                                            $saved_unit   = $mem['mapped_unit'];
                                            $saved_factor = $mem['conversion_factor'];
                                            $saved_type   = $mem['mapped_type'];
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="items[<?php echo $index; ?>][raw_name]" class="table-input" value="<?php echo htmlspecialchars($raw_name); ?>" readonly style="background:#F9F9F9; font-weight:bold; color:var(--text-dark);">
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="items[<?php echo $index; ?>][qty]" class="table-input" value="<?php echo htmlspecialchars($item['qty'] ?? '1'); ?>" required>
                                        </td>
                                        <td>
                                            <select name="item_type[<?php echo $index; ?>]" class="table-input">
                                                <option value="consumable" <?php echo $saved_type === 'consumable' ? 'selected' : ''; ?>>مواد مستهلكة (مرة واحدة)</option>
                                                <option value="reusable" <?php echo $saved_type === 'reusable' ? 'selected' : ''; ?>>أصول تشغيلية (تعاد وتستخدم)</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="unit[<?php echo $index; ?>]" class="table-input">
                                                <option value="جرام" <?php echo $saved_unit === 'جرام' ? 'selected' : ''; ?>>جرام</option>
                                                <option value="حبة" <?php echo $saved_unit === 'حبة' ? 'selected' : ''; ?>>حبة</option>
                                                <option value="ملي" <?php echo $saved_unit === 'ملي' ? 'selected' : ''; ?>>ملي</option>
                                                <option value="كرتون" <?php echo $saved_unit === 'كرتون' ? 'selected' : ''; ?>>كرتون</option>
                                                <option value="لتر" <?php echo $saved_unit === 'لتر' ? 'selected' : ''; ?>>لتر</option>
                                                <option value="كيلو" <?php echo $saved_unit === 'كيلو' ? 'selected' : ''; ?>>كيلو</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" min="0.001" name="factor[<?php echo $index; ?>]" class="table-input" value="<?php echo htmlspecialchars($saved_factor); ?>" placeholder="مثال: 500 للوزن" required>
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="items[<?php echo $index; ?>][price]" class="table-input" value="<?php echo number_format(floatval($item['price'] ?? 0), 3, '.', ''); ?>" required>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:20px; text-align:left;">
                        <button type="submit" name="save_final_invoice" class="btn-submit" style="background-color:var(--success-green);">
                            <span>ترحيل الفاتورة وتغذية المستودع والذاكرة</span>
                            <i class="bi bi-check2-all"></i>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const cupToggle = document.getElementById("cupToggle");
    if (cupToggle) {
        cupToggle.addEventListener("click", function(e) {
            e.stopPropagation();
            document.body.classList.toggle("sidebar-show");
        });
    }
});

function triggerFileInput() {
    const videoContainer = document.getElementById('videoLoadingContainer');
    if (videoContainer.style.display !== 'flex') {
        document.getElementById('fileInput').click();
    }
}

function executeAiAnalysis() {
    const fileInput = document.getElementById('fileInput');
    const defaultContent = document.getElementById('defaultUploaderContent');
    const videoContainer = document.getElementById('videoLoadingContainer');
    const loadingVideo = document.getElementById('aiActionVideo');

    if (fileInput.files.length > 0) {
        if (defaultContent) defaultContent.style.display = 'none';
        if (videoContainer) videoContainer.style.display = 'flex';
        if (loadingVideo) {
            loadingVideo.muted = true;
            loadingVideo.play().catch(error => {
                console.log("خطأ تشغيل الفيديو التلقائي: ", error);
            });
        }
        document.getElementById('submitUploadBtn').click();
    }
}
</script>
</body>
</html>
