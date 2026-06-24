<?php
/**
 * Customer viewer page - loads link configuration and displays limited view
 */

session_start();

// Get link ID from query parameter
$linkId = $_GET['link'] ?? '';

if (empty($linkId)) {
    die('Link ID is required');
}

// Load XML and get link configuration
define('DATA_FILE', __DIR__ . '/data.xml');

function loadXML() {
    if (!file_exists(DATA_FILE)) {
        return null;
    }
    return simplexml_load_file(DATA_FILE);
}

function getLinkConfig($linkId) {
    $xml = loadXML();
    if (!$xml) {
        return null;
    }
    
    foreach ($xml->links->link as $link) {
        if ((string)$link['id'] === $linkId) {
            return [
                'id' => (string)$link['id'],
                'name' => (string)$link->name,
                'showPrice' => (string)$link->showPrice === 'true',
                'showQuantity' => (string)$link->showQuantity === 'true',
                'requirePassword' => isset($link->requirePassword) ? (string)$link->requirePassword === 'true' : true,
                'filters' => [
                    'keyword' => (string)$link->filters->keyword,
                    'materialTypes' => array_filter(explode(',', (string)$link->filters->materialTypes)),
                    'targetCategories' => array_filter(explode(',', (string)$link->filters->targetCategories)),
                    'manufacturers' => array_filter(explode(',', (string)$link->filters->manufacturers)),
                    'minQuantity' => (string)$link->filters->minQuantity
                ],
                'username' => (string)$link->username
            ];
        }
    }
    return null;
}

$linkConfig = getLinkConfig($linkId);

if (!$linkConfig) {
    die('Link not found');
}

// Check if user is authenticated for this link
$requirePassword = isset($linkConfig['requirePassword']) ? $linkConfig['requirePassword'] : true;
$isAuthenticated = isset($_SESSION['link_' . $linkId . '_authenticated']) && $_SESSION['link_' . $linkId . '_authenticated'];

