<?php
// place_order.php
// This version fixes the payment status bug and adds logic to save card details securely.

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$user_id = $_SESSION['user_id'];

// --- Get all form data ---
$address = trim($_POST['address'] ?? '');
$city = trim($_POST['city'] ?? '');
$zip_code = trim($_POST['zip_code'] ?? '');
$phone_number = trim($_POST['phone_number'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? '');
$save_address = isset($_POST['save_address']);
$save_card = isset($_POST['save_card']);
$card_choice = $_POST['card_choice'] ?? 'new';

if (empty($address) || empty($city) || empty($zip_code) || empty($phone_number) || empty($payment_method)) {
    header("Location: checkout.php?error=All shipping fields are required.");
    exit;
}
$full_address = "$address, $city, $zip_code";

// --- THE BUG FIX: Explicitly set payment status based on payment method ---
$payment_status = ($payment_method === 'Card') ? 'Paid' : 'Unpaid';

// --- Validate card details if required ---
if ($payment_method === 'Card' && $card_choice === 'new') {
    $card_number = trim($_POST['card_number'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $cvc = trim($_POST['cvc'] ?? '');
    if (empty($card_number) || empty($expiry_date) || empty($cvc)) {
        header("Location: checkout.php?error=Please fill in all new card details.");
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // --- Handle saving address ---
    if ($save_address) {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ?");
        $count_stmt->execute([$user_id]);
        if ($count_stmt->fetchColumn() < 2) {
            $save_sql = "INSERT INTO user_addresses (user_id, address_line_1, city, zip_code) VALUES (?, ?, ?, ?)";
            $pdo->prepare($save_sql)->execute([$user_id, $address, $city, $zip_code]);
        }
    }

    // --- Handle saving new card (without CVC) ---
    if ($payment_method === 'Card' && $card_choice === 'new' && $save_card) {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM user_payment_methods WHERE user_id = ?");
        $count_stmt->execute([$user_id]);
        if ($count_stmt->fetchColumn() < 2) {
            $masked_number = '**** **** **** ' . substr(preg_replace('/[^0-9]/', '', $card_number), -4);
            $save_sql = "INSERT INTO user_payment_methods (user_id, masked_number, expiry_date) VALUES (?, ?, ?)";
            $pdo->prepare($save_sql)->execute([$user_id, $masked_number, $expiry_date]);
        }
    }

    // --- Process the order ---
    $cart_sql = "SELECT p.id, p.price, p.stock_quantity, ci.quantity FROM cart_items ci JOIN products p ON ci.product_id = p.id WHERE ci.user_id = ? FOR UPDATE";
    $stmt = $pdo->prepare($cart_sql);
    $stmt->execute([$user_id]);
    $items = $stmt->fetchAll();
    if (empty($items)) throw new Exception("Your cart is empty.");

    $total_amount = 0;
    foreach ($items as $item) {
        if ($item['quantity'] > $item['stock_quantity']) throw new Exception("Not enough stock for one of your items.");
        $total_amount += $item['price'] * $item['quantity'];
    }

    $order_sql = "INSERT INTO orders (user_id, total_amount, payment_method, payment_status, shipping_address, phone_number, order_status) VALUES (?, ?, ?, ?, ?, ?, 'Shipped')";
    $pdo->prepare($order_sql)->execute([$user_id, $total_amount, $payment_method, $payment_status, $full_address, $phone_number]);
    $order_id = $pdo->lastInsertId();

    $item_sql = "INSERT INTO order_items (order_id, product_id, quantity, price_per_item) VALUES (?, ?, ?, ?)";
    $update_stock_sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
    foreach ($items as $item) {
        $pdo->prepare($item_sql)->execute([$order_id, $item['id'], $item['quantity'], $item['price']]);
        $pdo->prepare($update_stock_sql)->execute([$item['quantity'], $item['id']]);
    }
    $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?")->execute([$user_id]);

    $pdo->commit();
    header("Location: order_success.php?order_id=" . $order_id);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header("Location: checkout.php?error=" . urlencode($e->getMessage()));
    exit;
}
?>