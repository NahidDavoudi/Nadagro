<?php
// api.php - Backend نهایی فروشگاه داودی
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *"); // In production, restrict this to your domain
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        
        /* -------- Get CSRF Token -------- */
        case "getCsrfToken":
            requireAuth(); // Ensure user is logged in to get a token
            jsonResponse(true, ['token' => getCsrfToken()], "CSRF Token");
            break;

        /* -------- Register -------- */
        case "register":
            // ... (no CSRF needed as it's a public action)
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['name'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $password = $data['password'] ?? '';
            $email = trim($data['email'] ?? '');
            $referral = trim($data['referral'] ?? '');

            if (!$name || !$phone || !$password) jsonResponse(false,null,"اطلاعات ناقص",400);

            $stmt=$pdo->prepare("SELECT id FROM users WHERE phone=?");
            $stmt->execute([$phone]);
            if($stmt->fetch()) jsonResponse(false,null,"این شماره تماس قبلا ثبت شده است",409);
            
            $refCode = generateReferralCode($phone);
            $referred_by=null;
            if($referral){
                $stmt=$pdo->prepare("SELECT id FROM users WHERE referral_code=?");
                $stmt->execute([$referral]);
                if($row=$stmt->fetch()) $referred_by=$row['id'];
            }

            $stmt=$pdo->prepare("INSERT INTO users (name,email,phone,password,referral_code,referred_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$name,$email,$phone,password_hash($password,PASSWORD_BCRYPT),$refCode,$referred_by]);

            jsonResponse(true,['referral_code'=>$refCode],"ثبت‌نام موفق");
            break;

        /* -------- Login -------- */
        case "login":
            // ... (no CSRF needed as it's a public action)
            $data = json_decode(file_get_contents('php://input'), true);
            $username=trim($data['username'] ?? '');
            $password=$data['password'] ?? '';
            if(!$username||!$password) jsonResponse(false,null,"اطلاعات ناقص",400);

            $stmt=$pdo->prepare("SELECT * FROM users WHERE phone=? OR email=? LIMIT 1");
            $stmt->execute([$username,$username]);
            $user=$stmt->fetch();
            if(!$user||!password_verify($password,$user['password'])) jsonResponse(false,null,"اطلاعات ورود اشتباه",401);

            $_SESSION['user_id']=$user['id'];
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); // Generate a new token on login

            jsonResponse(true,['id'=>$user['id'],'name'=>$user['name'],'referral_code'=>$user['referral_code']],"ورود موفق");
            break;

        /* -------- Logout -------- */
        case "logout":
            requireAuth();
            $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!validateCsrfToken($csrfToken)) {
                jsonResponse(false, null, 'درخواست نامعتبر', 403);
            }
            session_destroy();
            jsonResponse(true,null,"خروج موفق");
            break;

        /* -------- Profile -------- */
        case "profile":
            requireAuth();
            $stmt=$pdo->prepare("
                SELECT u.id, u.name, u.email, u.phone, u.referral_code, 
                       (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as referral_count
                FROM users u 
                WHERE u.id = ?
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $profileData = $stmt->fetch();
            if (!$profileData) jsonResponse(false, null, "کاربر یافت نشد", 404);
            jsonResponse(true, $profileData, "پروفایل");
            break;

        /* -------- Update Profile -------- */
        case "updateProfile":
            requireAuth();
            $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!validateCsrfToken($csrfToken)) {
                jsonResponse(false, null, 'درخواست نامعتبر', 403);
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');

            if (!$name) jsonResponse(false, null, "نام نمی‌تواند خالی باشد", 400);

            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->execute([$name, $email, $_SESSION['user_id']]);
            jsonResponse(true, null, "پروفایل با موفقیت به‌روزرسانی شد");
            break;


        /* -------- Products -------- */
        case "products":
            $stmt=$pdo->query("SELECT id,name,description,price,image,stock FROM products WHERE stock > 0");
            jsonResponse(true,$stmt->fetchAll(),"محصولات");
            break;

        /* -------- Product Detail -------- */
        case "product":
            $id=(int)($_GET['id']??0);
            $stmt=$pdo->prepare("SELECT id,name,description,price,image,stock FROM products WHERE id=?");
            $stmt->execute([$id]);
            $row=$stmt->fetch();
            if(!$row) jsonResponse(false,null,"یافت نشد",404);
            jsonResponse(true,$row,"محصول");
            break;

        /* -------- Create Order -------- */
        case "order":
            requireAuth();
            $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!validateCsrfToken($csrfToken)) {
                jsonResponse(false, null, 'درخواست نامعتبر', 403);
            }

            $data = json_decode(file_get_contents('php://input'), true);
            $items=$data['items']??[];
            $address=$data['address']??'';
            $payment=$data['payment']??'cod';
            if(!$items || !$address) jsonResponse(false,null,"سبد خرید یا آدرس خالی است",400);

            $pdo->beginTransaction();
            try {
                // ... (rest of the order logic is correct)
                $total=0;
                foreach($items as $it){
                    $pid=(int)$it['id']; $qty=(int)$it['quantity'];
                    $stmt=$pdo->prepare("SELECT price,stock FROM products WHERE id=? FOR UPDATE");
                    $stmt->execute([$pid]);
                    $row=$stmt->fetch();
                    if(!$row||$row['stock']<$qty) throw new Exception("موجودی محصول " . $pid . " کافی نیست.");
                    $total += $row['price']*$qty;
                }
                $stmt=$pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?");
                $stmt->execute([$_SESSION['user_id']]);
                $isFirstBuy=($stmt->fetchColumn()==0);
                $discountApplied = 0;
                if($isFirstBuy){
                    $discountApplied=(int)round($total*0.05);
                    $total-=$discountApplied;
                }
                $stmt=$pdo->prepare("INSERT INTO orders (user_id,total_amount,address,payment_method, status) VALUES (?,?,?,?, 'در حال پردازش')");
                $stmt->execute([$_SESSION['user_id'],$total,$address,$payment]);
                $orderId=$pdo->lastInsertId();
                $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)");
                $stmtStock = $pdo->prepare("UPDATE products SET stock=stock-? WHERE id=?");
                foreach($items as $it){
                    $pid=(int)$it['id']; $qty=(int)$it['quantity'];
                    $stmt=$pdo->prepare("SELECT price FROM products WHERE id=?");
                    $stmt->execute([$pid]);
                    $price=$stmt->fetch()['price'];
                    $stmtItem->execute([$orderId,$pid,$qty,$price]);
                    $stmtStock->execute([$qty,$pid]);
                }
                if($isFirstBuy){
                    $stmt=$pdo->prepare("SELECT referred_by FROM users WHERE id=?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $referrer = $stmt->fetch();
                    if($referrer && $referrer['referred_by']){
                        $bonus=(int)round(($total+$discountApplied)*0.05);
                        $stmt=$pdo->prepare("INSERT INTO discounts (user_id,amount,reason) VALUES (?,?,?)");
                        $stmt->execute([$referrer['referred_by'],"پاداش معرفی کاربر جدید"]);
                    }
                }
                $pdo->commit();
                jsonResponse(true,['order_id'=>$orderId,'discount'=>$discountApplied],"سفارش ثبت شد");
            }catch(Exception $e){
                $pdo->rollBack();
                jsonResponse(false,null,"خطا در ثبت سفارش: ".$e->getMessage(),500);
            }
            break;

        /* -------- Orders (Optimized) -------- */
        case "orders":
            requireAuth();
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $orders = $stmt->fetchAll();

            if (empty($orders)) {
                jsonResponse(true, [], "سفارش‌ها");
                break;
            }

            $orderIds = array_column($orders, 'id');
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

            $stmtItem = $pdo->prepare("
                SELECT oi.order_id, oi.product_id, p.name as product_name, oi.quantity, oi.price 
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id IN ($placeholders)
            ");
            $stmtItem->execute($orderIds);
            $allItems = $stmtItem->fetchAll();
            
            $itemsByOrderId = [];
            foreach ($allItems as $item) {
                $itemsByOrderId[$item['order_id']][] = $item;
            }

            foreach ($orders as &$o) {
                $o['items'] = $itemsByOrderId[$o['id']] ?? [];
            }
            unset($o);

            jsonResponse(true, $orders, "سفارش‌ها");
            break;

        /* -------- Discounts -------- */
        case "discounts":
            requireAuth();
            $stmt=$pdo->prepare("SELECT * FROM discounts WHERE user_id=? ORDER BY created_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            jsonResponse(true,$stmt->fetchAll(),"تخفیف‌ها");
            break;

        default:
            jsonResponse(false,null,"درخواست نامعتبر",400);
    }
}catch(Exception $ex){
    error_log("General API Error: " . $ex->getMessage());
    jsonResponse(false,null,"خطای سرور",500);
}

