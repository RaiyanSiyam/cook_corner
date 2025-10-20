<?php
// admin/delete.php
// This is a universal script to handle the deletion of any item type (user, product, recipe).

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../db.php';

// 1. Get the type of item to delete and its ID from the URL
$type = isset($_GET['type']) ? $_GET['type'] : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($type) || $id === 0) {
    // Redirect if required parameters are missing
    header('Location: index.php?error=invalid_request');
    exit;
}

$table_name = '';
$redirect_page = '';
$image_column = null;

// 2. Configure the deletion based on the item type
switch ($type) {
    case 'user':
        // Safety Check: Prevent an admin from deleting their own account
        if ($id === $_SESSION['user_id']) {
            header('Location: manage_users.php?error=self_delete');
            exit;
        }
        $table_name = 'users';
        $redirect_page = 'manage_users.php';
        break;

    case 'product':
        $table_name = 'products';
        $redirect_page = 'manage_products.php';
        $image_column = 'image_url';
        break;

    case 'recipe':
        $table_name = 'recipes';
        $redirect_page = 'manage_recipes.php';
        $image_column = 'image_url';
        break;

    default:
        // If the type is unknown, redirect with an error
        header('Location: index.php?error=unknown_type');
        exit;
}

try {
    // 3. Get the image path first (if applicable)
    $image_file_path = null;
    if ($image_column) {
        $stmt = $pdo->prepare("SELECT $image_column FROM $table_name WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if ($item && !empty($item[$image_column]) && strpos($item[$image_column], 'http') !== 0) {
            $image_file_path = __DIR__ . '/../' . $item[$image_column];
        }
    }

    // 4. Perform the deletion using the "master key" method
    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
    
    $stmt = $pdo->prepare("DELETE FROM $table_name WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    $pdo->commit();

    // 5. Attempt to delete the physical image file from the server
    // The '@' symbol suppresses warnings, ensuring the redirect happens even if this fails.
    if ($image_file_path && file_exists($image_file_path)) {
        @unlink($image_file_path);
    }

    // 6. Redirect back to the correct management page
    header("Location: $redirect_page?deleted=1");
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Ensure checks are re-enabled even on error
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    error_log("Deletion failed for type '$type', id '$id': " . $e->getMessage());
    header("Location: $redirect_page?error=db_error");
    exit;
}
?>