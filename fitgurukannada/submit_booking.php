<?php
header('Content-Type: application/json');

// ================================================================
//  FITGURUKANNADA — Booking Handler
//  Saves photo to /uploads/, sends all text data to WhatsApp
//  via CallMeBot (free). No database required.
//
//  SETUP (do this ONCE before using):
//  1. Open WhatsApp and add this number as a contact:
//     +34 644 54 96 67  (CallMeBot service number)
//  2. Send exactly this message to that number:
//     I allow callmebot to send me messages
//  3. You'll receive an API key. Paste it below.
// ================================================================

$YOUR_WHATSAPP_NUMBER = '91XXXXXXXXXX';   // Your number with country code, no + or spaces. e.g. 919876543210
$CALLMEBOT_APIKEY     = 'PASTE_YOUR_KEY_HERE';  // API key from CallMeBot

// ---- Collect & sanitise fields ----
$goal_type      = trim($_POST['goal_type']      ?? '');
$goal_other     = trim($_POST['goal_other']     ?? '');
$name           = trim($_POST['name']           ?? '');
$mobile         = trim($_POST['mobile']         ?? '');
$gender         = trim($_POST['gender']         ?? '');
$referral       = trim($_POST['referral']       ?? '');
$height_ft      = intval($_POST['height_ft']    ?? 0);
$height_in      = intval($_POST['height_in']    ?? 0);
$age            = intval($_POST['age']          ?? 0);
$current_weight = floatval($_POST['current_weight'] ?? 0);
$goal_weight    = floatval($_POST['goal_weight']    ?? 0);
$occupation     = trim($_POST['occupation']     ?? '');
$sleep_hours    = trim($_POST['sleep_hours']    ?? '');
$water_intake   = trim($_POST['water_intake']   ?? '');
$meals_per_day  = trim($_POST['meals_per_day']  ?? '');
$medical_other  = trim($_POST['medical_other']  ?? '');
$concerns       = trim($_POST['concerns']       ?? '');
$consent        = $_POST['consent'] ?? '';

// Medical conditions array
$medical_raw   = $_POST['medical_conditions'] ?? [];
$medical_clean = array_filter(array_map('trim', $medical_raw));
if ($medical_other && in_array('Other', $medical_clean)) {
    $medical_clean[] = 'Other: ' . $medical_other;
}
$medical_str = !empty($medical_clean) ? implode(', ', $medical_clean) : 'None reported';

// ---- Validate ----
if (!$goal_type)      { respond(false, 'Goal type is required.'); }
if (!$name)           { respond(false, 'Name is required.'); }
if (!preg_match('/^[6-9]\d{9}$/', $mobile)) { respond(false, 'Invalid mobile number.'); }
if (!$gender)         { respond(false, 'Gender is required.'); }
if (!$referral)       { respond(false, 'Referral source is required.'); }
if ($age < 18 || $age > 80) { respond(false, 'You must be 18 or older to book.'); }
if (!$current_weight) { respond(false, 'Current weight is required.'); }
if (!$goal_weight)    { respond(false, 'Goal weight is required.'); }
if (!$occupation)     { respond(false, 'Occupation is required.'); }
if (!$sleep_hours)    { respond(false, 'Sleep hours is required.'); }
if (!$water_intake)   { respond(false, 'Water intake is required.'); }
if (!$meals_per_day)  { respond(false, 'Meals per day is required.'); }
if ($consent !== 'yes') { respond(false, 'Please accept the photo consent.'); }

$height_cm  = round((($height_ft * 12) + $height_in) * 2.54, 1);
$final_goal = ($goal_type === 'Other' && $goal_other) ? $goal_other : $goal_type;

// ---- Photo upload (saved to server, path mentioned in WhatsApp) ----
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    respond(false, 'Please upload a current photo.');
}

$allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
$file_type     = mime_content_type($_FILES['photo']['tmp_name']);

if (!in_array($file_type, $allowed_types)) {
    respond(false, 'Photo must be JPG, PNG, or WEBP.');
}
if ($_FILES['photo']['size'] > 8 * 1024 * 1024) {
    respond(false, 'Photo must be under 8MB.');
}

$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
// Block direct web access to uploaded client photos (Apache)
if (!file_exists($upload_dir . '.htaccess')) {
    file_put_contents($upload_dir . '.htaccess', "Require all denied\n");
}

$ext      = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'][$file_type] ?? 'jpg';
$safe_name = preg_replace('/[^a-z0-9]/', '_', strtolower($name));
$filename  = $safe_name . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

if (!move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
    respond(false, 'Could not save the photo. Check /uploads/ folder permissions.');
}

// ---- Build WhatsApp message ----
$concerns_display = $concerns ?: 'None';

$msg = "🏋️ *NEW BOOKING — FITGURUKANNADA*
📅 " . date('d M Y, h:i A') . "

👤 *Name:* {$name}
📱 *Mobile:* {$mobile}
⚤ *Gender:* {$gender}
📣 *Found us via:* {$referral}

🎯 *Goal:* {$final_goal}

📏 *Height:* {$height_cm} cm ({$height_ft}'{$height_in}\")
⚖️ *Current Weight:* {$current_weight} kg
🏁 *Goal Weight:* {$goal_weight} kg
🎂 *Age:* {$age} yrs

💼 *Occupation:* {$occupation}
💤 *Sleep per night:* {$sleep_hours}
💧 *Water per day:* {$water_intake}
🍽️ *Meals per day:* {$meals_per_day}

🏥 *Medical / Physical Conditions:*
{$medical_str}

📝 *Client's Concerns:*
{$concerns_display}

📸 *Photo saved as:* uploads/{$filename}
(Open your server's uploads folder to view)";

// ---- Send to WhatsApp via CallMeBot ----
$wa_result = sendWhatsApp($YOUR_WHATSAPP_NUMBER, $CALLMEBOT_APIKEY, $msg);

if ($wa_result === false) {
    // WhatsApp failed but photo is saved — respond with partial success
    respond(true, "Thanks {$name}! Your details are saved. (Note to admin: WhatsApp delivery failed — check API key and number in submit_booking.php)");
}

respond(true, "Thanks {$name}! Your details have been received. Your coach will call you on {$mobile} shortly. 💪");

// ---- Helper functions ----
function sendWhatsApp($phone, $apikey, $message) {
    if (!function_exists('curl_init')) return false;
    $encoded = urlencode($message);
    $url = "https://api.callmebot.com/whatsapp.php?phone={$phone}&text={$encoded}&apikey={$apikey}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    return ($err ? false : $response);
}

function respond($success, $message) {
    http_response_code($success ? 200 : 400);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}