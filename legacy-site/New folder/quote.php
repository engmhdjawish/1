<?php
/**
 * Quote/Invoice page - displays order with product images and prices
 */

$orderId = $_GET['order'] ?? '';
$token = $_GET['token'] ?? '';

if (empty($orderId)) {
    die('Order ID is required');
}

// Get order data - use local file access instead of HTTP request
// This avoids URL encoding issues with spaces in folder names
define('DATA_FILE', __DIR__ . '/data.xml');

// Load XML data
function loadOrderXML() {
    if (!file_exists(DATA_FILE)) {
        return null;
    }
    return simplexml_load_file(DATA_FILE);
}

// Get order data directly from XML
$xml = loadOrderXML();
if (!$xml || !isset($xml->orders)) {
    die('فشل في تحميل بيانات الطلب: لا توجد بيانات');
}

$found = false;
$orderData = null;

foreach ($xml->orders->order as $order) {
    if ((string)$order['id'] === $orderId) {
        // Verify token if provided (for security)
        $createdDate = (string)$order->created;
        $expectedToken = md5($orderId . 'quote_secret_' . $createdDate);
        
        if (!empty($token) && $token !== $expectedToken) {
            die('رمز الوصول غير صحيح. يرجى استخدام الرابط الصحيح من لوحة التحكم.');
        }
        
        $items = [];
        foreach ($order->items->item as $item) {
            $items[] = [
                'guid' => (string)$item->guid,
                'code' => (string)$item->code,
                'name' => (string)$item->name,
                'quantity' => (int)$item->quantity,
                'pcsPerBox' => (int)$item->pcsPerBox,
                'salePrice_SP' => (float)$item->salePrice_SP,
                'salePrice_Usd' => (float)($item->salePrice_Usd ?? 0),
                'imageUrl' => (string)($item->imageUrl ?? '')
            ];
        }
        
        $orderData = [
            'id' => (string)$order['id'],
            'customerName' => (string)$order->customerName,
            'customerPhone' => (string)($order->customerPhone ?? ''),
            'created' => (string)$order->created,
            'status' => (string)$order->status,
            'totalSP' => (float)$order->totalSP,
            'totalUSD' => (float)$order->totalUSD,
            'items' => $items
        ];
        $found = true;
        break;
    }
}

if (!$found) {
    die('الطلب غير موجود');
}

$data = ['success' => true, 'order' => $orderData];

if (!$data || !$data['success']) {
    die('Order not found or invalid');
}

$order = $data['order'];

// Load company information
$companyInfo = require __DIR__ . '/company_info.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>عرض الأسعار - طلب <?php echo htmlspecialchars($order['customerName']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f5f5;
            font-family: Arial, sans-serif;
        }
        .quote-container {
            max-width: 900px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .quote-header {
            border-bottom: 2px solid #007bff;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .product-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        .product-image {
            width: 120px;
            height: 120px;
            object-fit: contain;
            border-radius: 5px;
            margin-left: 15px;
            border: 1px solid #dee2e6;
        }
        .product-info {
            flex-grow: 1;
        }
        .product-price {
            text-align: left;
            min-width: 200px;
        }
        @media (max-width: 768px) {
            .product-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .product-image {
                width: 100px;
                height: 100px;
                margin-left: 0;
                margin-bottom: 15px;
                align-self: center;
            }
            .product-info {
                width: 100%;
                margin-bottom: 15px;
            }
            .product-price {
                width: 100%;
                text-align: right;
                min-width: auto;
                padding-top: 15px;
                border-top: 1px solid #dee2e6;
            }
        }
        .total-section {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        @media print {
            body { background: white; }
            .quote-container { box-shadow: none; }
            .no-print { display: none; }
        }
        /* Image Popup Styles */
        #imagePopupOverlay {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 9998;
            cursor: pointer;
        }
        #imagePopup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            background: white;
            padding: 20px;
            border-radius: 8px;
            max-width: 90vw;
            max-height: 90vh;
            text-align: center;
        }
        #imagePopup img {
            max-width: 85vw;
            max-height: 75vh;
            object-fit: contain;
            border-radius: 8px;
        }
        #imagePopupClose {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 36px;
            color: white;
            background-color: rgba(0, 0, 0, 0.5);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10000;
            line-height: 1;
        }
        #imagePopupClose:hover {
            background-color: rgba(0, 0, 0, 0.8);
            transform: scale(1.1);
        }
    </style>
