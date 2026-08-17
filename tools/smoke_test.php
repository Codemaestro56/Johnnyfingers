<?php
// Simple smoke test for Johnnyfingers site.
// Usage: php tools/smoke_test.php [base_url]
// Example: php tools/smoke_test.php http://localhost/johnyfinger

$base = $argv[1] ?? 'http://localhost/johnyfinger';
$cookie = sys_get_temp_dir() . '/jf_smoke_cookie.txt';
$email = 'smoke+' . time() . '@example.com';
$pass = 'TestPass123!';

function curl_request($url, $method = 'GET', $data = null, $headers = [], $cookie = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    }
    if ($method === 'POST') {
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else if (is_string($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_POST, true);
    }
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['body' => $out, 'err' => $err, 'info' => $info];
}

echo "Base URL: $base\n";
echo "Using cookie file: $cookie\n";

// 1) Register
$regUrl = rtrim($base, '/') . '/register.php';
$regData = [
    'full_name' => 'Smoke Tester',
    'email' => $email,
    'phone' => '+10000000000',
    'password' => $pass
];
$res = curl_request($regUrl, 'POST', $regData, [], $cookie);
if ($res['err']) { echo "Register cURL error: " . $res['err'] . "\n"; exit(1); }
echo "Register HTTP code: " . ($res['info']['http_code'] ?? 'N/A') . "\n";

// 2) Login
$loginUrl = rtrim($base, '/') . '/login.php';
$loginData = ['email' => $email, 'password' => $pass];
$res = curl_request($loginUrl, 'POST', $loginData, [], $cookie);
if ($res['err']) { echo "Login cURL error: " . $res['err'] . "\n"; exit(1); }
echo "Login HTTP code: " . ($res['info']['http_code'] ?? 'N/A') . "\n";

// Quick check: ensure dashboard is reachable
$dashUrl = rtrim($base, '/') . '/dashboard.php';
$res = curl_request($dashUrl, 'GET', null, [], $cookie);
if (strpos($res['body'], 'My Active Enrollments') !== false) {
    echo "Dashboard reached OK\n";
} else {
    echo "Dashboard content check failed (HTTP " . ($res['info']['http_code'] ?? 'N/A') . ")\n";
}

// 3) Enroll via checkout fallback
$checkoutUrl = rtrim($base, '/') . '/checkout.php';
$checkoutData = ['process_payment' => '1', 'course_code' => 'WM-01'];
$res = curl_request($checkoutUrl, 'POST', $checkoutData, [], $cookie);
echo "Checkout POST HTTP code: " . ($res['info']['http_code'] ?? 'N/A') . "\n";

// Confirm enrollment appears on dashboard
$res = curl_request($dashUrl, 'GET', null, [], $cookie);
if (strpos($res['body'], 'Washing Machine Repair') !== false || strpos($res['body'], 'WM-01') !== false) {
    echo "Enrollment appears on dashboard\n";
} else {
    echo "Enrollment not found on dashboard — check enrollments table manually\n";
}

// 4) Access player and mark lesson complete
$playerUrl = rtrim($base, '/') . '/player.php?course=WM-01';
$res = curl_request($playerUrl, 'GET', null, [], $cookie);
if ($res['err']) { echo "Player cURL error: " . $res['err'] . "\n"; exit(1); }
if (strpos($res['body'], 'Mark Lesson Complete') === false) {
    echo "Player page missing expected button.\n";
} else {
    echo "Player page OK.\n";
    // Post to mark_lesson_complete.php (use course db_id 'washing-machine')
    $markUrl = rtrim($base, '/') . '/mark_lesson_complete.php';
    $json = json_encode(['course_id' => 'washing-machine', 'lesson_id' => 1]);
    $res2 = curl_request($markUrl, 'POST', $json, ['Content-Type: application/json'], $cookie);
    echo "Mark complete HTTP code: " . ($res2['info']['http_code'] ?? 'N/A') . "\n";
    if ($res2['err']) { echo "Mark complete error: " . $res2['err'] . "\n"; }
    echo "Mark response body: \n" . substr($res2['body'], 0, 1000) . "\n";
}

echo "Smoke test completed. Please inspect DB for `enrollments` and `lesson_progress` rows for user: $email\n";

?>