<?php
// admin_api.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *"); // Adjust for production
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

// Login is the only action accessible without being an admin
if ($action !== 'login') {
    requireAdmin();
}

try {
    switch ($action) {
        /* -------- Admin Login -------- */
        case "login":
            $data = json_decode(file_get_contents('php://input'), true);
            $username = trim($data['username'] ?? '');
            $password = $data['password'] ?? '';
            if (!$username || !$password) jsonResponse(false, null, "اطلاعات ناقص", 400);

            $stmt = $pdo->prepare("SELECT * FROM users WHERE (phone=? OR email=?) AND is_admin = 1 LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                jsonResponse(false, null, "اطلاعات ورود اشتباه یا دسترسی غیرمجاز", 401);
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_admin'] = true;
            jsonResponse(true, ['name' => $user['name']], "ورود موفق");
            break;

        /* -------- Dashboard Stats -------- */
        case "dashboard_stats":
            $stats = [];
            $stats['users_count'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stats['products_count'] = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
            $stats['orders_count'] = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $stats['total_revenue'] = $pdo->query("SELECT SUM(total_amount) FROM orders WHERE status = 'تحویل شده'")->fetchColumn() ?: 0;
            jsonResponse(true, $stats);
            break;

        /* -------- Users Management -------- */
        case "get_users":
            $stmt = $pdo->query("SELECT id, name, email, phone, created_at, is_admin FROM users ORDER BY created_at DESC");
            jsonResponse(true, $stmt->fetchAll());
            break;

        /* -------- Products Management -------- */
        case "get_products":
            $stmt = $pdo->query("SELECT * FROM products ORDER BY id DESC");
            jsonResponse(true, $stmt->fetchAll());
            break;

        case "add_product":
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO products (name, description, price, stock, image) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$data['name'], $data['description'], $data['price'], $data['stock'], $data['image']]);
            jsonResponse(true, ['id' => $pdo->lastInsertId()], "محصول اضافه شد");
            break;

        case "update_product":
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, image=? WHERE id=?");
            $stmt->execute([$data['name'], $data['description'], $data['price'], $data['stock'], $data['image'], $data['id']]);
            jsonResponse(true, null, "محصول بروزرسانی شد");
            break;
            
        case "delete_product":
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$data['id']]);
            jsonResponse(true, null, "محصول با موفقیت حذف شد.");
            break;


        /* -------- Orders Management -------- */
        case "get_orders":
            $stmt = $pdo->query("
                SELECT o.id, o.total_amount, o.status, o.created_at, u.name as user_name
                FROM orders o
                JOIN users u ON o.user_id = u.id
                ORDER BY o.created_at DESC
            ");
            jsonResponse(true, $stmt->fetchAll());
            break;
            
        case "get_order_details":
            $orderId = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            $stmt = $pdo->prepare("
                SELECT oi.*, p.name as product_name 
                FROM order_items oi JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ?");
            $stmt->execute([$orderId]);
            $order['items'] = $stmt->fetchAll();
            jsonResponse(true, $order);
            break;

        case "update_order_status":
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$data['status'], $data['id']]);
            jsonResponse(true, null, "وضعیت سفارش به‌روز شد");
            break;
            
        default:
            jsonResponse(false, null, "درخواست نامعتبر", 400);
    }
} catch (Exception $ex) {
    error_log("Admin API Error: " . $ex->getMessage());
    jsonResponse(false, null, "خطای سرور", 500);
}