</head>
<body>
    <!-- Image Popup -->
    <div id="imagePopupOverlay" onclick="hidePopupImage()"></div>
    <div id="imagePopup">
        <span id="imagePopupClose" onclick="hidePopupImage()">×</span>
        <img src="" alt="صورة مكبرة">
    </div>
    <div class="quote-container">
        <div class="quote-header">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center gap-3">
                    <img alt="<?php echo htmlspecialchars($companyInfo['name']); ?> Logo" src="<?php echo htmlspecialchars($companyInfo['logo']); ?>" style="height: 50px; width: auto;">
                    <div>
                        <h2 class="mb-0"><?php echo htmlspecialchars($companyInfo['name']); ?></h2>
                        <p class="mb-0 text-muted small">عرض أسعار</p>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge bg-<?php echo $order['status'] === 'quoted' ? 'success' : ($order['status'] === 'confirmed' ? 'info' : 'warning'); ?> fs-6">
                        <?php 
                        $statusLabels = [
                            'pending' => 'قيد المراجعة',
                            'quoted' => 'تم إرسال العرض',
                            'confirmed' => 'مؤكد',
                            'completed' => 'مكتمل',
                            'cancelled' => 'ملغي'
                        ];
                        echo $statusLabels[$order['status']] ?? $order['status'];
                        ?>
                    </span>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>العميل:</strong> <?php echo htmlspecialchars($order['customerName']); ?></p>
                    <?php if (!empty($order['customerPhone'])): ?>
                    <p class="mb-1"><strong>رقم الهاتف:</strong> <a href="tel:<?php echo htmlspecialchars($order['customerPhone']); ?>" class="text-primary"><?php echo htmlspecialchars($order['customerPhone']); ?></a></p>
                    <?php endif; ?>
                    <p class="mb-1"><strong>تاريخ الطلب:</strong> <?php echo htmlspecialchars($order['created']); ?></p>
                    <p class="mb-0"><strong>رقم الطلب:</strong> <?php echo htmlspecialchars($order['id']); ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <?php if (!empty($companyInfo['location'])): ?>
                    <p class="mb-1"><strong>العنوان:</strong> <?php echo htmlspecialchars($companyInfo['location']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['phone'])): ?>
                    <p class="mb-1"><strong>الهاتف:</strong> <?php echo htmlspecialchars($companyInfo['phone']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($companyInfo['email'])): ?>
                    <p class="mb-0"><strong>البريد الإلكتروني:</strong> <?php echo htmlspecialchars($companyInfo['email']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <h4 class="mb-3">المنتجات:</h4>
        <div id="productsList">
            <?php 
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            foreach ($order['items'] as $item): 
                $imageUrl = !empty($item['imageUrl']) 
                    ? $baseUrl . $item['imageUrl'] 
                    : $baseUrl . '/No_image_available.svg.png';
                $thumbnailUrl = !empty($item['imageUrl']) 
                    ? $baseUrl . str_replace('/images/', '/images/thumbnails/', $item['imageUrl'])
                    : $imageUrl;
                $itemTotalSP = $item['salePrice_SP'] * $item['pcsPerBox'] * $item['quantity'];
                $itemTotalUSD = $item['salePrice_Usd'] * $item['pcsPerBox'] * $item['quantity'];
            ?>
                <div class="product-item">
                    <img src="<?php echo htmlspecialchars($thumbnailUrl); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="product-image" style="cursor: pointer;" onclick="showPopupImage('<?php echo htmlspecialchars($imageUrl); ?>')">
                    <div class="product-info">
                        <h5><?php echo htmlspecialchars($item['code'] . ' - ' . $item['name']); ?></h5>
                        <p class="mb-1"><strong>الكمية:</strong> <?php echo $item['quantity']; ?> علبة</p>
                        <p class="mb-1"><strong>عدد القطع في العلبة:</strong> <?php echo $item['pcsPerBox']; ?></p>
                        <p class="mb-0"><strong>السعر للعلبة:</strong> <?php echo number_format($item['salePrice_SP'], 0); ?> ل.س / $<?php echo number_format($item['salePrice_Usd'], 2); ?></p>
                    </div>
                    <div class="product-price">
                        <h5 class="text-primary">الإجمالي:</h5>
                        <p class="mb-0"><strong><?php echo number_format($itemTotalSP, 0); ?> ل.س</strong></p>
                        <p class="mb-0"><strong>$<?php echo number_format($itemTotalUSD, 2); ?></strong></p>
                    </div>
                </div>
            <?php 
            endforeach; 
            
            // Calculate summary
            $totalItems = count($order['items']);
            $totalBoxes = 0;
            foreach ($order['items'] as $item) {
                $totalBoxes += $item['quantity'];
            }
            ?>
        </div>

        <div class="summary-section mb-3 p-3 bg-light rounded">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>عدد المنتجات:</strong> <?php echo $totalItems; ?> منتج</p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>عدد العلب:</strong> <?php echo $totalBoxes; ?> علبة</p>
                </div>
            </div>
        </div>

        <div class="total-section">
            <div class="row">
                <div class="col-md-6 offset-md-6">
                    <div class="d-flex justify-content-between mb-2">
                        <strong>الإجمالي الكلي:</strong>
                        <strong><?php echo number_format($order['totalSP'], 0); ?> ل.س</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <strong>الإجمالي الكلي:</strong>
                        <strong>$<?php echo number_format($order['totalUSD'], 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <style>
            @media (max-width: 768px) {
                .total-section .row > div {
                    margin-right: 0 !important;
                }
            }
        </style>

        <div class="mt-4 text-center no-print">
            <button class="btn btn-primary" onclick="window.print()">طباعة</button>
            <button class="btn btn-secondary ms-2" onclick="window.close()">إغلاق</button>
        </div>

        <!-- Footer -->
        <div class="mt-5 pt-4 border-top text-center">
            <div class="d-flex align-items-center justify-content-center gap-3 mb-2">
                <img alt="<?php echo htmlspecialchars($companyInfo['name']); ?> Logo" src="<?php echo htmlspecialchars($companyInfo['logo']); ?>" style="height: 40px; width: auto;">
                <h5 class="mb-0"><?php echo htmlspecialchars($companyInfo['name']); ?></h5>
            </div>
            <?php if (!empty($companyInfo['location'])): ?>
            <p class="mb-1 text-muted small"><?php echo htmlspecialchars($companyInfo['location']); ?></p>
            <?php endif; ?>
            <?php if (!empty($companyInfo['phone'])): ?>
            <p class="mb-1 text-muted small"><?php echo htmlspecialchars($companyInfo['phone']); ?></p>
            <?php endif; ?>
            <?php if (!empty($companyInfo['email'])): ?>
            <p class="mb-0 text-muted small"><?php echo htmlspecialchars($companyInfo['email']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Image Popup Functions
        function showPopupImage(imageUrl) {
            const popup = document.getElementById('imagePopup');
            const overlay = document.getElementById('imagePopupOverlay');
            const img = popup.querySelector('img');
            
            img.src = imageUrl;
            popup.style.display = 'block';
            overlay.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        }

        function hidePopupImage() {
            const popup = document.getElementById('imagePopup');
            const overlay = document.getElementById('imagePopupOverlay');
            
            popup.style.display = 'none';
            overlay.style.display = 'none';
            document.body.style.overflow = ''; // Restore scrolling
        }

        // Close popup on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hidePopupImage();
            }
        });
    </script>
</body>
</html>

