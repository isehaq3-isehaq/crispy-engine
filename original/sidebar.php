<?php
// sidebar.php
// شريط التنقل الجانبي الديناميكي الذكي الموحد لنظام شاي سيّار - نسخة القائمة القابلة للتمرير والتصفح السلس

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔐 حماية إضافية للصفحات التي لا تستدعي db.php: إجبار تغيير كلمة المرور المؤقتة
if (!empty($_SESSION['must_change_password']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'change_password.php') {
    echo '<script>location.replace("change_password.php");</script>';
    return;
}

$user_role = $_SESSION['role'] ?? 'customer';
$user_name = $_SESSION['user_name'] ?? 'مستخدم شاي سيّار';
$job_title = $_SESSION['job_title'] ?? 'المدير التنفيذي العام';
$location  = $_SESSION['employee_location'] ?? '';

// 👑 [دالة حقن رمز الريال العُماني القياسي الموحد لشاي سيار]:
if (!function_exists('get_omr_symbol')) {
    function get_omr_symbol() {
        $base_path = 'Ro/RO.svg';
        if (file_exists($base_path)) {
            $version = filemtime($base_path);
            return '<img src="' . $base_path . '?v=' . $version . '" class="omr-official-svg-icon" alt="﷼" style="height: 1.15em; width: auto; vertical-align: middle; margin-left: 4px; display: inline-block; background: transparent !important; background-color: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; box-shadow: none !important; filter: invert(21%) sepia(85%) saturate(3501%) hue-rotate(346deg) brightness(85%) contrast(85%) !important;">';
        } else {
            return '<span style="font-weight:bold; font-size:12px; margin-left:3px; color:#B82929; background: transparent !important; border: none !important;">ريال</span>';
        }
    }
}

// 🎬 [الالتقاط الذكي التلقائي لتحديثات فيديو التحميل المباشر وقراءة توقيت الملف لمنع الكاش]:
$video_path = 'tea_loading.mp4';
$video_version_param = file_exists($video_path) ? filemtime($video_path) : time();
?>

<!-- 🎬 [حاوية فيديو التحميل المحدثة بالكسر الآلي لذاكرة المتصفح Cache] -->
<div id="sysGlobalVideoLoaderZone" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: #0E121A; z-index: 99999999; display: flex; justify-content: center; align-items: center; transition: opacity 0.4s ease;">
    <video autoplay muted loop playsinline style="width: 100%; height: 100%; object-fit: cover; max-width: 500px; max-height: 500px;">
        <source src="<?php echo $video_path . '?v=' . $video_version_param; ?>" type="video/mp4">
        جاري تحميل نظام شاي سيار...
    </video>
</div>

<div class="sidebar-global-overlay-layer" id="sidebarGlobalOverlay" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.4); z-index: 99998; display: none; backdrop-filter: blur(2px);"></div>