// If password is required and user is not authenticated, show login form
if ($requirePassword && !$isAuthenticated) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>تسجيل الدخول</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
        <style>
            body {
                background-color: #f5f5f5;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }
            .login-container {
                max-width: 400px;
                padding: 30px;
                background: white;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h2 class="text-center mb-4">تسجيل الدخول</h2>
            <form id="loginForm">
                <input type="hidden" id="linkId" value="<?php echo htmlspecialchars($linkId); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">اسم المستخدم</label>
                    <input type="text" class="form-control" id="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">كلمة المرور</label>
                    <input type="password" class="form-control" id="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">تسجيل الدخول</button>
            </form>
            <div id="loginError" class="alert alert-danger mt-3" style="display: none;"></div>
        </div>
        <script>
            document.getElementById('loginForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const linkId = document.getElementById('linkId').value;
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;

                try {
                    const response = await fetch('auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'login_link', linkId, username, password })
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        window.location.reload();
                    } else {
                        document.getElementById('loginError').textContent = data.message;
                        document.getElementById('loginError').style.display = 'block';
                    }
                } catch (error) {
                    document.getElementById('loginError').textContent = 'حدث خطأ في الاتصال';
                    document.getElementById('loginError').style.display = 'block';
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// User is authenticated, show the viewer
// Load company information
$companyInfo = require __DIR__ . '/company_info.php';

// Pass configuration to JavaScript
$configJson = json_encode($linkConfig);
$companyInfoJson = json_encode($companyInfo);
?>
<!DOCTYPE html>
<html class="light" lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo htmlspecialchars($companyInfo['name'] . ' (' . $linkConfig['name'] . ')'); ?></title>
    
    <!-- Open Graph / Facebook / WhatsApp -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($companyInfo['name'] . ' (' . $linkConfig['name'] . ')'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($linkConfig['name']); ?>">
    <?php 
    $logoUrl = '';
    if (!empty($companyInfo['logo'])) {
        // Get base URL
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $baseUrl = $protocol . '://' . $host;
        $logoPath = $companyInfo['logo'];
        // If logo path doesn't start with http, make it relative to base URL
        if (!preg_match('/^https?:\/\//', $logoPath)) {
            $logoUrl = $baseUrl . '/' . ltrim($logoPath, '/');
        } else {
            $logoUrl = $logoPath;
        }
    }
    if (!empty($logoUrl)): ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($logoUrl); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php endif; ?>
    <meta property="og:url" content="<?php echo htmlspecialchars($protocol . '://' . $host . $_SERVER['REQUEST_URI']); ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($companyInfo['name']); ?>">
    
    <!-- Favicon / Tab Icon -->
    <?php if (!empty($logoUrl)): ?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($logoUrl); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($logoUrl); ?>">
    <?php endif; ?>
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($companyInfo['name'] . ' (' . $linkConfig['name'] . ')'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($linkConfig['name']); ?>">
    <?php if (!empty($logoUrl)): ?>
    <meta name="twitter:image" content="<?php echo htmlspecialchars($logoUrl); ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "primary": "#D81921",
              "background-light": "#f6f6f8",
              "background-dark": "#101622",
            },
            fontFamily: {
              "display": ["Manrope", "sans-serif"]
            },
            borderRadius: {"DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px"},
          },
        },
      }
    </script>
    <style type="text/tailwindcss">
    body {
        --stripe-color: rgba(0, 0, 0, 0.03);
        background-color: #f6f6f8;
        background-image:
            linear-gradient(45deg, var(--stripe-color) 25%, transparent 25%),
            linear-gradient(-45deg, var(--stripe-color) 25%, transparent 25%),
            linear-gradient(45deg, transparent 75%, var(--stripe-color) 75%),
            linear-gradient(-45deg, transparent 75%, var(--stripe-color) 75%);
        background-size: 20px 20px;
        font-family: "Manrope", sans-serif;
    }
    .material-symbols-outlined {
        font-variation-settings:
        'FILL' 0,
        'wght' 400,
        'GRAD' 0,
        'opsz' 24
    }
    </style>
    <style>
        #imagePopupOverlay {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background-color: rgba(0, 0, 0, 0.6);
            z-index: 9998;
        }

        #imagePopup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: white;
            padding: 10px;
            border-radius: 12px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.4);
            z-index: 9999;
            overflow: hidden;
        }

        #imagePopup img {
            max-width: 90vw;
            max-height: 80vh;
            object-fit: contain;
            border-radius: 8px;
        }

        #imagePopupClose {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 36px;
            color: white;
            background-color: red;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            line-height: 40px;
            text-align: center;
            cursor: pointer;
            box-shadow: 0 0 8px rgba(0,0,0,0.3);
            transition: transform 0.2s, background-color 0.3s;
            z-index: 1000;
        }
        #imagePopupClose:hover {
            transform: scale(1.2);
            background-color: #cc0000;
        }
        @keyframes cartBounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.4); }
        }
        .cart-bounce {
            animation: cartBounce 0.5s ease-in-out;
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        .item-added-notification {
            position: fixed;
            top: 80px;
            right: 20px;
            background: #D81921;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10000;
            animation: slideIn 0.3s ease-out;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hidden {
            display: none !important;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-white dark:bg-gray-900 shadow-sm sticky top-0 z-30">
        <div class="px-4 sm:px-6 lg:px-10 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <img alt="<?php echo htmlspecialchars($companyInfo['name']); ?> Logo" class="h-10 w-auto" src="<?php echo htmlspecialchars($companyInfo['logo']); ?>"/>
                    <div class="flex flex-col">
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($companyInfo['name']); ?></h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400 hidden sm:block"><?php echo htmlspecialchars($linkConfig['name']); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="hidden md:flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300 mr-4">
                        <?php if (!empty($companyInfo['location'])): ?>
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-xl text-gray-500 dark:text-gray-400">location_on</span>
                            <span><?php echo htmlspecialchars($companyInfo['location']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($companyInfo['phone'])): ?>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $companyInfo['phone'])); ?>" 
                           class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
                            <span class="material-symbols-outlined text-xl text-gray-700 dark:text-gray-300">call</span>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($companyInfo['phone']); ?></span>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($companyInfo['mobile'])): ?>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $companyInfo['mobile'])); ?>" 
                           class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-100 dark:bg-green-900 hover:bg-green-200 dark:hover:bg-green-800 transition-colors">
                            <span class="material-symbols-outlined text-xl text-green-700 dark:text-green-300">phone_android</span>
                            <span class="text-sm font-medium text-green-700 dark:text-green-300"><?php echo htmlspecialchars($companyInfo['mobile']); ?></span>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($companyInfo['whatsapp'])): ?>
                        <a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $companyInfo['whatsapp'])); ?>" 
                           target="_blank"
                           class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-[#25D366] hover:bg-[#20BA5A] transition-colors">
                            <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                            </svg>
                            <span class="text-sm font-medium text-white">واتساب</span>
                        </a>
                        <?php endif; ?>
                    </div>
                    <button onclick="showCart()" class="relative p-2 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700">
                        <span class="material-symbols-outlined text-xl text-gray-800 dark:text-gray-200">shopping_cart</span>
                        <span id="cartCountBadge" class="absolute -top-1 -right-1 bg-primary text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center hidden">0</span>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 px-4 sm:px-6 lg:px-10 py-6 sm:py-8">
        <div class="max-w-screen-xl mx-auto">
            <!-- Toast Notification -->
            <div id="toast" style="visibility: hidden; min-width: 250px; max-width: 90%; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px 24px; position: fixed; z-index: 99999; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 17px; transition: visibility 0s, opacity 0.5s ease; opacity: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
                تم
            </div>

            <!-- Image Popup -->
            <div id="imagePopupOverlay" onclick="hidePopupImage()"></div>
            <div id="imagePopup">
                <span id="imagePopupClose" onclick="hidePopupImage()">×</span>
                <img src="" alt="صورة مكبرة">
                <div id="popupProductInfo" style="margin-top: 10px; text-align: center; color: #333;"></div>
            </div>

            <!-- Page Title -->
            <div class="mb-6">
                <h2 class="text-2xl sm:text-3xl font-black text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($linkConfig['name']); ?></h2>
                <p class="text-gray-500 dark:text-gray-400 text-sm" id="resultsCount">جاري التحميل...</p>
            </div>

            <!-- Products Grid -->
            <div id="productList" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-6">جاري التحميل...</div>
            
            <!-- Pagination -->
            <div class="flex justify-center mt-8 sm:mt-12">
                <nav aria-label="Pagination" class="flex items-center gap-2 flex-wrap justify-center" id="pagination"></nav>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-900 dark:bg-black text-white py-6 mt-8">
        <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-10">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <img alt="<?php echo htmlspecialchars($companyInfo['name']); ?> Logo" class="h-8 w-auto" src="<?php echo htmlspecialchars($companyInfo['logo']); ?>"/>
                    <h3 class="text-lg font-bold"><?php echo htmlspecialchars($companyInfo['name']); ?></h3>
                </div>
                <div class="flex flex-col md:flex-row items-center gap-4 md:gap-6 text-sm">
                    <?php if (!empty($companyInfo['location'])): ?>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-xl">location_on</span>
                        <span><?php echo htmlspecialchars($companyInfo['location']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['phone'])): ?>
                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $companyInfo['phone'])); ?>" 
                       class="flex items-center gap-2 hover:text-primary transition-colors">
                        <span class="material-symbols-outlined text-xl">call</span>
                        <span><?php echo htmlspecialchars($companyInfo['phone']); ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['mobile'])): ?>
                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $companyInfo['mobile'])); ?>" 
                       class="flex items-center gap-2 text-green-600 dark:text-green-400 hover:text-green-700 dark:hover:text-green-300 transition-colors">
                        <span class="material-symbols-outlined text-xl">phone_android</span>
                        <span><?php echo htmlspecialchars($companyInfo['mobile']); ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['whatsapp'])): ?>
                    <a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $companyInfo['whatsapp'])); ?>" 
                       target="_blank"
                       class="flex items-center gap-2 text-[#25D366] hover:text-[#20BA5A] transition-colors">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                        </svg>
                        <span>واتساب</span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['email'])): ?>
                    <a href="mailto:<?php echo htmlspecialchars($companyInfo['email']); ?>" 
                       class="flex items-center gap-2 hover:text-primary transition-colors">
                        <span class="material-symbols-outlined text-xl">email</span>
                        <span><?php echo htmlspecialchars($companyInfo['email']); ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <!-- Shopping Cart Sidebar -->
    <div id="shoppingCart" class="fixed top-0 right-0 h-full w-full md:w-96 bg-white dark:bg-gray-800 shadow-xl z-50 transform translate-x-full transition-transform duration-300 overflow-y-auto">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">سلة المشتريات</h2>
                <button onclick="hideCart()" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <span class="material-symbols-outlined text-gray-600 dark:text-gray-300">close</span>
                </button>
            </div>
            <ul id="cartItemsList" class="space-y-4">
                <li class="text-gray-500 dark:text-gray-400 text-center py-8">لا توجد منتجات في السلة.</li>
            </ul>
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4">
                <div id="cartTotalSp" class="text-lg font-bold text-gray-900 dark:text-white mb-2">الإجمالي: 0 ل.س</div>
                <div id="cartTotalUsd" class="text-sm text-gray-600 dark:text-gray-400 mb-4">الإجمالي: 0 USD</div>
                <button onclick="clearCart()" class="w-full flex items-center justify-center rounded-lg h-10 px-4 bg-red-600 text-white text-sm font-bold mb-2">تفريغ السلة</button>
                <button onclick="confirmOrder()" class="w-full flex items-center justify-center rounded-lg h-10 px-4 bg-green-600 text-white text-sm font-bold">تأكيد الطلب</button>
            </div>
        </div>
    </div>
    <div id="cartOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden" onclick="hideCart()"></div>

    <!-- Order Confirmation Modal -->
    <div id="orderModalOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden" onclick="hideOrderModal()"></div>
    <div id="orderModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6" onclick="event.stopPropagation()">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-2xl font-bold text-gray-900 dark:text-white">تأكيد الطلب</h3>
                <button onclick="hideOrderModal()" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <span class="material-symbols-outlined text-gray-600 dark:text-gray-300">close</span>
                </button>
            </div>
            
            <form id="orderForm" onsubmit="submitOrder(event)">
                <div class="mb-4">
                    <label for="customerName" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        اسم العميل <span class="text-red-500">*</span>
                    </label>
                    <input type="text" 
                           id="customerName" 
                           name="customerName" 
                           required
                           class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                           placeholder="أدخل اسمك الكامل">
                </div>
                
                <div class="mb-6">
                    <label for="customerPhone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        رقم الهاتف <span class="text-red-500">*</span>
                    </label>
                    <input type="tel" 
                           id="customerPhone" 
                           name="customerPhone" 
                           required
                           pattern="[0-9+\-\s()]+"
                           class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                           placeholder="مثال: 0991234567">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">يرجى إدخال رقم هاتف صحيح للتواصل معك</p>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" 
                            onclick="hideOrderModal()" 
                            class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 font-medium transition-colors">
                        إلغاء
                    </button>
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium transition-colors flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-lg">check</span>
                        تأكيد الطلب
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Get API proxy URL
        function getApiProxyUrl() {
            return "api_proxy.php";
        }

        // Link configuration from PHP
        const linkConfig = <?php echo $configJson; ?>;
        const pageSize = 12;
        let currentPage = 1;
        let materialsData = [];
        let cart = [];

        // Format number
        function formatNumber(number) {
            return number.toLocaleString('En');
        }

        // Load materials with predefined filters
        async function loadMaterials(page = 1) {
            currentPage = page;
            
            const query = {
                keyword: linkConfig.filters.keyword || '',
                materialTypes: linkConfig.filters.materialTypes || [],
                targetCategories: linkConfig.filters.targetCategories || [],
                manufacturers: linkConfig.filters.manufacturers || [],
                minQuantity: parseFloat(linkConfig.filters.minQuantity) || 0,
                pageIndex: page,
                pageSize: pageSize
            };

            try {
                const res = await fetch(`${getApiProxyUrl()}?action=mat_filter`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(query)
                });

                const result = await res.json();
                materialsData = result.data || [];
                displayMaterials(materialsData);
                renderPagination(result.page, result.pageCount);
                
                // Update results count
                const resultsCount = document.getElementById('resultsCount');
                if (resultsCount) {
                    const total = result.totalCount || materialsData.length;
                    resultsCount.textContent = `تم العثور على ${total} منتج`;
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('productList').innerHTML = '<p>حدث خطأ في تحميل البيانات</p>';
            }
        }

        // Get base URL for images
        function GetIp() {
            let apiBaseUrl = "";
            const currentHost = window.location.hostname;
            if (currentHost.startsWith("192.168.") || currentHost === "localhost") {
                apiBaseUrl = "http://192.168.1.9";
            } else {
                apiBaseUrl = "https://jawish.ddns.net";
            }
            return apiBaseUrl;
        }

        // Display materials with limited view
        function displayMaterials(items) {
            const container = document.getElementById("productList");
            container.innerHTML = "";

            if (!items || items.length === 0) {
                container.innerHTML = "<p class='text-gray-500 dark:text-gray-400 text-center col-span-full py-8'>لا توجد مواد مطابقة.</p>";
                return;
            }
            
            // Log materials viewed
            logVisitorAction('materials_viewed', `Viewed ${items.length} materials on page ${currentPage}`);
            
            const BaseUrl = GetIp();
            items.forEach(item => {
                let ThumbnailImageUrl = item.imageUrl == '' 
                    ? BaseUrl + '/No_image_available.svg.png' 
                    : BaseUrl + item.imageUrl.replace("/images/", "/images/thumbnails/");
                let ImageUrl = item.imageUrl == '' 
                    ? BaseUrl + '/No_image_available.svg.png' 
                    : BaseUrl + item.imageUrl;
                
                const isAvailable = (item.availableQunatity ?? 0) > 0;
                const stockBadge = isAvailable ? 
                    '<span class="absolute top-3 right-3 bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded-full dark:bg-green-900 dark:text-green-300">متوفر</span>' :
                    '<span class="absolute top-3 right-3 bg-red-100 text-red-800 text-xs font-semibold px-2.5 py-0.5 rounded-full dark:bg-red-900 dark:text-red-300">غير متوفر</span>';

                let cardHTML = `
                    <div class="bg-white dark:bg-gray-800 rounded-xl overflow-hidden shadow-sm hover:shadow-lg transition-shadow duration-300 flex flex-col">
                        <div class="relative">
                            <img class="w-full h-48 object-contain cursor-zoom-in bg-gray-50 dark:bg-gray-700" 
                                 src="${ThumbnailImageUrl}" 
                                 alt="صورة المادة"
                                 onclick="showPopupImage('${ImageUrl}', '${item.guid}')">
                            ${stockBadge}
                        </div>
                        <div class="p-4 flex flex-col flex-grow">
                            <h3 class="font-bold text-gray-800 dark:text-white mb-1">${item.name ?? "?"}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">${item.code ?? "?"}</p>
                `;

                // Show price only if enabled
                if (linkConfig.showPrice) {
                    cardHTML += `
                            <p class="text-lg font-black text-gray-900 dark:text-white mb-2">
                                ${formatNumber(item.salePrice_SP ?? 0)} ل.س
                                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">/ ${formatNumber((item.salePrice_Usd ?? 0).toFixed(2))} $</span>
                            </p>
                    `;
                }

                // Show quantity only if enabled
                if (linkConfig.showQuantity) {
                    cardHTML += `
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">التعبئة: ${item.pcsPerBox ?? "?"} | المتاح: ${Math.floor((item.availableQunatity ?? 0) / (item.pcsPerBox || 1))}</p>
                    `;
                }

                // Add to cart button
                cardHTML += `
                            <div class="flex items-center gap-2 mt-auto">
                                <button ${!isAvailable ? 'disabled' : ''} onclick="addToCart('${item.guid}')" class="flex-1 flex items-center justify-center overflow-hidden rounded-lg h-10 px-4 ${isAvailable ? 'bg-primary text-white hover:bg-red-700' : 'bg-gray-300 dark:bg-gray-600 text-gray-500 dark:text-gray-400 cursor-not-allowed'} text-sm font-bold transition-colors">
                                    ${isAvailable ? 'إضافة إلى السلة' : 'غير متوفر'}
                                </button>
                            </div>
                        </div>
                    </div>
                `;

                container.innerHTML += cardHTML;
            });
            
            // Scroll to first product after rendering
            setTimeout(() => {
                const firstProduct = container.querySelector('.bg-white');
                if (firstProduct) {
                    firstProduct.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    // Fallback: scroll to top of product list container
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        }

        // Render pagination
        function renderPagination(current, total) {
            const container = document.getElementById("pagination");
            container.innerHTML = "";

            if (total <= 1) return;

            if (current > 1) {
                container.innerHTML += `<button onclick="loadMaterials(1)" style="margin: 0 5px; padding: 6px 12px; border: none; background-color: #ddd; border-radius: 4px; cursor: pointer;">الأول</button>`;
            }

            const range = 2;
            const start = Math.max(1, current - range);
            const end = Math.min(total, current + range);

            for (let i = start; i <= end; i++) {
                container.innerHTML += `<button onclick="loadMaterials(${i})" style="margin: 0 5px; padding: 6px 12px; border: none; background-color: ${i === current ? '#007bff' : '#ddd'}; color: ${i === current ? 'white' : 'black'}; border-radius: 4px; cursor: pointer;">${i}</button>`;
            }

            if (current < total) {
                container.innerHTML += `<button onclick="loadMaterials(${total})" style="margin: 0 5px; padding: 6px 12px; border: none; background-color: #ddd; border-radius: 4px; cursor: pointer;">الأخير (${total})</button>`;
            }
        }

        // Add to cart function with quantity counter
        function addToCart(productGUID) {
            const product = materialsData.find(item => item.guid === productGUID);
            if (product) {
                // Log material added to cart
                logVisitorAction('add_to_cart', `${product.code} - ${product.name}`);
                // Check if product already exists in cart
                const existingItem = cart.find(item => item.guid === productGUID);
                if (existingItem) {
                    // Increment quantity
                    if (!existingItem.quantity) {
                        existingItem.quantity = 1;
                    }
                    existingItem.quantity++;
                } else {
                    // Add new item with quantity 1
                    product.quantity = 1;
                    cart.push(product);
                }
                updateCartUI();
                animateCartBadge("تمت الإضافة إلى السلة");
                ShowToast("تم الاضافة");
            }
        }

        function animateCartBadge(message) {
            const cartBadge = document.getElementById("cartCountBadge");
            const cartButton = cartBadge?.closest('button');
            
            // Animate the cart button
            if (cartButton) {
                cartButton.classList.add("cart-bounce");
                setTimeout(() => {
                    cartButton.classList.remove("cart-bounce");
                }, 500);
            }
        }

        // Show toast notification
        function ShowToast(message) {
            const toast = document.getElementById("toast");
            toast.innerText = message;
            toast.style.visibility = "visible";
            toast.style.opacity = "1";

            setTimeout(() => {
                toast.style.opacity = "0";
                toast.style.visibility = "hidden";
            }, 3000);
        }

        // Remove from cart
        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCartUI();
            ShowToast("تم الحذف");
        }

        // Image popup navigation
        let currentPopupIndex = -1;
        let FromCart = false;

        function showPopupImage(imageUrl, guid) {
            const baseUrl = GetIp();
            FromCart = false;
            currentPopupIndex = -1;

            let index = materialsData.findIndex(m => m.guid === guid);
            if (index !== -1) {
                FromCart = false;
                currentPopupIndex = index;
            } else {
                index = cart.findIndex(cartItem => {
                    const item = (cartItem && cartItem.item) ? cartItem.item : cartItem;
                    return item && item.guid === guid;
                });
                if (index !== -1) {
                    FromCart = true;
                    currentPopupIndex = index;
                } else {
                    document.querySelector('#imagePopup img').src = baseUrl + '/No_image_available.svg.png';
                }
            }

            document.querySelector('#imagePopup img').src = imageUrl;
            document.getElementById('imagePopup').style.display = 'block';
            document.getElementById('imagePopupOverlay').style.display = 'block';
            updatePopupProductInfo();
        }

        function hidePopupImage() {
            document.getElementById('imagePopup').style.display = 'none';
            document.getElementById('imagePopupOverlay').style.display = 'none';
        }

        function navigatePopup(direction) {
            let item;
            const list = FromCart ? cart.map(cartItem => (cartItem && cartItem.item) ? cartItem.item : cartItem) : materialsData;
            const newIndex = currentPopupIndex + direction;
            if (newIndex >= 0 && newIndex < list.length) {
                currentPopupIndex = newIndex;
                item = list[currentPopupIndex];
                const baseUrl = GetIp();
                const imageUrl = item.imageUrl == '' 
                    ? baseUrl + '/No_image_available.svg.png' 
                    : baseUrl + item.imageUrl;
                document.querySelector('#imagePopup img').src = imageUrl;
                updatePopupProductInfo();
            }
        }

        function updatePopupProductInfo() {
            let item;
            if (FromCart) {
                const cartItem = cart[currentPopupIndex];
                item = (cartItem && cartItem.item) ? cartItem.item : cartItem;
            } else {
                item = materialsData[currentPopupIndex];
            }
            if (item) {
                const baseUrl = GetIp();
                const thumbnailUrl = item.imageUrl == '' 
                    ? baseUrl + '/No_image_available.svg.png' 
                    : baseUrl + item.imageUrl.replace("/images/", "/images/thumbnails/");
                document.getElementById('popupProductInfo').innerHTML = `
                    <strong>${item.code} - ${item.name}</strong>
                    ${linkConfig.showPrice ? `<br>السعر: ${formatNumber(item.salePrice_SP ?? 0)} ل.س / $${formatNumber((item.salePrice_Usd ?? 0).toFixed(2))}` : ''}
                `;
            }
        }

        // Cart sidebar functions
        function showCart() {
            document.getElementById('shoppingCart').classList.remove('translate-x-full');
            document.getElementById('cartOverlay').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function hideCart() {
            document.getElementById('shoppingCart').classList.add('translate-x-full');
            document.getElementById('cartOverlay').classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Update quantity
        function updateQuantity(index, change) {
            const item = cart[index];
            if (!item.quantity) item.quantity = 1;
            item.quantity += change;
            if (item.quantity <= 0) {
                removeFromCart(index);
            } else {
                updateCartUI();
            }
        }

        // Update cart UI
        function updateCartUI() {
            const cartItemsList = document.getElementById("cartItemsList");
            const cartCountBadge = document.getElementById("cartCountBadge");
            cartItemsList.innerHTML = "";
            let totalSp = 0;
            let totalUsd = 0;
            let totalItems = 0;

            if (cart.length === 0) {
                cartItemsList.innerHTML = "<li class='text-gray-500 dark:text-gray-400 text-center py-8'>لا توجد منتجات في السلة.</li>";
                if (cartCountBadge) {
                    cartCountBadge.classList.add('hidden');
                }
            } else {
                const BaseUrl = GetIp();
                cart.forEach((item, index) => {
                    const quantity = item.quantity || 1;
                    totalItems += quantity;
                    const itemTotalSYP = item.salePrice_SP * item.pcsPerBox * quantity;
                    const itemTotalUSD = item.salePrice_Usd * item.pcsPerBox * quantity;
                    totalSp += itemTotalSYP;
                    totalUsd += itemTotalUSD;
                    
                    let ThumbnailImageUrl = item.imageUrl == '' 
                        ? BaseUrl + '/No_image_available.svg.png' 
                        : BaseUrl + item.imageUrl.replace("/images/", "/images/thumbnails/");
                    
                    let FullImageUrl = item.imageUrl == '' 
                        ? BaseUrl + '/No_image_available.svg.png' 
                        : BaseUrl + item.imageUrl;
                    
                    let cartItemHTML = `
                        <li class="flex items-center gap-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <img src="${ThumbnailImageUrl}" alt="صورة المادة" 
                                 class="w-20 h-20 object-contain rounded-lg cursor-pointer hover:opacity-80 transition-opacity" 
                                 onclick="showPopupImage('${FullImageUrl}', '${item.guid}')">
                            <div class="flex-grow">
                                <h4 class="font-bold text-gray-900 dark:text-white mb-1">${item.name}</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">الكود: ${item.code}</p>
                    `;
                    
                    // Show quantity only if enabled
                    if (linkConfig.showQuantity) {
                        cartItemHTML += `<p class="text-xs text-gray-500 dark:text-gray-400">التعبئة: ${item.pcsPerBox}</p>`;
                    }
                    
                    cartItemHTML += `</div>`;
                    
                    // Quantity controls
                    cartItemHTML += `
                        <div class="flex items-center gap-2">
                            <button onclick="updateQuantity(${index}, -1)" class="w-8 h-8 rounded-lg bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 flex items-center justify-center text-gray-700 dark:text-gray-200 font-bold">-</button>
                            <span class="w-10 text-center font-bold text-gray-900 dark:text-white">${quantity}</span>
                            <button onclick="updateQuantity(${index}, 1)" class="w-8 h-8 rounded-lg bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 flex items-center justify-center text-gray-700 dark:text-gray-200 font-bold">+</button>
                        </div>
                    `;
                    
                    // Show price only if enabled
                    if (linkConfig.showPrice) {
                        cartItemHTML += `
                            <div class="text-left min-w-[100px]">
                                <div class="font-bold text-gray-900 dark:text-white">${formatNumber(itemTotalSYP)} ل.س</div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">${formatNumber(itemTotalUSD.toFixed(2))} $</div>
                            </div>
                        `;
                    }
                    
                    cartItemHTML += `
                            <button onclick="removeFromCart(${index})" class="p-2 rounded-lg hover:bg-red-100 dark:hover:bg-red-900 text-red-600 dark:text-red-400">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </li>
                    `;
                    cartItemsList.innerHTML += cartItemHTML;
                });
                
                // Update cart badge
                if (cartCountBadge) {
                    cartCountBadge.textContent = totalItems;
                    cartCountBadge.classList.remove('hidden');
                }
            }

            // Update totals only if price is enabled
            if (linkConfig.showPrice) {
                document.getElementById("cartTotalSp").innerText = `الإجمالي: ${formatNumber(totalSp)} ل.س`;
                document.getElementById("cartTotalUsd").innerText = `الإجمالي: ${formatNumber(totalUsd.toFixed(2))} USD`;
            } else {
                document.getElementById("cartTotalSp").innerText = ``;
                document.getElementById("cartTotalUsd").innerText = ``;
            }
            
            // Save to localStorage with link-specific key
            const linkId = '<?php echo htmlspecialchars($linkId); ?>';
            localStorage.setItem("shoppingCart_" + linkId, JSON.stringify(cart));
        }

        // Clear cart
        function clearCart() {
            if (confirm("هل أنت متأكد من تفريغ السلة؟")) {
                cart = [];
                const linkId = '<?php echo htmlspecialchars($linkId); ?>';
                localStorage.removeItem("shoppingCart_" + linkId);
                updateCartUI();
                ShowToast("تم تفريغ السلة");
            }
        }

        // Show order confirmation modal
        function confirmOrder() {
            if (cart.length === 0) {
                ShowToast("السلة فارغة");
                return;
            }
            
            // Show modal
            document.getElementById('orderModalOverlay').classList.remove('hidden');
            document.getElementById('orderModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Focus on name input
            setTimeout(() => {
                document.getElementById('customerName').focus();
            }, 100);
        }

        // Hide order modal
        function hideOrderModal() {
            document.getElementById('orderModalOverlay').classList.add('hidden');
            document.getElementById('orderModal').classList.add('hidden');
            document.body.style.overflow = '';
            // Reset form
            document.getElementById('orderForm').reset();
            // Re-enable submit button if it was disabled
            const submitBtn = document.querySelector('#orderForm button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span class="material-symbols-outlined text-lg">check</span> تأكيد الطلب';
            }
        }

        // Submit order
        async function submitOrder(event) {
            event.preventDefault();
            
            const customerName = document.getElementById('customerName').value.trim();
            const customerPhone = document.getElementById('customerPhone').value.trim();
            
            // Validate inputs
            if (!customerName) {
                ShowToast("يجب إدخال اسم العميل");
                document.getElementById('customerName').focus();
                return;
            }
            
            if (!customerPhone) {
                ShowToast("يجب إدخال رقم الهاتف");
                document.getElementById('customerPhone').focus();
                return;
            }
            
            // Basic phone validation (at least 7 digits)
            const phoneDigits = customerPhone.replace(/\D/g, '');
            if (phoneDigits.length < 7) {
                ShowToast("يرجى إدخال رقم هاتف صحيح");
                document.getElementById('customerPhone').focus();
                return;
            }

            // Disable submit button
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="material-symbols-outlined animate-spin">sync</span> جاري الإرسال...';

            try {
                const response = await fetch('order_manager.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_order',
                        linkId: '<?php echo htmlspecialchars($linkId); ?>',
                        customerName: customerName,
                        customerPhone: customerPhone,
                        items: cart.map(item => ({
                            guid: item.guid,
                            code: item.code,
                            name: item.name,
                            quantity: item.quantity || 1,
                            pcsPerBox: item.pcsPerBox,
                            salePrice_SP: item.salePrice_SP,
                            salePrice_Usd: item.salePrice_Usd,
                            imageUrl: item.imageUrl || '' // Include image URL
                        }))
                    })
                });

                const data = await response.json();
                if (data.success) {
                    // Get GPS location for logging
                    const gpsLocation = await getGPSLocation();
                    
                    // Log order placed with customer name
                    logVisitorAction('order_placed', `Order ID: ${data.orderId}, Items: ${cart.length}, Phone: ${customerPhone}`, gpsLocation, customerName);
                    
                    ShowToast("تم تأكيد الطلب بنجاح");
                    hideOrderModal();
                    cart = [];
                    const linkId = '<?php echo htmlspecialchars($linkId); ?>';
                    localStorage.removeItem("shoppingCart_" + linkId);
                    updateCartUI();
                    hideCart();
                } else {
                    ShowToast("حدث خطأ: " + (data.error || 'غير معروف'));
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            } catch (error) {
                ShowToast("حدث خطأ في الاتصال");
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        }

        // Get GPS location
        function getGPSLocation() {
            return new Promise((resolve) => {
                if (!navigator.geolocation) {
                    resolve(null);
                    return;
                }
                
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        resolve({
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude,
                            accuracy: position.coords.accuracy
                        });
                    },
                    (error) => {
                        console.log('GPS error:', error.message);
                        resolve(null);
                    },
                    {
                        enableHighAccuracy: false,
                        timeout: 5000,
                        maximumAge: 300000 // Cache for 5 minutes
                    }
                );
            });
        }

        // Get or create unique visitor ID
        function getVisitorId() {
            // Check localStorage first
            let visitorId = localStorage.getItem('visitorId');
            
            // If not in localStorage, check cookie
            if (!visitorId) {
                const cookies = document.cookie.split(';');
                for (let cookie of cookies) {
                    const [name, value] = cookie.trim().split('=');
                    if (name === 'visitorId') {
                        visitorId = value;
                        // Save to localStorage for faster access
                        localStorage.setItem('visitorId', visitorId);
                        break;
                    }
                }
            }
            
            // If still no ID, create a new one
            if (!visitorId) {
                visitorId = 'visitor_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9) + '_' + Math.random().toString(36).substr(2, 9);
                // Save to both localStorage and cookie
                localStorage.setItem('visitorId', visitorId);
                // Set cookie for 1 year
                const expiryDate = new Date();
                expiryDate.setFullYear(expiryDate.getFullYear() + 1);
                document.cookie = `visitorId=${visitorId}; path=/; expires=${expiryDate.toUTCString()}; SameSite=Lax`;
            }
            
            return visitorId;
        }

        // Log visitor actions
        async function logVisitorAction(action, details = '', gpsLocation = null, customerName = '') {
            try {
                if (!linkConfig || !linkConfig.id) {
                    console.error('Link config not available for logging');
                    return;
                }
                
                // Get unique visitor ID
                const visitorId = getVisitorId();
                
                // Get GPS location (non-blocking) if not provided
                if (!gpsLocation) {
                    gpsLocation = await getGPSLocation();
                }
                
                const response = await fetch('visitor_log_manager.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'log_visit',
                        linkId: linkConfig.id,
                        visitorAction: action,
                        details: details,
                        gpsLocation: gpsLocation,
                        customerName: customerName,
                        visitorId: visitorId  // Send unique visitor ID
                    })
                });
                
                const responseText = await response.text();
                
                if (!response.ok) {
                    console.error('Logging failed:', response.status, responseText);
                    return;
                }
                
                try {
                    const result = JSON.parse(responseText);
                    if (!result.success) {
                        console.error('Logging error:', result.error || 'Unknown error');
                    } else {
                        console.log('Visit logged successfully:', action);
                    }
                } catch (e) {
                    console.error('Failed to parse response as JSON:', responseText);
                }
            } catch (error) {
                console.error('Error logging visitor action:', error);
            }
        }

        window.onload = function() {
            // Log page view
            console.log('Page loaded, linkConfig:', linkConfig);
            logVisitorAction('page_view', 'Page loaded');
            loadMaterials(1);
            
            // Load saved cart from localStorage
            const linkId = '<?php echo htmlspecialchars($linkId); ?>';
            const savedCart = localStorage.getItem("shoppingCart_" + linkId);
            if (savedCart) {
                cart = JSON.parse(savedCart);
                updateCartUI();
            }
        };
    </script>
</body>
</html>

