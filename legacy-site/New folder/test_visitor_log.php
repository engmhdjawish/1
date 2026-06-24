<?php
/**
 * Simple test script to verify visitor logging works
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>اختبار سجل الزوار</title>
</head>
<body>
    <h1>اختبار سجل الزوار</h1>
    <button onclick="testLog()">اختبار التسجيل</button>
    <div id="result"></div>
    
    <script>
        async function testLog() {
            const resultDiv = document.getElementById('result');
            resultDiv.innerHTML = 'جاري الاختبار...';
            
            try {
                const response = await fetch('visitor_log_manager.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'log_visit',
                        linkId: 'test_link_123',
                        visitorAction: 'test_action',
                        details: 'This is a test log entry'
                    })
                });
                
                const text = await response.text();
                resultDiv.innerHTML = `
                    <h3>النتيجة:</h3>
                    <p><strong>Status:</strong> ${response.status}</p>
                    <p><strong>Response:</strong> ${text}</p>
                    <pre>${text}</pre>
                `;
                
                if (response.ok) {
                    const data = JSON.parse(text);
                    if (data.success) {
                        resultDiv.innerHTML += '<p style="color: green;">✓ تم التسجيل بنجاح!</p>';
                    } else {
                        resultDiv.innerHTML += '<p style="color: red;">✗ فشل التسجيل: ' + (data.error || 'خطأ غير معروف') + '</p>';
                    }
                } else {
                    resultDiv.innerHTML += '<p style="color: red;">✗ خطأ HTTP: ' + response.status + '</p>';
                }
            } catch (error) {
                resultDiv.innerHTML = '<p style="color: red;">✗ خطأ: ' + error.message + '</p>';
            }
        }
    </script>
</body>
</html>