<style>
    /* 🔒 عزل وحماية التنسيق بالكامل بداخل كلاس .sys-sidebar */
    .sys-sidebar {
        width: 240px !important;
        background-color: #0E121A !important;
        color: #EFEBE3 !important;
        height: 100vh !important;
        max-height: 100vh !important;
        padding: 20px 12px !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        border-left: 1px solid rgba(239, 235, 227, 0.1) !important;
        flex-shrink: 0 !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
    }

    .sys-sidebar .sidebar-top-container {
        display: flex !important;
        flex-direction: column !important;
        flex-grow: 1 !important;
        overflow: hidden !important;
    }

    .sys-sidebar .sidebar-brand {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 8px !important;
        padding-bottom: 15px !important;
        border-bottom: 1px solid rgba(239, 235, 227, 0.15) !important;
        margin-bottom: 15px !important;
        width: 100% !important;
        flex-shrink: 0 !important;
    }
    .sys-sidebar .sidebar-brand h2 {
        color: #B82929 !important;
        font-size: 22px !important;
        font-weight: 700 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: none !important;
    }
    
    .sys-sidebar .sidebar-cup-close-trigger {
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        max-width: 38px !important;
        display: inline-flex !important;
        justify-content: center !important;
        align-items: center !important;
        cursor: pointer !important;
        border-radius: 50% !important;
        background: rgba(184, 41, 41, 0.12) !important;
        border: 1px solid rgba(184, 41, 41, 0.25) !important;
        transition: all 0.3s ease !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
    }
    .sys-sidebar .sidebar-cup-close-trigger:hover {
        background: rgba(184, 41, 41, 0.25) !important;
        transform: scale(1.08) rotate(-10deg) !important;
    }
    .sys-sidebar .sidebar-cup-close-trigger img {
        width: 20px !important;
        height: 20px !important;
        object-fit: contain !important;
    }

    .sys-sidebar .sidebar-user-card {
        background: rgba(239, 235, 227, 0.05) !important;
        padding: 10px !important;
        border-radius: 10px !important;
        margin-bottom: 15px !important;
        text-align: right !important;
        width: 100% !important;
        flex-shrink: 0 !important;
    }
    .sys-sidebar .sidebar-user-card h4 {
        font-size: 13px !important;
        color: #EFEBE3 !important;
        margin: 0 0 4px 0 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    .sys-sidebar .sidebar-user-card p {
        font-size: 11px !important;
        color: #B82929 !important;
        font-weight: 700 !important;
        margin: 0 !important;
    }

    /* 📜 [خاصية التمرير الحركي الذكي للقائمة] */
    .sys-sidebar .sidebar-menu-scrollable-zone {
        list-style: none !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
        flex-grow: 1 !important;
        padding: 0 4px 10px 0 !important;
        margin: 0 !important;
        width: 100% !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        scroll-behavior: smooth !important;
    }

    .sys-sidebar .sidebar-menu-scrollable-zone::-webkit-scrollbar {
        width: 5px !important;
    }
    .sys-sidebar .sidebar-menu-scrollable-zone::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.02) !important;
        border-radius: 10px !important;
    }
    .sys-sidebar .sidebar-menu-scrollable-zone::-webkit-scrollbar-thumb {
        background: rgba(184, 41, 41, 0.4) !important;
        border-radius: 10px !important;
    }
    .sys-sidebar .sidebar-menu-scrollable-zone::-webkit-scrollbar-thumb:hover {
        background: rgba(184, 41, 41, 0.8) !important;
    }

    .sys-sidebar .sidebar-menu-item {
        padding: 0 !important;
        margin: 0 !important;
        list-style: none !important;
    }
    .sys-sidebar .sidebar-menu-item a {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        color: #EFEBE3 !important;
        text-decoration: none !important;
        padding: 10px 12px !important;
        border-radius: 8px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        transition: all 0.3s ease !important;
        width: 100% !important;
        background: none !important;
    }
    .sys-sidebar .sidebar-menu-item a:hover {
        background-color: #B82929 !important;
        color: #FFFFFF !important;
    }
    .sys-sidebar .menu-cup-icon {
        width: 16px !important;
        height: 16px !important;
        object-fit: contain !important;
    }

    /* 🏷️ [عنوان قسم فاصل] يُميّز مجموعات الروابط منطقياً حسب نوع الاستخدام */
    .sys-sidebar .sidebar-section-label {
        font-size: 10px !important;
        font-weight: 700 !important;
        color: rgba(239, 235, 227, 0.4) !important;
        padding: 10px 12px 4px 12px !important;
        margin: 0 !important;
        letter-spacing: 0.5px !important;
        border-top: 1px solid rgba(239, 235, 227, 0.08) !important;
        list-style: none !important;
    }
    .sys-sidebar .sidebar-section-label:first-child {
        border-top: none !important;
        padding-top: 0 !important;
    }

    .sys-sidebar .sidebar-footer {
        padding-top: 12px !important;
        border-top: 1px solid rgba(239, 235, 227, 0.1) !important;
        width: 100% !important;
        margin-top: auto !important;
        flex-shrink: 0 !important;
    }
    .sys-sidebar .btn-sidebar-logout {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        color: #B82929 !important;
        text-decoration: none !important;
        padding: 10px 12px !important;
        border-radius: 8px !important;
        font-size: 13px !important;
        font-weight: 700 !important;
        transition: background 0.3s !important;
        border: 1px solid rgba(184, 41, 41, 0.2) !important;
        width: 100% !important;
    }
    .sys-sidebar .btn-sidebar-logout:hover {
        background-color: #B82929 !important;
        color: white !important;
    }
</style>

<div class="sys-sidebar">
    <div class="sidebar-top-container">
        <div class="sidebar-brand">
            <h2>شاي سيّار</h2>
            <div class="sidebar-cup-close-trigger" id="sidebarCupCloseBtn" title="إخفاء القائمة الجانبية">
                <?php if (file_exists('logo_cup.png')): ?>
                    <img src="logo_cup.png" alt="إغلاق">
                <?php else: ?>
                    <i class="bi bi-cup-hot-fill" style="color: #B82929; font-size: 18px;"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="sidebar-user-card">
            <h4><?php echo htmlspecialchars($user_name); ?> 👋</h4>
            <p><?php echo htmlspecialchars($job_title ? $job_title : 'مستخدم النظام'); ?></p>
        </div>

        <ul class="sidebar-menu-scrollable-zone">
            <li class="sidebar-menu-item">
                <a href="pos.php"><i class="bi bi-cpu-fill"></i> <span>شاشة نقاط البيع (POS)</span></a>
            </li>
            <li class="sidebar-menu-item">
                <a href="online_order.php" target="_blank"><i class="bi bi-globe2"></i> <span>بوابة طلبات الزبائن (أونلاين)</span></a>
            </li>
            <li class="sidebar-menu-item">
                <a href="printer_settings.php"><i class="bi bi-printer-fill"></i> <span>الطابعات (بحث وربط)</span></a>
            </li>
            <li class="sidebar-menu-item">
                <a href="change_password.php"><i class="bi bi-key-fill"></i> <span>كلمة المرور</span></a>
            </li>

            <?php if ($user_role === 'executive'): ?>
                <li class="sidebar-menu-item">
                    <a href="executive_dashboard.php">
                        <?php if (file_exists('logo_cup.png')): ?>
                            <img src="logo_cup.png" class="menu-cup-icon" alt="الكوب">
                        <?php else: ?>
                            <i class="bi bi-cup-hot-fill" style="color: #B82929;"></i>
                        <?php endif; ?>
                        <span>التحكم التنفيذي الكلي</span>
                    </a>
                </li>

                <li class="sidebar-section-label">🍳 المطبخ والإنتاج</li>
                <li class="sidebar-menu-item">
                    <a href="kitchen_production.php"><i class="bi bi-egg-fried"></i> <span>واجهة إعداد المنتجات</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="kitchen_prep.php"><i class="bi bi-mortarboard-fill"></i> <span>تحضيرات وتجهيز المطبخ</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="recipe_management.php"><i class="bi bi-journal-plus"></i> <span>تأسيس المكونات والوصفات</span></a>
                </li>

                <li class="sidebar-section-label">📦 المخزون والمشتريات</li>
                <li class="sidebar-menu-item">
                    <a href="main_inventory.php"><i class="bi bi-box-seam"></i> <span>المخزن الرئيسي</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="purchases.php"><i class="bi bi-receipt-cutoff"></i> <span>المشتريات الذكية (AI)</span></a>
                </li>

                <li class="sidebar-section-label">🛍️ المنتجات والعملاء</li>
                <li class="sidebar-menu-item">
                    <a href="product_management.php"><i class="bi bi-bag-plus-fill"></i> <span>إدارة وتهيئة المنتجات</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="ai_aliases.php"><i class="bi bi-mic-fill"></i> <span>ذاكرة الطلب الصوتي (AI)</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="customers.php"><i class="bi bi-people-fill"></i> <span>إدارة العملاء والولاء</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="offers.php"><i class="bi bi-gift-fill"></i> <span>إدارة العروض والمكافئات</span></a>
                </li>

                <li class="sidebar-section-label">👥 الموظفون</li>
                <li class="sidebar-menu-item">
                    <a href="manage_employees.php"><i class="bi bi-person-vcard-fill"></i> <span>طاقم الموظفين والفرع</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="payroll.php"><i class="bi bi-calculator-fill"></i> <span>الرواتب والسلفيات</span></a>
                </li>

                <li class="sidebar-section-label">💰 المالية والحسابات</li>
                <li class="sidebar-menu-item">
                    <a href="expenses.php"><i class="bi bi-receipt"></i> <span>المصاريف وسندات الصرف</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="accounts.php"><i class="bi bi-journal-bookmark-fill"></i> <span>الدفاتر المالية والحسابات</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="general_ledger.php"><i class="bi bi-journal-check"></i> <span>حساب الأستاذ العام (الموحد)</span></a>
                </li>

                <li class="sidebar-section-label">📊 التقارير والإعدادات</li>
                <li class="sidebar-menu-item">
                    <a href="executive_reports.php"><i class="bi bi-graph-up-arrow"></i> <span>التقارير الشاملة</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="company_settings.php"><i class="bi bi-gear-fill"></i> <span>إعدادات الفروع والمالية</span></a>
                </li>
            <?php endif; ?>

            <?php if ($user_role === 'branch_manager'): ?>
                <li class="sidebar-menu-item">
                    <a href="executive_dashboard.php"><i class="bi bi-building-gear"></i> <span>إدارة الفرع والعمليات</span></a>
                </li>

                <li class="sidebar-section-label">🍳 المطبخ والإنتاج</li>
                <li class="sidebar-menu-item">
                    <a href="kitchen_production.php"><i class="bi bi-egg-fried"></i> <span>واجهة إعداد المنتجات</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="kitchen_prep.php"><i class="bi bi-mortarboard-fill"></i> <span>تحضيرات وتجهيز المطبخ</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="recipe_management.php"><i class="bi bi-journal-plus"></i> <span>تأسيس المكونات والوصفات</span></a>
                </li>

                <li class="sidebar-section-label">📦 المخزون</li>
                <li class="sidebar-menu-item">
                    <a href="main_inventory.php"><i class="bi bi-box-seam"></i> <span>المخزن الرئيسي</span></a>
                </li>

                <li class="sidebar-section-label">🛍️ المنتجات والعملاء</li>
                <li class="sidebar-menu-item">
                    <a href="product_management.php"><i class="bi bi-bag-plus-fill"></i> <span>إدارة وتهيئة المنتجات</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="ai_aliases.php"><i class="bi bi-mic-fill"></i> <span>ذاكرة الطلب الصوتي (AI)</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="customers.php"><i class="bi bi-people-fill"></i> <span>إدارة العملاء والولاء</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="offers.php"><i class="bi bi-gift-fill"></i> <span>إدارة العروض والمكافئات</span></a>
                </li>

                <li class="sidebar-section-label">👥 الموظفون</li>
                <li class="sidebar-menu-item">
                    <a href="manage_employees.php"><i class="bi bi-person-vcard-fill"></i> <span>طاقم الموظفين</span></a>
                </li>

                <li class="sidebar-section-label">💰 المالية والحسابات</li>
                <li class="sidebar-menu-item">
                    <a href="expenses.php"><i class="bi bi-receipt"></i> <span>المصاريف وسندات الصرف</span></a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="accounts.php"><i class="bi bi-journal-bookmark-fill"></i> <span>الدفاتر المالية والحسابات</span></a>
                </li>
            <?php endif; ?>

            <?php if ($user_role === 'cashier'): ?>
                <li class="sidebar-menu-item">
                    <a href="kitchen_prep.php"><i class="bi bi-mortarboard-fill"></i> <span>تحضيرات المطبخ</span></a>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="sidebar-footer">
        <a href="logout.php" class="btn-sidebar-logout">
            <i class="bi bi-box-arrow-right"></i>
            <span>تسجيل الخروج</span>
        </a>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const loaderZone = document.getElementById('sysGlobalVideoLoaderZone');
    if (loaderZone) {
        setTimeout(function() {
            loaderZone.style.opacity = '0';
            setTimeout(function() {
                loaderZone.style.display = 'none';
            }, 400);
        }, 600);
    }

    const sidebarCupCloseBtn = document.getElementById("sidebarCupCloseBtn");
    const sidebarGlobalOverlay = document.getElementById("sidebarGlobalOverlay");
    
    if (sidebarCupCloseBtn) {
        sidebarCupCloseBtn.addEventListener("click", function(e) {
            e.stopPropagation();
            document.body.classList.remove("sidebar-show");
        });
    }
    if (sidebarGlobalOverlay) {
        sidebarGlobalOverlay.addEventListener("click", function() {
            document.body.classList.remove("sidebar-show");
        });
    }
});
</script>