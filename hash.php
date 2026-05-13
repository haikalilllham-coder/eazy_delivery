<?php
declare(strict_types=1);
session_start();

/*
EAZY DELIVERY — single-file PHP app
- Admin / Driver login
- Orders with member priority
- Attendance with photo proof
- Custom date range report (printable)
- Printable invoice/report
- MySQL + PDO
- Multi-user safe with transactions & row locking
*/

date_default_timezone_set('Asia/Makassar');

/* ====== EDIT DATABASE HERE ====== */
const DB_HOST = 'localhost';
const DB_NAME = 'eazy_delivery';
const DB_USER = 'root';
const DB_PASS = '';

const APP_NAME = 'EAZY DELIVERY';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h2>Database connection failed</h2><p>Check DB credentials in index.php</p><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

// ========== MIGRATION ==========
try {
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE members ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (PDOException $e) {}

// ========== FUNCTIONS ==========
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function now(): string { return date('Y-m-d H:i:s'); }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void {
    if (!current_user()) {
        header('Location: ?page=login');
        exit;
    }
}
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }
function is_driver(): bool { return (current_user()['role'] ?? '') === 'driver'; }

function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function redirect(string $url): never {
    header("Location: $url");
    exit;
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function ensure_upload_dir(): string {
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function upload_photo(string $field): ?string {
    if (empty($_FILES[$field]['name'])) return null;
    if (!is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) return null;
    $name = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = ensure_upload_dir();
    $dest = $dir . '/' . $name;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        return 'uploads/' . $name;
    }
    return null;
}

function week_range(): array {
    $start = date('Y-m-d', strtotime('monday this week'));
    $end = date('Y-m-d', strtotime('sunday this week'));
    return [$start, $end];
}

function month_range(): array {
    return [date('Y-m-01'), date('Y-m-t')];
}

function get_setting(PDO $pdo, string $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
}

function driver_rejection_count(PDO $pdo, int $driverId): int {
    [$ws, $we] = week_range();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_rejections WHERE driver_id = ? AND rejection_date BETWEEN ? AND ?");
    $stmt->execute([$driverId, $ws, $we . ' 23:59:59']);
    return (int)$stmt->fetchColumn();
}

function driver_week_status(PDO $pdo, int $driverId): array {
    $count = driver_rejection_count($pdo, $driverId);
    if ($count >= 5) return ['label' => 'NON-AKTIF MINGGU INI', 'badge' => 'danger', 'blocked' => true, 'count' => $count];
    if ($count >= 2) return ['label' => 'WASPADA', 'badge' => 'warning', 'blocked' => false, 'count' => $count];
    return ['label' => 'AMAN', 'badge' => 'success', 'blocked' => false, 'count' => $count];
}

function driver_has_active_order(PDO $pdo, int $driverId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE driver_id = ? AND status IN ('accepted','picked_up')");
    $stmt->execute([$driverId]);
    return (int)$stmt->fetchColumn() > 0;
}

function driver_today_completed(PDO $pdo, int $driverId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE driver_id = ? AND status = 'completed' AND DATE(completed_at) = CURDATE()");
    $stmt->execute([$driverId]);
    return (int)$stmt->fetchColumn();
}

function driver_today_bonus(PDO $pdo, int $driverId): int {
    $stmt = $pdo->prepare("
        SELECT id, completed_at
        FROM orders
        WHERE driver_id = ? AND status = 'completed' AND DATE(completed_at) = CURDATE()
        ORDER BY completed_at ASC, id ASC
    ");
    $stmt->execute([$driverId]);
    $rows = $stmt->fetchAll();
    $bonus = 0;
    foreach ($rows as $i => $r) {
        if ($i + 1 > 20) $bonus += 1000;
    }
    return $bonus;
}

function order_total(array $order): int {
    return (int)$order['total_fee'];
}

function get_point_discount(PDO $pdo, int $memberPoints): int {
    $rulesStr = get_setting($pdo, 'point_discount_rules', '');
    if (empty($rulesStr)) return 0;
    $rules = explode(',', $rulesStr);
    $discount = 0;
    foreach ($rules as $rule) {
        $parts = explode(':', trim($rule));
        if (count($parts) === 2) {
            $pointThreshold = (int)$parts[0];
            $percent = (int)$parts[1];
            if ($memberPoints >= $pointThreshold && $percent > $discount) {
                $discount = $percent;
            }
        }
    }
    return $discount;
}

function compute_order_total(array $data, ?array $member, PDO $pdo): array {
    $base = 15000;
    $extraStops = max(0, (int)($data['extra_stops'] ?? 0));
    $extra = $extraStops * 5000;
    $subtotal = $base + $extra;

    $discountPercent = 0;
    if ($member) {
        $memberPoints = (int)($member['points'] ?? 0);
        $memberDiscount = (int)($member['discount_percent'] ?? 0);
        $pointDiscount = get_point_discount($pdo, $memberPoints);
        // Gunakan diskon tertinggi antara diskon bawaan member dan diskon dari poin
        $discountPercent = max($memberDiscount, $pointDiscount);
    } elseif (!empty($data['member_discount_percent'])) {
        $discountPercent = max(0, min(100, (int)$data['member_discount_percent']));
    }

    $discount = (int)round($subtotal * ($discountPercent / 100));
    $total = max(0, $subtotal - $discount);
    return [
        'base_fee' => $base,
        'extra_fee' => $extra,
        'discount' => $discount,
        'total' => $total,
    ];
}

function order_profit_estimate(array $order): int {
    return (int)$order['total_fee'];
}

function admin_dashboard_stats(PDO $pdo): array {
    $monthStart = date('Y-m-01');

    $stats = [];
    $stats['today_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['today_revenue'] = (int)$pdo->query("SELECT COALESCE(SUM(total_fee),0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $stats['month_revenue'] = (int)$pdo->query("SELECT COALESCE(SUM(total_fee),0) FROM orders WHERE created_at >= '$monthStart 00:00:00'")->fetchColumn();
    $stats['month_bonus'] = (int)$pdo->query("
        SELECT COALESCE(SUM(extra_bonus),0) FROM (
            SELECT o.id,
                   CASE WHEN x.seq > 20 THEN 1000 ELSE 0 END AS extra_bonus
            FROM orders o
            JOIN (
                SELECT o2.id,
                       o2.driver_id,
                       o2.completed_at,
                       ROW_NUMBER() OVER (PARTITION BY o2.driver_id, DATE(o2.completed_at) ORDER BY o2.completed_at ASC, o2.id ASC) AS seq
                FROM orders o2
                WHERE o2.status = 'completed' AND o2.completed_at >= '$monthStart 00:00:00'
            ) x ON x.id = o.id
        ) t
    ")->fetchColumn();
    $stats['month_profit'] = $stats['month_revenue'] - $stats['month_bonus'];
    $stats['member_count'] = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
    $stats['driver_count'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'driver'")->fetchColumn();
    return $stats;
}

function order_status_badge(string $status): string {
    return match($status) {
        'pending' => 'secondary',
        'accepted' => 'primary',
        'picked_up' => 'info',
        'completed' => 'success',
        'rejected' => 'danger',
        default => 'dark'
    };
}

/* ====== ACTIONS ====== */
$page = $_GET['page'] ?? 'dashboard';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($page === 'logout') {
    session_destroy();
    redirect('?page=login');
}

if (!table_exists($pdo, 'users')) {
    $page = 'install';
}

// ========== LOGIN ==========
if ($action === 'login') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['role'] === 'driver') {
            flash_set('danger', 'Akses driver dinonaktifkan. Gunakan akun admin.');
            redirect('?page=login');
        }
        $_SESSION['user'] = $user;
        flash_set('success', 'Login berhasil.');
        redirect('?page=dashboard');
    } else {
        flash_set('danger', 'Username atau password salah.');
        redirect('?page=login');
    }
}

// ========== SAVE MEMBER (CRUD) ==========
if ($action === 'save_member' && is_admin()) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)$_POST['name']);
    $phone = trim((string)$_POST['phone']);
    $balance = (int)($_POST['balance'] ?? 0);
    $points = (int)($_POST['points'] ?? 0);
    $discount = (int)($_POST['discount_percent'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE members SET name=?, phone=?, balance=?, points=?, discount_percent=?, notes=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$name, $phone, $balance, $points, $discount, $notes, $id]);
        flash_set('success', 'Member berhasil diperbarui.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO members (name, phone, balance, points, discount_percent, notes, created_at, updated_at) VALUES (?,?,?,?,?,?, NOW(), NOW())");
        $stmt->execute([$name, $phone, $balance, $points, $discount, $notes]);
        flash_set('success', 'Member baru ditambahkan.');
    }
    redirect('?page=members');
}

// ========== SAVE DRIVER ==========
if ($action === 'save_driver' && is_admin()) {
    $name = trim((string)$_POST['name']);
    $phone = trim((string)$_POST['phone']);
    $username = trim((string)$_POST['username']);
    $password = trim((string)($_POST['password'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($password === '') {
        $password = 'driver123';
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, phone, role, is_active, created_at) VALUES (?, ?, ?, ?, 'driver', ?, NOW())");
    $stmt->execute([$username, $hash, $name, $phone, $isActive]);
    flash_set('success', 'Driver berhasil ditambahkan.');
    redirect('?page=drivers');
}

// ========== ADMIN REJECT ORDER (with transaction) ==========
if ($action === 'admin_reject_order' && is_admin()) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $newDriverId = (int)($_POST['new_driver_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($driverId <= 0) {
        flash_set('danger', 'Order belum punya driver.');
        redirect('?page=orders');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO order_rejections (order_id, driver_id, reason, rejection_date) VALUES (?, ?, ?, NOW())")
           ->execute([$orderId, $driverId, $reason]);

        if ($newDriverId > 0) {
            $pdo->prepare("UPDATE orders SET driver_id=?, status='pending' WHERE id=?")
                ->execute([$newDriverId, $orderId]);
            $note = "Dialihkan ke driver lain";
        } else {
            $pdo->prepare("UPDATE orders SET status='rejected', rejected_at=NOW() WHERE id=?")
                ->execute([$orderId]);
            $note = "Ditolak tanpa pengalihan";
        }

        $pdo->prepare("INSERT INTO order_logs (order_id, status, note, created_at) VALUES (?, 'rejected', ?, NOW())")
            ->execute([$orderId, $note . " | Alasan: " . $reason]);

        $pdo->commit();
        flash_set('warning', 'Penolakan berhasil.');
    } catch (\Throwable $e) {
        $pdo->rollBack();
        flash_set('danger', 'Gagal menolak order: ' . $e->getMessage());
    }
    redirect('?page=orders');
}

// ========== COMPLETE ORDER BY ADMIN (with transaction) ==========
if ($action === 'complete_order_admin' && is_admin()) {
    $orderId = (int)($_POST['order_id'] ?? 0);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE orders SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$orderId]);

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order) {
            if (!empty($order['member_id'])) {
                $pdo->prepare("UPDATE members SET points = points + 1 WHERE id = ?")
                    ->execute([(int)$order['member_id']]);
            }
            $pdo->prepare("INSERT INTO order_logs (order_id, status, note, created_at) VALUES (?, 'completed', ?, NOW())")
                ->execute([$orderId, 'Order diselesaikan oleh admin.']);
        }

        $pdo->commit();
        flash_set('success', 'Pesanan #' . $orderId . ' diselesaikan.');
    } catch (\Throwable $e) {
        $pdo->rollBack();
        flash_set('danger', 'Gagal menyelesaikan order: ' . $e->getMessage());
    }
    redirect('?page=orders');
}

// ========== SAVE ORDER (with transaction + row locking) ==========
if ($action === 'save_order' && is_admin()) {
    $createdBy = current_user()['id'];
    $memberId = (int)($_POST['member_id'] ?? 0);
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $customerName = trim((string)$_POST['customer_name']);
    $customerPhone = trim((string)$_POST['customer_phone']);
    $serviceTypeSelected = trim((string)$_POST['service_type']);
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'cash'));
    $pickupAddress = trim((string)$_POST['pickup_address']);
    $dropAddress = trim((string)$_POST['drop_address']);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $extraStops = max(0, (int)($_POST['extra_stops'] ?? 0));
    $isPriority = $memberId > 0 ? 1 : 0;
    $status = 'pending';

    if ($serviceTypeSelected === 'Lainnya') {
        $serviceOther = trim((string)($_POST['service_other'] ?? ''));
        if ($serviceOther !== '') {
            $serviceType = 'Lainnya: ' . $serviceOther;
            if ($notes === '') {
                $notes = "Layanan khusus: $serviceOther";
            } else {
                $notes = "Layanan khusus: $serviceOther | " . $notes;
            }
        } else {
            $serviceType = 'Lainnya';
        }
    } else {
        $serviceType = $serviceTypeSelected;
    }

    // Mulai transaksi
    $pdo->beginTransaction();
    try {
        // Hitung total (tidak perlu lock dulu)
        $member = null;
        if ($memberId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch() ?: null;
        }
        $calc = compute_order_total($_POST, $member, $pdo);
        $memberDiscount = $member ? $calc['discount'] > 0 ? round(($calc['base_fee']+$calc['extra_fee'])*($calc['discount']/100)) : (int)($member['discount_percent'] ?? 0) : (int)($_POST['member_discount_percent'] ?? 0);

        // 1. Insert order
        $stmt = $pdo->prepare("
            INSERT INTO orders (member_id, driver_id, customer_name, customer_phone, service_type, payment_method, pickup_address, drop_address, notes, extra_stops, base_fee, extra_fee, member_discount, total_fee, is_priority, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([$memberId ?: null, $driverId ?: null, $customerName, $customerPhone, $serviceType, $paymentMethod, $pickupAddress, $dropAddress, $notes, $extraStops, $calc['base_fee'], $calc['extra_fee'], $memberDiscount, $calc['total'], $isPriority, $status, $createdBy]);
        $orderId = (int)$pdo->lastInsertId();

        // 2. Jika member, kunci baris member untuk update saldo & poin
        if ($memberId > 0) {
            $lock = $pdo->prepare("SELECT balance, points FROM members WHERE id = ? FOR UPDATE");
            $lock->execute([$memberId]);
            $lockedMember = $lock->fetch();
            if (!$lockedMember) {
                throw new \RuntimeException('Member tidak ditemukan.');
            }

            // Tambah poin
            $pdo->prepare("UPDATE members SET points = points + 1 WHERE id = ?")->execute([$memberId]);

            // Jika bayar pakai saldo, cek kecukupan
            if ($paymentMethod === 'member_balance') {
                if ($lockedMember['balance'] < $calc['total']) {
                    throw new \RuntimeException('Saldo member tidak mencukupi.');
                }
                $pdo->prepare("UPDATE members SET balance = balance - ? WHERE id = ?")->execute([$calc['total'], $memberId]);
                $pdo->prepare("INSERT INTO member_transactions (member_id, order_id, type, amount, note, created_at) VALUES (?, ?, 'debit', ?, ?, NOW())")
                    ->execute([$memberId, $orderId, $calc['total'], 'Pembayaran order #' . $orderId]);
                $newBalance = $lockedMember['balance'] - $calc['total'];
                flash_set('success', 'Order #' . $orderId . ' berhasil. Saldo member sekarang ' . money($newBalance) . '.');
            } else {
                flash_set('success', 'Order #' . $orderId . ' berhasil disimpan.');
            }
        } else {
            flash_set('success', 'Order berhasil disimpan.');
        }

        // 3. Log
        $pdo->prepare("INSERT INTO order_logs (order_id, status, note, created_at) VALUES (?, 'pending', ?, NOW())")
            ->execute([$orderId, 'Order dibuat oleh admin.']);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        flash_set('danger', 'Gagal menyimpan order: ' . $e->getMessage());
        redirect('?page=orders');
    }

    // Notifikasi WA (di luar transaksi)
    $driver = null;
    if ($driverId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch();
    }

    if ($driver && !empty($driver['phone'])) {
        $todayCount = driver_today_completed($pdo, $driverId);
        $target = 20;
        $statusTarget = $todayCount >= $target ? "TARGET TERCAPAI 🎯" : "MENUJU TARGET";
        $bonusInfo = $todayCount >= 20 ? "Bonus +1000/order & +1 poin" : "-";

        $msg = "💙 EAZY DELIVERY\n\n" .
               "📦 ORDER BARU\n" .
               "ID: #$orderId\n" .
               "Pelanggan: $customerName\n" .
               "WA: $customerPhone\n\n" .
               "📍 Jemput:\n$pickupAddress\n\n" .
               "📍 Antar:\n$dropAddress\n\n" .
               "💰 Total: " . money($calc['total']) . "\n\n" .
               "📊 STATUS DRIVER HARI INI\n" .
               "Order selesai: $todayCount\n" .
               "Target: $target\n" .
               "Status: $statusTarget\n" .
               "Bonus: $bonusInfo\n\n" .
               "Silakan terima / tolak order di sistem.";

        $phoneWA = preg_replace('/[^0-9]/', '', $driver['phone']);
        if (substr($phoneWA, 0, 1) === '0') {
            $phoneWA = '62' . substr($phoneWA, 1);
        }
        $_SESSION['wa_driver'] = "https://wa.me/$phoneWA?text=" . urlencode($msg);
    }

    redirect('?page=orders');
}

// ========== APPROVE ORDER ==========
if ($action === 'approve_order' && is_admin()) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $pdo->prepare("UPDATE orders SET admin_status='approved', status='accepted' WHERE id=?")
    ->execute([$orderId]);

    $stmt = $pdo->prepare("SELECT o.*, m.name AS member_name, m.balance AS member_balance, m.points AS member_points, m.discount_percent AS member_discount FROM orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if ($order && !empty($order['customer_phone'])) {
        $waText = "💙 EAZY DELIVERY\n\n" .
                  "● Jenis layanan : " . $order['service_type'] . "\n\n" .
                  "📍 Alamat jemput barang :\n" . $order['pickup_address'] . "\n\n" .
                  "📍 Tujuan pengantaran :\n" . $order['drop_address'] . "\n\n" .
                  "📦 Barang belanja:\n" . $order['notes'] . "\n\n" .
                  "💰 Ongkir: " . money($order['total_fee']) . "\n" .
                  "💳 Pembayaran: " . $order['payment_method'] . "\n\n" .
                  "📞 Contact:\n" . $order['customer_name'] . " - " . $order['customer_phone'] . "\n\n" .
                  "⚠️ Catatan:\n" . $order['notes'];
        if ($order['member_name']) {
            $waText .= "--- INFO MEMBER ---\n" .
                       "Nama: " . $order['member_name'] . "\n" .
                       "Saldo: " . money($order['member_balance'] ?? 0) . "\n" .
                       "Poin: " . (int)($order['member_points'] ?? 0) . "\n" .
                       "Diskon: " . (int)($order['member_discount'] ?? 0) . "%\n\n";
        }
        $waText .= "Terima kasih telah menggunakan EAZY DELIVERY 🙏";

        $phone = preg_replace('/[^0-9]/', '', $order['customer_phone']);
        if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);
        $_SESSION['wa_customer_invoice'] = "https://wa.me/$phone?text=" . urlencode($waText);
    }

    flash_set('success', 'Pesanan disetujui. Silakan kirim invoice ke pelanggan.');
    redirect('?page=orders');
}

// ========== DRIVER ACCEPT (already safe) ==========
if ($action === 'accept_order' && is_driver()) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $user = current_user();
    $uid = (int)$user['id'];

    if (driver_has_active_order($pdo, $uid)) {
        flash_set('warning', 'Anda masih memiliki order yang sedang dalam perjalanan. Selesaikan terlebih dahulu.');
        redirect('?page=driver');
    }

    $stmt = $pdo->prepare("UPDATE orders SET status='accepted', driver_id = COALESCE(driver_id, ?), accepted_at = NOW() WHERE id = ? AND (driver_id IS NULL OR driver_id = ?)");
    $stmt->execute([$uid, $orderId, $uid]);
    if ($stmt->rowCount() === 0) {
        flash_set('danger', 'Order tidak tersedia.');
    } else {
        $pdo->prepare("INSERT INTO order_logs (order_id, status, note, created_at) VALUES (?, 'accepted', ?, NOW())")
            ->execute([$orderId, 'Order diterima driver.']);
        flash_set('success', 'Order diterima.');
    }
    redirect('?page=driver');
}

if ($action === 'reject_order' && is_driver()) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? 'Tidak disebutkan'));
    $user = current_user();
    $stmt = $pdo->prepare("UPDATE orders SET status='rejected', rejected_at = NOW() WHERE id = ? AND (driver_id IS NULL OR driver_id = ?)");
    $stmt->execute([$orderId, $user['id'], $user['id']]);
    $pdo->prepare("INSERT INTO order_rejections (order_id, driver_id, reason, rejection_date) VALUES (?, ?, ?, NOW())")
        ->execute([$orderId, $user['id'], $reason]);
    $pdo->prepare("INSERT INTO order_logs (order_id, status, note, created_at) VALUES (?, 'rejected', ?, NOW())")
        ->execute([$orderId, 'Order ditolak: ' . $reason]);
    flash_set('warning', 'Order ditolak dan dicatat.');
    redirect('?page=driver');
}

if ($action === 'complete_order' && is_driver()) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $user = current_user();
    $stmt = $pdo->prepare("UPDATE orders SET status='completed', completed_at = NOW() WHERE id = ? AND driver_id = ?");
    $stmt->execute([$orderId, $user['id']]);
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order && !empty($order['member_id'])) {
            $pdo->prepare("UPDATE members SET points = points + 1 WHERE id = ?")->execute([(int)$order['member_id']]);
        }
        $pdo->prepare("INSERT INTO order_logs (order_id, status, note, created_at) VALUES (?, 'completed', ?, NOW())")
            ->execute([$orderId, 'Order selesai.']);
        flash_set('success', 'Order ditandai selesai.');
    } else {
        flash_set('danger', 'Gagal menyelesaikan order.');
    }
    redirect('?page=driver');
}

if ($action === 'save_attendance' && is_admin()) {
    $photo = upload_photo('photo');
    $status = trim((string)($_POST['status'] ?? 'hadir'));
    $note = trim((string)($_POST['note'] ?? ''));
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $attendanceDate = trim((string)($_POST['attendance_date'] ?? date('Y-m-d')));
    if (empty($attendanceDate)) $attendanceDate = date('Y-m-d');

    if ($driverId <= 0) {
        flash_set('danger', 'Pilih driver dulu.');
        redirect('?page=attendance');
    }
    $stmt = $pdo->prepare("INSERT INTO attendance (user_id, attendance_date, status, note, photo_path, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$driverId, $attendanceDate, $status, $note, $photo]);
    flash_set('success', 'Absensi tersimpan.');
    redirect('?page=attendance');
}

if ($page === 'driver') {
    require_login();
    flash_set('warning', 'Halaman driver tidak tersedia untuk admin.');
    redirect('?page=dashboard');
}

if ($action === 'save_company_setting' && is_admin()) {
    set_setting($pdo, 'company_discount_percent', (string)max(0, min(100, (int)($_POST['company_discount_percent'] ?? 0))));
    set_setting($pdo, 'company_name', trim((string)($_POST['company_name'] ?? APP_NAME)));
    $pointRules = trim((string)($_POST['point_discount_rules'] ?? ''));
    set_setting($pdo, 'point_discount_rules', $pointRules);
    flash_set('success', 'Pengaturan disimpan.');
    redirect('?page=settings');
}

/* ====== DATA FOR PAGES ====== */
$me = current_user();
$flash = flash_get();

function render_header(string $title): void {
    $user = current_user();
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#f5f7fb; }
/* MODIFIKASI STICKY SIDEBAR */
.sidebar {
    min-height:100vh;
    background:#111827;
    color:#fff;
    position: sticky;
    top: 0;
    align-self: flex-start; /* penting untuk sticky bekerja di flex row */
}
.sidebar a { color:#cbd5e1; text-decoration:none; display:block; padding:.7rem 1rem; border-radius:.7rem; margin:.15rem 0; }
.sidebar a.active, .sidebar a:hover { background:#1f2937; color:#fff; }
.card-stat { border:0; border-radius:1rem; box-shadow:0 8px 20px rgba(15,23,42,.05); }
.table thead th { white-space:nowrap; }
.print-only { display:none; }
@media print {
    .no-print { display:none !important; }
    body { background:#fff; }
    .print-only { display:block; }
    .card, .table { box-shadow:none !important; }
    .sidebar { display:none; } /* sembunyikan sidebar saat cetak */
}
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark no-print">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="?page=dashboard"><?= APP_NAME ?></a>
    <div class="ms-auto d-flex align-items-center gap-2 text-white">
      <?php if ($user): ?>
        <span class="small">Login sebagai <b><?= e($user['full_name']) ?></b> (<?= e($user['role']) ?>)</span>
        <a class="btn btn-sm btn-outline-light" href="?page=logout">Logout</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<div class="container-fluid">
  <div class="row">
    <?php if ($user): ?>
    <div class="col-lg-2 p-3 sidebar no-print">
      <div class="mb-3">
        <div class="fw-bold fs-5"><?= APP_NAME ?></div>
        <div class="text-secondary small">Delivery admin system</div>
      </div>
      <a href="?page=dashboard" class="<?= ($_GET['page'] ?? 'dashboard')==='dashboard' ? 'active':'' ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
      <a href="?page=orders" class="<?= ($_GET['page'] ?? '')==='orders' ? 'active':'' ?>"><i class="bi bi-truck me-2"></i>Pesanan</a>
      <a href="?page=members" class="<?= ($_GET['page'] ?? '')==='members' ? 'active':'' ?>"><i class="bi bi-person-badge me-2"></i>Member</a>
      <a href="?page=drivers" class="<?= ($_GET['page'] ?? '')==='drivers' ? 'active':'' ?>"><i class="bi bi-person-gear me-2"></i>Driver</a>
      <a href="?page=attendance" class="<?= ($_GET['page'] ?? '')==='attendance' ? 'active':'' ?>"><i class="bi bi-card-checklist me-2"></i>Absensi</a>
      <a href="?page=reports" class="<?= ($_GET['page'] ?? '')==='reports' ? 'active':'' ?>"><i class="bi bi-graph-up me-2"></i>Laporan</a>
      <a href="?page=invoices" class="<?= ($_GET['page'] ?? '')==='invoices' ? 'active':'' ?>"><i class="bi bi-receipt me-2"></i>Invoice</a>
      <a href="?page=settings" class="<?= ($_GET['page'] ?? '')==='settings' ? 'active':'' ?>"><i class="bi bi-gear me-2"></i>Setting</a>
      <?php if (($user['role'] ?? '') === 'driver'): ?>
        <a href="?page=driver" class="<?= ($_GET['page'] ?? '')==='driver' ? 'active':'' ?>"><i class="bi bi-clipboard-check me-2"></i>Area Driver</a>
      <?php endif; ?>
    </div>
    <div class="col-lg-10 p-3 p-lg-4">
    <?php else: ?>
    <div class="col-12 p-3 p-lg-5">
    <?php endif; ?>
    <?php
}

function render_footer(): void {
    ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

if ($page === 'install') {
    render_header('Instalasi');
    ?>
    <div class="alert alert-warning">
      <h4 class="alert-heading">Database belum siap</h4>
      Import file <b>schema.sql</b> ke MySQL dulu, lalu sesuaikan username/password database di bagian atas <code>index.php</code>.
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'login') {
    render_header('Login');
    ?>
    <div class="row justify-content-center">
      <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm border-0 rounded-4">
          <div class="card-body p-4">
            <h3 class="fw-bold mb-1"><?= APP_NAME ?></h3>
            <p class="text-secondary">Panel admin dan driver</p>
            <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
            <form method="post">
              <input type="hidden" name="action" value="login">
              <div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
              <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required></div>
              <button class="btn btn-dark w-100">Masuk</button>
            </form>
            <div class="small text-secondary mt-3">Login awal ada di file <code>schema.sql</code>.</div>
          </div>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($me && $me['role'] === 'driver' && $page === 'dashboard') {
    $page = 'driver';
}

/* ====== PAGES ====== */
if ($page === 'dashboard') {
    require_login();
    $stats = admin_dashboard_stats($pdo);
    $recent = $pdo->query("
        SELECT o.*, m.name AS member_name, u.full_name AS driver_name, a.full_name AS admin_name
        FROM orders o
        LEFT JOIN members m ON m.id = o.member_id
        LEFT JOIN users u ON u.id = o.driver_id
        LEFT JOIN users a ON a.id = o.created_by
        ORDER BY o.is_priority DESC, o.created_at DESC
        LIMIT 10
    ")->fetchAll();
    render_header('Dashboard');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['wa_driver'])): ?>
        <a href="<?= $_SESSION['wa_driver'] ?>" target="_blank" class="btn btn-success mb-3">Kirim Notifikasi ke Driver (WA)</a>
    <?php unset($_SESSION['wa_driver']); endif; ?>

    <div class="row g-3">
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Order Hari Ini</div><div class="fs-3 fw-bold"><?= (int)$stats['today_orders'] ?></div></div></div>
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Pendapatan Hari Ini</div><div class="fs-3 fw-bold"><?= money($stats['today_revenue']) ?></div></div></div>
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Profit Bulan Ini</div><div class="fs-3 fw-bold"><?= money($stats['month_profit']) ?></div></div></div>
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Member / Driver</div><div class="fs-3 fw-bold"><?= (int)$stats['member_count'] ?> / <?= (int)$stats['driver_count'] ?></div></div></div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-lg-7">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="fw-bold mb-0">Pesanan Terbaru</h5>
              <a class="btn btn-sm btn-outline-dark" href="?page=orders">Kelola Pesanan</a>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead><tr><th>#</th><th>Tanggal</th><th>Pelanggan</th><th>Member</th><th>Layanan</th><th>Status</th><th>Total</th><th>Admin</th></tr></thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr class="<?= $r['is_priority'] ? 'table-warning' : '' ?>">
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                      <td><?= e($r['customer_name']) ?><div class="small text-secondary"><?= e($r['customer_phone']) ?></div></td>
                      <td><?= $r['member_name'] ? e($r['member_name']) : '-' ?></td>
                      <td><?= e($r['service_type']) ?></td>
                      <td><span class="badge bg-<?= e(order_status_badge($r['status'])) ?>"><?= e($r['status']) ?></span></td>
                      <td><?= money($r['total_fee']) ?></td>
                      <td><?= e($r['admin_name'] ?? '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card border-0 rounded-4 shadow-sm mb-3">
          <div class="card-body">
            <h5 class="fw-bold">Absensi Cepat</h5>
            <form method="post" enctype="multipart/form-data" class="mt-3">
              <input type="hidden" name="action" value="save_attendance">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Tanggal</label>
                  <input type="date" class="form-control" name="attendance_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Driver</label>
                  <select class="form-select" name="driver_id" required>
                    <option value="">Pilih</option>
                    <?php $drivers = $pdo->query("SELECT id, full_name FROM users WHERE role='driver'")->fetchAll(); ?>
                    <?php foreach ($drivers as $d): ?>
                      <option value="<?= $d['id'] ?>"><?= e($d['full_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <select class="form-select" name="status">
                    <option value="hadir">Hadir</option>
                    <option value="telat">Telat</option>
                    <option value="izin">Izin</option>
                    <option value="lembur">Lembur</option>
                  </select>
                </div>
                <div class="col-md-6"><input class="form-control" name="note" placeholder="Catatan absensi"></div>
                <div class="col-12"><input type="file" class="form-control" name="photo" accept="image/*"></div>
              </div>
              <button class="btn btn-dark mt-3">Simpan Absensi</button>
            </form>
          </div>
        </div>
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h5 class="fw-bold">Info Layanan</h5>
            <div class="list-group list-group-flush">
              <div class="list-group-item">Antar makanan dan minuman</div>
              <div class="list-group-item">Paket dan dokumen</div>
              <div class="list-group-item">Kebutuhan belanja harian</div>
              <div class="list-group-item">Antar jemput laundry</div>
              <div class="list-group-item">Layanan lainnya (bisa custom)</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'orders') {
    require_login();
    $members = $pdo->query("SELECT * FROM members ORDER BY points DESC, balance DESC, name ASC")->fetchAll();
    $drivers = $pdo->query("SELECT * FROM users WHERE role='driver' ORDER BY is_active DESC, full_name ASC")->fetchAll();
    $orders = $pdo->query("
        SELECT o.*, m.name AS member_name, u.full_name AS driver_name, a.full_name AS admin_name
        FROM orders o
        LEFT JOIN members m ON m.id = o.member_id
        LEFT JOIN users u ON u.id = o.driver_id
        LEFT JOIN users a ON a.id = o.created_by
        WHERE o.status NOT IN ('completed', 'rejected')
        ORDER BY o.is_priority DESC, o.created_at DESC
    ")->fetchAll();
    render_header('Pesanan');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['wa_driver'])): ?>
        <a href="<?= $_SESSION['wa_driver'] ?>" target="_blank" class="btn btn-success mb-3">Kirim Detail ke Driver (WA)</a>
    <?php unset($_SESSION['wa_driver']); endif; ?>
    <?php if (!empty($_SESSION['wa_customer_invoice'])): ?>
        <a href="<?= $_SESSION['wa_customer_invoice'] ?>" target="_blank" class="btn btn-info mb-3">Kirim Invoice ke WA Pelanggan</a>
    <?php unset($_SESSION['wa_customer_invoice']); endif; ?>

    <div class="card border-0 rounded-4 shadow-sm mb-4">
      <div class="card-body">
        <h4 class="fw-bold mb-3">Input Pesanan Baru</h4>
        <div class="card border-0 rounded-4 shadow-sm mb-3">
          <div class="card-body">
            <h5 class="fw-bold">Auto Fill dari Chat WA</h5>
            <textarea id="autoText" class="form-control" rows="4" placeholder="Paste chat pelanggan di sini..."></textarea>
            <button type="button" onclick="autoFillWA()" class="btn btn-primary mt-2">Auto Fill Sekarang</button>
          </div>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="save_order">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Member Prioritas</label>
              <select class="form-select" name="member_id" id="memberSelect" onchange="fillMember()">
                <option value="0">Non member</option>
                <?php foreach ($members as $m): ?>
                  <option value="<?= (int)$m['id'] ?>"
                    data-name="<?= e($m['name']) ?>"
                    data-phone="<?= e($m['phone']) ?>"
                    data-balance="<?= (int)$m['balance'] ?>"
                    data-points="<?= (int)$m['points'] ?>"
                    data-discount="<?= (int)$m['discount_percent'] ?>">
                    <?= e($m['name']) ?> (saldo <?= money($m['balance']) ?>, poin <?= (int)$m['points'] ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Nama Pelanggan</label>
              <input class="form-control" name="customer_name" id="customer_name" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">No. WA</label>
              <input class="form-control" name="customer_phone" id="customer_phone" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Layanan</label>
              <select class="form-select" name="service_type" id="service_type" onchange="toggleServiceOther()">
                <option>Antar makanan dan minuman</option>
                <option>Paket dan dokumen</option>
                <option>Kebutuhan belanja harian</option>
                <option>Antar jemput laundry</option>
                <option value="Lainnya">Lainnya</option>
              </select>
              <input type="text" class="form-control mt-1 d-none" name="service_other" id="service_other" placeholder="Sebutkan layanan khusus...">
            </div>
            <div class="col-md-4">
              <label class="form-label">Driver</label>
              <select class="form-select" name="driver_id">
                <option value="">Pilih driver</option>
                <?php foreach ($drivers as $d): ?>
                  <?php $st = driver_week_status($pdo, (int)$d['id']); ?>
                  <option value="<?= (int)$d['id'] ?>" <?= $st['blocked'] ? 'disabled' : '' ?>><?= e($d['full_name']) ?> (<?= $st['label'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Pembayaran</label>
              <select class="form-select" name="payment_method" id="payment_method">
                <option value="cash">Cash</option>
                <option value="non_cash">Non-cash</option>
                <option value="member_balance">Saldo Member</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Alamat Jemput</label>
              <input class="form-control" name="pickup_address" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Alamat Antar</label>
              <input class="form-control" name="drop_address" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Titik Tambahan</label>
              <input type="number" min="0" class="form-control" name="extra_stops" value="0">
            </div>
            <div class="col-md-3">
              <label class="form-label">Diskon Member (%)</label>
              <input type="number" min="0" max="100" class="form-control" name="member_discount_percent" id="member_discount_percent" value="0" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label">Catatan</label>
              <input class="form-control" name="notes" placeholder="Contoh: cepat, tidak bisa telat, COD, dll.">
            </div>
            <div class="col-12">
              <div id="memberBalanceInfo" class="alert alert-info d-none">
                <strong>Info Saldo Member:</strong> Tersedia <span id="memberBalanceValue"></span><br>
                ⚠️ Pembayaran otomatis menggunakan <b>Saldo Member</b>.
              </div>
            </div>
          </div>
          <button class="btn btn-dark mt-3">Simpan Pesanan</button>
        </form>
      </div>
    </div>

    <div class="card border-0 rounded-4 shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="fw-bold mb-0">Daftar Pesanan</h4>
          <span class="badge bg-warning text-dark">Member naik ke atas</span>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>#</th><th>Tanggal</th><th>Pelanggan</th><th>Member</th><th>Layanan</th><th>Driver</th><th>Status</th><th>Total</th><th>Admin</th><th>Invoice</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
              <tr class="<?= $o['is_priority'] ? 'table-warning' : '' ?>">
                <td><?= (int)$o['id'] ?></td>
                <td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                <td><?= e($o['customer_name']) ?><div class="small text-secondary"><?= e($o['customer_phone']) ?></div></td>
                <td><?= $o['member_name'] ? e($o['member_name']) : '-' ?></td>
                <td><?= e($o['service_type']) ?></td>
                <td><?= $o['driver_name'] ? e($o['driver_name']) : '-' ?></td>
                <td><span class="badge bg-<?= e(order_status_badge($o['status'])) ?>"><?= e($o['status']) ?></span></td>
                <td><?= money($o['total_fee']) ?></td>
                <td><?= e($o['admin_name'] ?? '-') ?></td>
                <td><a class="btn btn-sm btn-outline-dark" href="?page=invoice&id=<?= (int)$o['id'] ?>">Lihat</a></td>
                <td class="d-flex flex-column gap-2">
                  <?php if (is_admin()): ?>
                    <form method="post">
                      <input type="hidden" name="action" value="approve_order">
                      <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                      <button class="btn btn-success btn-sm w-100">Terima</button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm w-100" onclick="toggleReject(<?= $o['id'] ?>)">Tolak</button>
                    <div id="rejectForm<?= $o['id'] ?>" style="display:none; background:#fff3f3; padding:10px; border-radius:6px; border:1px solid #ffc9c9;">
                      <form method="post">
                        <input type="hidden" name="action" value="admin_reject_order">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <input type="hidden" name="driver_id" value="<?= $o['driver_id'] ?>">
                        <label class="small fw-bold">Alasan penolakan</label>
                        <input type="text" name="reason" class="form-control form-control-sm mb-2" required>
                        <label class="small fw-bold">Ganti driver (opsional)</label>
                        <select name="new_driver_id" class="form-select form-select-sm mb-2">
                          <option value="">Tidak ganti driver</option>
                          <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= e($d['full_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-danger btn-sm w-100">Simpan Penolakan</button>
                      </form>
                    </div>
                    <form method="post">
                      <input type="hidden" name="action" value="complete_order_admin">
                      <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                      <button class="btn btn-dark btn-sm w-100">Selesai</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
<script>
const memberData = {};
document.querySelectorAll('#memberSelect option').forEach(opt => {
  if (!opt.value || opt.value === '0') return;
  memberData[opt.value] = {
    name: opt.dataset.name || '',
    phone: opt.dataset.phone || '',
    balance: opt.dataset.balance || '0',
    points: opt.dataset.points || '0',
    discount: opt.dataset.discount || '0',
  };
});
function fillMember() {
  const sel = document.getElementById('memberSelect');
  const data = memberData[sel.value];
  const paySelect = document.getElementById('payment_method');
  const balanceDiv = document.getElementById('memberBalanceInfo');
  const balanceSpan = document.getElementById('memberBalanceValue');
  if (data) {
    document.getElementById('customer_name').value = data.name;
    document.getElementById('customer_phone').value = data.phone;
    document.getElementById('member_discount_percent').value = data.discount;
    paySelect.value = 'member_balance';
    balanceSpan.textContent = 'Rp ' + parseInt(data.balance).toLocaleString('id-ID');
    balanceDiv.classList.remove('d-none');
  } else {
    document.getElementById('customer_name').value = '';
    document.getElementById('customer_phone').value = '';
    document.getElementById('member_discount_percent').value = '0';
    paySelect.value = 'cash';
    balanceDiv.classList.add('d-none');
  }
}
function toggleServiceOther() {
  const sel = document.getElementById('service_type');
  const otherInput = document.getElementById('service_other');
  if (sel.value === 'Lainnya') {
    otherInput.classList.remove('d-none');
  } else {
    otherInput.classList.add('d-none');
  }
}
function autoFillWA() {
  let text = document.getElementById("autoText").value.toLowerCase();
  function ambil(patterns, group = 1) {
    for (let p of patterns) {
      let match = text.match(p);
      if (match) return match[group].trim();
    }
    return "";
  }
  let nama = ambil([/nama[:\s\-]*([^\n]+)/i]);
  let wa = ambil([/(wa|hp|no)[:\s\-]*([0-9]+)/i], 2);
  if (!wa) { let m = text.match(/08[0-9]{8,13}/); if(m) wa = m[0]; }
  let jemput = ambil([/(jemput|pickup|ambil)[:\s\-]*([^\n]+)/i], 2);
  let antar = ambil([/(antar|kirim|tujuan)[:\s\-]*([^\n]+)/i], 2);
  let layanan = ambil([/(layanan|jenis)[:\s\-]*([^\n]+)/i], 2);
  let titik = ambil([/(titik|stop)[:\s\-]*([0-9]+)/i], 2);
  if (nama) document.getElementById("customer_name").value = nama;
  if (wa) document.getElementById("customer_phone").value = wa;
  if (jemput) document.querySelector('[name="pickup_address"]').value = jemput;
  if (antar) document.querySelector('[name="drop_address"]').value = antar;
  if (titik) document.querySelector('[name="extra_stops"]').value = titik;
  if (layanan) {
    let select = document.querySelector('[name="service_type"]');
    for (let i=0; i<select.options.length; i++) {
      if (select.options[i].text.toLowerCase().includes(layanan)) {
        select.selectedIndex = i;
        toggleServiceOther();
        break;
      }
    }
  }
  alert("Auto-fill berhasil 🚀");
}
function toggleReject(id) {
  let el = document.getElementById('rejectForm' + id);
  if (!el) return;
  if (el.style.display === 'none' || el.style.display === '') {
    el.style.display = 'block';
    el.querySelector('input[name="reason"]').focus();
  } else {
    el.style.display = 'none';
  }
}
</script>
    <?php
    render_footer();
    exit;
}

if ($page === 'members') {
    require_login();
    $editId = (int)($_GET['edit'] ?? 0);
    $editMember = null;
    if ($editId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$editId]);
        $editMember = $stmt->fetch();
    }
    $members = $pdo->query("SELECT * FROM members ORDER BY balance DESC, points DESC, name ASC")->fetchAll();
    render_header('Member');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h4 class="fw-bold"><?= $editMember ? 'Edit Member' : 'Tambah Member' ?></h4>
            <form method="post" class="mt-3">
              <input type="hidden" name="action" value="save_member">
              <input type="hidden" name="id" value="<?= $editMember ? (int)$editMember['id'] : 0 ?>">
              <input class="form-control mb-2" name="name" placeholder="Nama member" required value="<?= $editMember ? e($editMember['name']) : '' ?>">
              <input class="form-control mb-2" name="phone" placeholder="No. WA" required value="<?= $editMember ? e($editMember['phone']) : '' ?>">
              <div class="row g-2">
                <div class="col-4"><input type="number" class="form-control" name="balance" placeholder="Saldo" value="<?= $editMember ? (int)$editMember['balance'] : 0 ?>"></div>
                <div class="col-4"><input type="number" class="form-control" name="points" placeholder="Poin" value="<?= $editMember ? (int)$editMember['points'] : 0 ?>"></div>
                <div class="col-4"><input type="number" class="form-control" name="discount_percent" placeholder="Diskon%" value="<?= $editMember ? (int)$editMember['discount_percent'] : 0 ?>"></div>
              </div>
              <textarea class="form-control mt-2" name="notes" placeholder="Catatan"><?= $editMember ? e($editMember['notes'] ?? '') : '' ?></textarea>
              <button class="btn btn-dark mt-3"><?= $editMember ? 'Update' : 'Simpan' ?> Member</button>
              <?php if ($editMember): ?>
                <a href="?page=members" class="btn btn-outline-secondary mt-3">Batal</a>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h4 class="fw-bold mb-3">Daftar Member</h4>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead><tr><th>Nama</th><th>WA</th><th>Saldo</th><th>Poin</th><th>Diskon</th><th>Keterangan</th><th>Tgl Daftar</th><th>Tgl Update</th><th>Aksi</th></tr></thead>
                <tbody>
                  <?php foreach ($members as $m):
                    $waMemberMsg = "💙 EAZY DELIVERY\n\nMember: " . $m['name'] . "\nSaldo: " . money($m['balance']) . "\nPoin: " . (int)$m['points'] . "\nDiskon: " . (int)$m['discount_percent'] . "%\n\nInfo lebih lanjut hubungi admin.";
                    $phoneMember = preg_replace('/[^0-9]/', '', $m['phone']);
                    if (substr($phoneMember, 0, 1) === '0') $phoneMember = '62' . substr($phoneMember, 1);
                    $waLinkMember = "https://wa.me/$phoneMember?text=" . urlencode($waMemberMsg);
                  ?>
                  <tr>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e($m['phone']) ?></td>
                    <td><?= money($m['balance']) ?></td>
                    <td><?= (int)$m['points'] ?></td>
                    <td><?= (int)$m['discount_percent'] ?>%</td>
                    <td><?= e($m['notes'] ?? '') ?></td>
                    <td><?= !empty($m['created_at']) ? date('d/m/Y H:i', strtotime($m['created_at'])) : '-' ?></td>
                    <td><?= !empty($m['updated_at']) ? date('d/m/Y H:i', strtotime($m['updated_at'])) : '-' ?></td>
                    <td>
                      <a href="?page=members&edit=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-dark">Edit</a>
                      <a href="<?= e($waLinkMember) ?>" target="_blank" class="btn btn-sm btn-outline-success">Kirim WA</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'drivers') {
    require_login();
    if (!is_admin()) {
        flash_set('warning', 'Halaman ini khusus admin.');
        redirect('?page=driver');
    }
    $drivers = $pdo->query("SELECT * FROM users WHERE role='driver' ORDER BY is_active DESC, full_name ASC")->fetchAll();

    $todayOrderCounts = [];
    $stmt = $pdo->query("SELECT driver_id, COUNT(*) AS cnt FROM orders WHERE status='completed' AND DATE(completed_at)=CURDATE() GROUP BY driver_id");
    foreach ($stmt->fetchAll() as $row) {
        $todayOrderCounts[(int)$row['driver_id']] = (int)$row['cnt'];
    }
    foreach ($drivers as &$d) {
        $d['today_orders'] = $todayOrderCounts[(int)$d['id']] ?? 0;
    }
    unset($d);
    usort($drivers, function($a, $b) {
        return $b['today_orders'] - $a['today_orders'];
    });

    $rekapStart = $_GET['rekap_start'] ?? date('Y-m-01');
    $rekapEnd   = $_GET['rekap_end']   ?? date('Y-m-d');
    $rekapData = [];
    if (strtotime($rekapStart) && strtotime($rekapEnd)) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name,
                   COUNT(o.id) AS total_orders,
                   COALESCE(SUM(o.total_fee),0) AS total_revenue
            FROM users u
            LEFT JOIN orders o ON o.driver_id = u.id
                AND o.status = 'completed'
                AND o.completed_at BETWEEN ? AND ?
            WHERE u.role = 'driver'
            GROUP BY u.id, u.full_name
            ORDER BY total_orders DESC
        ");
        $stmt->execute([$rekapStart . ' 00:00:00', $rekapEnd . ' 23:59:59']);
        $rekapData = $stmt->fetchAll();
    }

    render_header('Driver');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h4 class="fw-bold">Tambah Driver</h4>
            <form method="post" class="mt-3">
              <input type="hidden" name="action" value="save_driver">
              <input class="form-control mb-2" name="name" placeholder="Nama driver" required>
              <input class="form-control mb-2" name="phone" placeholder="No. WA">
              <input class="form-control mb-2" name="username" placeholder="Username" required>
              <input class="form-control mb-2" type="password" name="password" placeholder="Password (default driver123)">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="act" checked><label class="form-check-label" for="act">Aktif</label></div>
              <button class="btn btn-dark mt-3">Simpan Driver</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card border-0 rounded-4 shadow-sm mb-3">
          <div class="card-body">
            <h4 class="fw-bold mb-3">Data Driver (Urut Order Hari Ini)</h4>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                <tr>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Aktif</th>
                    <th>Order Hari Ini</th>
                    <th>Rejeksi Minggu Ini</th>
                    <th>Status</th>
                    <th>Sedang Jalan?</th>
                </tr>
                </thead>
                <tbody>
                  <?php foreach ($drivers as $d): $st = driver_week_status($pdo, (int)$d['id']); $activeOrder = driver_has_active_order($pdo, (int)$d['id']); ?>
                    <tr class="<?= $st['badge'] === 'warning' ? 'table-warning' : ($st['badge'] === 'danger' ? 'table-danger' : '') ?>">
                      <td><?= e($d['full_name']) ?></td>
                      <td><?= e($d['username']) ?></td>
                      <td><?= (int)$d['is_active'] ? '✔' : '❌' ?></td>
                      <td><strong><?= $d['today_orders'] ?></strong></td>
                      <td><?= $st['count'] ?></td>
                      <td><span class="badge bg-<?= e($st['badge']) ?>"><?= e($st['label']) ?></span></td>
                      <td><?= $activeOrder ? '🚚 Dalam perjalanan' : '✅ Siap' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h4 class="fw-bold mb-3">Rekap Kinerja Driver</h4>
            <form method="get" class="row g-2 mb-3">
              <input type="hidden" name="page" value="drivers">
              <div class="col-md-4"><label class="form-label">Dari Tanggal</label><input type="date" class="form-control" name="rekap_start" value="<?= e($rekapStart) ?>"></div>
              <div class="col-md-4"><label class="form-label">Sampai Tanggal</label><input type="date" class="form-control" name="rekap_end" value="<?= e($rekapEnd) ?>"></div>
              <div class="col-md-4 d-flex align-items-end"><button type="submit" class="btn btn-dark">Tampilkan</button></div>
            </form>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
<tr>
    <th>Driver</th>
    <th>Jumlah Order Selesai</th>
    <th>Total Pendapatan</th>
</tr>
</thead>
                <tbody>
                <?php foreach ($rekapData as $rd): ?>
                  <tr><td><?= e($rd['full_name']) ?></td><td><?= (int)$rd['total_orders'] ?></td><td><?= money($rd['total_revenue']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($rekapData)): ?>
                  <tr><td colspan="3" class="text-center">Tidak ada data pada rentang tanggal tersebut.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'attendance') {
    require_login();
    $filterDate = $_GET['filter_date'] ?? '';
    if (!empty($filterDate) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
        $filterDate = '';
    }
    if ($filterDate) {
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name, u.role
            FROM attendance a
            JOIN users u ON u.id = a.user_id
            WHERE a.attendance_date = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$filterDate]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $pdo->query("
            SELECT a.*, u.full_name, u.role
            FROM attendance a
            JOIN users u ON u.id = a.user_id
            ORDER BY a.created_at DESC
        ")->fetchAll();
    }
    render_header('Absensi');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h4 class="fw-bold">Absensi</h4>
            <form method="post" enctype="multipart/form-data" class="mt-3">
              <input type="hidden" name="action" value="save_attendance">
              <div class="mb-2"><label class="form-label">Tanggal</label><input type="date" class="form-control" name="attendance_date" value="<?= date('Y-m-d') ?>" required></div>
              <select class="form-select mb-2" name="driver_id" required>
                <option value="">Pilih Driver</option>
                <?php $drivers = $pdo->query("SELECT id, full_name FROM users WHERE role='driver'")->fetchAll(); ?>
                <?php foreach ($drivers as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= e($d['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select class="form-select mb-2" name="status">
                <option value="hadir">Hadir</option>
                <option value="telat">Telat</option>
                <option value="izin">Izin</option>
                <option value="lembur">Lembur</option>
              </select>
              <input class="form-control mb-2" name="note" placeholder="Catatan">
              <input type="file" class="form-control mb-2" name="photo" accept="image/*">
              <button class="btn btn-dark">Simpan</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h4 class="fw-bold mb-3">Riwayat Absensi</h4>
            <form method="get" class="row g-2 mb-3 align-items-end">
              <input type="hidden" name="page" value="attendance">
              <div class="col-md-4">
                <label class="form-label">Filter Tanggal</label>
                <input type="date" class="form-control" name="filter_date" value="<?= e($filterDate) ?>" placeholder="YYYY-MM-DD">
              </div>
              <div class="col-md-2">
                <button type="submit" class="btn btn-dark w-100">Filter</button>
              </div>
              <div class="col-md-2">
                <a href="?page=attendance" class="btn btn-outline-secondary w-100">Reset</a>
              </div>
            </form>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead><tr><th>Tanggal</th><th>User</th><th>Status</th><th>Catatan</th><th>Bukti Foto</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?= e($r['attendance_date']) ?></td>
                    <td><?= e($r['full_name']) ?> <span class="text-secondary small">(<?= e($r['role']) ?>)</span></td>
                    <td><span class="badge bg-info text-dark"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['note'] ?? '') ?></td>
                    <td><?php if (!empty($r['photo_path'])): ?><a href="<?= e($r['photo_path']) ?>" target="_blank">Lihat foto</a><?php else: ?>-<?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="5" class="text-center">Tidak ada data absensi.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

/* ====== HALAMAN LAPORAN DENGAN FILTER ADMIN ====== */
if ($page === 'reports') {
    require_login();

    // Rentang tanggal
    $customStart = $_GET['start'] ?? null;
    $customEnd = $_GET['end'] ?? null;
    if ($customStart && $customEnd && strtotime($customStart) && strtotime($customEnd)) {
        $start = $customStart;
        $end = $customEnd;
        $title = "Laporan Kustom: $start s/d $end";
    } else {
        $type = $_GET['type'] ?? 'weekly';
        if ($type === 'monthly') {
            [$start, $end] = month_range();
            $title = 'Laporan Bulanan';
        } else {
            [$start, $end] = week_range();
            $title = 'Laporan Mingguan';
        }
    }
    $startDt = $start . ' 00:00:00';
    $endDt = $end . ' 23:59:59';

    // MODIFIKASI FILTER ADMIN
    $filterAdmin = $_GET['admin_filter'] ?? 'all'; // 'all' atau ID admin
    $adminList = $pdo->query("SELECT id, full_name FROM users WHERE role='admin' ORDER BY full_name")->fetchAll();

    $whereAdmin = '';
    $params = [$startDt, $endDt];
    if ($filterAdmin !== 'all' && is_numeric($filterAdmin)) {
        $whereAdmin = "AND o.created_by = ?";
        $params[] = (int)$filterAdmin;
    }

    $ordersStmt = $pdo->prepare("
        SELECT o.*, m.name AS member_name, u.full_name AS driver_name, a.full_name AS admin_name
        FROM orders o
        LEFT JOIN members m ON m.id = o.member_id
        LEFT JOIN users u ON u.id = o.driver_id
        LEFT JOIN users a ON a.id = o.created_by
        WHERE o.created_at BETWEEN ? AND ? $whereAdmin
        ORDER BY o.created_at DESC
    ");
    $ordersStmt->execute($params);
    $orders = $ordersStmt->fetchAll();

    // Perhitungan statistik tetap sama
    $revenue = 0; $bonus = 0; $completed = 0; $memberOrders = 0; $cash = 0; $nonCash = 0; $memberBalance = 0;
    $totalExtraStops = 0;
    $driverSeq = [];
    $dailyRevenue = [];
    foreach ($orders as $o) {
        $day = substr($o['created_at'], 0, 10);
        $dailyRevenue[$day] = ($dailyRevenue[$day] ?? 0) + (int)$o['total_fee'];

        $revenue += (int)$o['total_fee'];
        if ($o['payment_method'] === 'cash') $cash += (int)$o['total_fee'];
        if ($o['payment_method'] === 'non_cash') $nonCash += (int)$o['total_fee'];
        if ($o['payment_method'] === 'member_balance') $memberBalance += (int)$o['total_fee'];
        if (!empty($o['member_id'])) $memberOrders++;
        $totalExtraStops += (int)$o['extra_stops'];
        if ($o['status'] === 'completed') {
            $completed++;
            $driverId = (int)($o['driver_id'] ?? 0);
            $cd = substr($o['completed_at'], 0, 10);
            $driverSeq[$driverId][$cd] = ($driverSeq[$driverId][$cd] ?? 0) + 1;
            if ($driverSeq[$driverId][$cd] > 20) $bonus += 1000;
        }
    }
    $profit = $revenue - $bonus;

    $driverPerf = [];
    foreach ($orders as $o) {
        $did = (int)($o['driver_id'] ?? 0);
        if (!$did) continue;
        if (!isset($driverPerf[$did])) $driverPerf[$did] = ['name' => $o['driver_name'] ?? 'Driver '.$did, 'orders' => 0, 'revenue' => 0, 'extra_stops' => 0];
        $driverPerf[$did]['orders']++;
        $driverPerf[$did]['revenue'] += (int)$o['total_fee'];
        $driverPerf[$did]['extra_stops'] += (int)$o['extra_stops'];
    }

    render_header($title);
    ?>
    <div class="card border-0 rounded-4 shadow-sm mb-3 no-print">
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
          <input type="hidden" name="page" value="reports">
          <div class="col-md-3">
            <label class="form-label">Dari Tanggal</label>
            <input type="date" class="form-control" name="start" value="<?= e($start) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Sampai Tanggal</label>
            <input type="date" class="form-control" name="end" value="<?= e($end) ?>">
          </div>
          <!-- Filter Admin -->
          <div class="col-md-2">
            <label class="form-label">Admin</label>
            <select class="form-select" name="admin_filter">
              <option value="all" <?= $filterAdmin === 'all' ? 'selected' : '' ?>>Semua Admin</option>
              <?php foreach ($adminList as $adm): ?>
                <option value="<?= $adm['id'] ?>" <?= (string)$filterAdmin === (string)$adm['id'] ? 'selected' : '' ?>><?= e($adm['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-dark w-100">Tampilkan</button>
          </div>
          <div class="col-md-2">
            <a href="?page=reports" class="btn btn-outline-dark w-100">Mingguan</a>
          </div>
        </form>
        <div class="d-flex justify-content-between mt-3">
          <h4 class="fw-bold mb-0"><?= e($title) ?></h4>
          <button class="btn btn-dark" onclick="window.print()">Cetak / PDF</button>
        </div>
        <div class="print-only mt-3">
          <hr>
          <div>Dicetak oleh: <strong><?= e(current_user()['full_name'] ?? 'Admin') ?></strong> (<?= e(current_user()['role'] ?? '-') ?>), Tanggal: <?= date('d/m/Y H:i') ?></div>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Revenue</div><div class="fs-4 fw-bold"><?= money($revenue) ?></div></div></div>
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Bonus Driver</div><div class="fs-4 fw-bold"><?= money($bonus) ?></div></div></div>
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Profit Bersih</div><div class="fs-4 fw-bold"><?= money($profit) ?></div></div></div>
      <div class="col-md-3"><div class="card card-stat p-3"><div class="text-secondary">Completed / Member</div><div class="fs-4 fw-bold"><?= $completed ?> / <?= $memberOrders ?></div></div></div>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-lg-8">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h5 class="fw-bold mb-3">Pendapatan Harian</h5>
            <canvas id="revenueChart" height="100"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h5 class="fw-bold">Ringkasan Pembayaran</h5>
            <table class="table mt-3">
              <tr><td>Cash</td><td><?= money($cash) ?></td></tr>
              <tr><td>Non-cash</td><td><?= money($nonCash) ?></td></tr>
              <tr><td>Saldo member</td><td><?= money($memberBalance) ?></td></tr>
              <tr><td><strong>Total Titik Tambahan</strong></td><td><?= $totalExtraStops ?> titik</td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-lg-12">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h5 class="fw-bold mb-3">Performa Driver</h5>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead><tr><th>Driver</th><th>Jumlah Order</th><th>Total Pendapatan</th><th>Total Titik Tambahan</th></tr></thead>
                <tbody>
                <?php foreach ($driverPerf as $dp): ?>
                  <tr><td><?= e($dp['name']) ?></td><td><?= $dp['orders'] ?></td><td><?= money($dp['revenue']) ?></td><td><?= $dp['extra_stops'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-lg-12">
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body">
            <h5 class="fw-bold mb-3">Detail Order</h5>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead><tr><th>#</th><th>Tanggal</th><th>Pelanggan</th><th>Layanan</th><th>Titik Tambahan</th><th>Metode</th><th>Total</th><th>Status</th><th>Admin</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                  <tr>
                    <td><?= (int)$o['id'] ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                    <td><?= e($o['customer_name']) ?></td>
                    <td><?= e($o['service_type']) ?></td>
                    <td><?= (int)$o['extra_stops'] ?> titik</td>
                    <td><?= e($o['payment_method']) ?></td>
                    <td><?= money($o['total_fee']) ?></td>
                    <td><?= e($o['status']) ?></td>
                    <td><?= e($o['admin_name'] ?? '-') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    const ctx = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_keys($dailyRevenue)) ?>,
            datasets: [{
                label: 'Pendapatan (Rp)',
                data: <?= json_encode(array_values($dailyRevenue)) ?>,
                backgroundColor: '#0f766e',
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: function(value) { return 'Rp ' + value.toLocaleString('id-ID'); } }
                }
            }
        }
    });
    </script>
    <?php
    render_footer();
    exit;
}

if ($page === 'invoices') {
    require_login();
    $rows = $pdo->query("
        SELECT o.*, m.name AS member_name, a.full_name AS admin_name
        FROM orders o
        LEFT JOIN members m ON m.id = o.member_id
        LEFT JOIN users a ON a.id = o.created_by
        ORDER BY o.created_at DESC
    ")->fetchAll();
    render_header('Invoice');
    ?>
    <div class="card border-0 rounded-4 shadow-sm">
      <div class="card-body">
        <h4 class="fw-bold mb-3">Invoice & Cetak</h4>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>#</th><th>Tanggal</th><th>Pelanggan</th><th>Member</th><th>Admin</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr><td><?= (int)$r['id'] ?></td><td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td><td><?= e($r['customer_name']) ?></td><td><?= $r['member_name'] ? e($r['member_name']) : '-' ?></td><td><?= e($r['admin_name'] ?? '-') ?></td><td><?= money($r['total_fee']) ?></td><td><?= e($r['status']) ?></td><td><a class="btn btn-sm btn-outline-dark" href="?page=invoice&id=<?= (int)$r['id'] ?>">Lihat Invoice</a></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'invoice') {
    require_login();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT o.*, m.name AS member_name, m.balance AS member_balance, m.points AS member_points, m.discount_percent AS member_discount, d.full_name AS driver_name, a.full_name AS admin_name
        FROM orders o
        LEFT JOIN members m ON m.id = o.member_id
        LEFT JOIN users d ON d.id = o.driver_id
        LEFT JOIN users a ON a.id = o.created_by
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $o = $stmt->fetch();
    if (!$o) { echo "Invoice tidak ditemukan"; render_footer(); exit; }

    $waLink = '#';
    if (!empty($o['customer_phone'])) {
        $phone = preg_replace('/[^0-9]/', '', $o['customer_phone']);
        if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);
        $waText = "💙 EAZY DELIVERY\n\n📄 INVOICE #" . $o['id'] . "\nNama: " . $o['customer_name'] . "\nLayanan: " . $o['service_type'] . "\nTotal: " . money($o['total_fee']) . "\n\nJemput:\n" . $o['pickup_address'] . "\n\nAntar:\n" . $o['drop_address'] . "\n\n";
        if ($o['member_name']) $waText .= "--- INFO MEMBER ---\nNama: " . $o['member_name'] . "\nSaldo: " . money($o['member_balance']??0) . "\nPoin: " . (int)($o['member_points']??0) . "\nDiskon: " . (int)($o['member_discount']??0) . "%\n\n";
        $waText .= "Terima kasih telah menggunakan EAZY DELIVERY 🙏";
        $waLink = "https://wa.me/$phone?text=" . urlencode($waText);
    }

    render_header("Invoice #$id");
    ?>
    <div class="card border-0 rounded-4 shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start no-print mb-3">
          <div><h3 class="fw-bold mb-0"><?= APP_NAME ?></h3><div class="text-secondary">Invoice pesanan #<?= (int)$o['id'] ?> | <?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></div></div>
          <div class="d-flex gap-2"><a href="<?= e($waLink) ?>" target="_blank" class="btn btn-success">Kirim WA Pelanggan</a><button class="btn btn-dark" onclick="window.print()">Cetak / PDF</button></div>
        </div>
        <div class="row g-3">
          <div class="col-md-6"><h5 class="fw-bold">Data Pelanggan</h5><div>Nama: <?= e($o['customer_name']) ?></div><div>WA: <?= e($o['customer_phone']) ?></div><div>Member: <?= $o['member_name'] ? e($o['member_name']) : 'Bukan member' ?></div></div>
          <div class="col-md-6"><h5 class="fw-bold">Detail Order</h5><div>Admin: <?= e($o['admin_name'] ?? '-') ?></div><div>Layanan: <?= e($o['service_type']) ?></div><div>Driver: <?= $o['driver_name'] ? e($o['driver_name']) : '-' ?></div><div>Status: <?= e($o['status']) ?></div></div>
        </div>
        <hr>
        <table class="table">
          <tr><td>Tarif dasar (Rp15.000)</td><td><?= money($o['base_fee']) ?></td></tr>
          <tr><td>Tambah titik (Rp5.000 x <?= (int)$o['extra_stops'] ?>)</td><td><?= money($o['extra_fee']) ?></td></tr>
          <tr><td>Diskon member (<?= (int)$o['member_discount'] ?>%)</td><td>- <?= money($o['member_discount'] > 0 ? round(($o['base_fee']+$o['extra_fee'])*($o['member_discount']/100)) : 0) ?></td></tr>
          <tr class="fw-bold"><td>Total</td><td><?= money($o['total_fee']) ?></td></tr>
        </table>
        <div class="mt-3"><div class="fw-bold">Alamat Jemput</div><div><?= e($o['pickup_address']) ?></div><div class="fw-bold mt-2">Alamat Antar</div><div><?= e($o['drop_address']) ?></div><?php if (!empty($o['notes'])): ?><div class="fw-bold mt-2">Catatan</div><div><?= e($o['notes']) ?></div><?php endif; ?></div>
        <?php if ($o['member_name']): ?><div class="alert alert-info mt-4"><strong>Info Member:</strong> Saldo <?= money($o['member_balance']??0) ?> | Poin <?= (int)($o['member_points']??0) ?> | Diskon <?= (int)($o['member_discount']??0) ?>%</div><?php endif; ?>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'settings') {
    require_login();
    if (!is_admin()) { flash_set('warning','Halaman ini khusus admin.'); redirect('?page=dashboard'); }
    $companyName = get_setting($pdo, 'company_name', APP_NAME);
    $companyDiscount = (int)get_setting($pdo, 'company_discount_percent', '0');
    $pointRules = get_setting($pdo, 'point_discount_rules', '');
    render_header('Setting');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <div class="card border-0 rounded-4 shadow-sm">
      <div class="card-body">
        <h4 class="fw-bold mb-3">Pengaturan Perusahaan</h4>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_company_setting">
          <div class="col-md-6"><label class="form-label">Nama Usaha</label><input class="form-control" name="company_name" value="<?= e($companyName) ?>"></div>
          <div class="col-md-6"><label class="form-label">Diskon Member Default (%)</label><input type="number" class="form-control" name="company_discount_percent" value="<?= (int)$companyDiscount ?>" min="0" max="100"></div>
          <div class="col-12">
            <label class="form-label">Aturan Diskon Berdasarkan Poin</label>
            <textarea class="form-control" name="point_discount_rules" rows="3" placeholder="Format: poin:diskon,poin:diskon&#10;Contoh: 100:5,200:10,500:20"><?= e($pointRules) ?></textarea>
            <div class="form-text">Kosongkan jika tidak ingin menggunakan diskon poin. Diskon yang diterapkan adalah yang tertinggi antara diskon bawaan member dan diskon dari aturan poin ini.</div>
          </div>
          <div class="col-12"><button class="btn btn-dark">Simpan Pengaturan</button></div>
        </form>
        <hr><div class="small text-secondary">Untuk invoice dan laporan PDF, gunakan tombol <b>Cetak / PDF</b> lalu simpan sebagai PDF dari browser.</div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

if ($page === 'driver') {
    require_login();
    if (!is_driver() && !is_admin()) redirect('?page=dashboard');
    $uid = (int)$me['id'];
    $status = driver_week_status($pdo, $uid);
    $todayCompleted = driver_today_completed($pdo, $uid);
    $todayBonus = driver_today_bonus($pdo, $uid);
    $hasActiveOrder = driver_has_active_order($pdo, $uid);
    if (is_driver()) {
        $stmt = $pdo->prepare("SELECT o.*, m.name AS member_name, m.balance AS member_balance, m.points AS member_points, m.discount_percent AS member_discount FROM orders o LEFT JOIN members m ON m.id = o.member_id WHERE (o.driver_id IS NULL OR o.driver_id = ?) AND o.status NOT IN ('completed','rejected') ORDER BY o.is_priority DESC, o.created_at ASC");
        $stmt->execute([$uid]);
    } else {
        $stmt = $pdo->query("SELECT o.*, m.name AS member_name, m.balance AS member_balance, m.points AS member_points, m.discount_percent AS member_discount FROM orders o LEFT JOIN members m ON m.id = o.member_id WHERE o.status NOT IN ('completed','rejected') ORDER BY o.is_priority DESC, o.created_at DESC");
    }
    $orders = $stmt->fetchAll();
    render_header('Area Driver');
    ?>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div><?php endif; ?>
    <div class="row g-3">
      <div class="col-md-4"><div class="card card-stat p-3"><div class="text-secondary">Target Harian</div><div class="fs-3 fw-bold"><?= $todayCompleted ?>/20</div><div class="small text-secondary">Jika lewat 20 order, bonus Rp 1.000/order</div></div></div>
      <div class="col-md-4"><div class="card card-stat p-3"><div class="text-secondary">Bonus Hari Ini</div><div class="fs-3 fw-bold"><?= money($todayBonus) ?></div></div></div>
      <div class="col-md-4"><div class="card card-stat p-3"><div class="text-secondary">Status Minggu Ini</div><div class="fs-4 fw-bold"><span class="badge bg-<?= e($status['badge']) ?>"><?= e($status['label']) ?></span></div><div class="small text-secondary"><?= $status['count'] ?> penolakan</div></div></div>
    </div>
    <?php if ($hasActiveOrder && is_driver()): ?>
    <div class="alert alert-warning mt-3">
      ⚠️ Anda sedang mengerjakan order lain. Selesaikan terlebih dahulu sebelum mengambil order baru.
    </div>
    <?php endif; ?>
    <div class="card border-0 rounded-4 shadow-sm mt-4">
      <div class="card-body">
        <h4 class="fw-bold mb-3">Pesanan Driver</h4>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>#</th><th>Tanggal</th><th>Pelanggan</th><th>Member</th><th>Layanan</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
              <tr class="<?= $o['is_priority'] ? 'table-warning' : '' ?>">
                <td><?= (int)$o['id'] ?></td>
                <td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                <td><?= e($o['customer_name']) ?></td>
                <td><?= $o['member_name'] ? e($o['member_name']) : '-' ?></td>
                <td><?= e($o['service_type']) ?></td>
                <td><span class="badge bg-<?= e(order_status_badge($o['status'])) ?>"><?= e($o['status']) ?></span></td>
                <td class="d-flex gap-1 flex-wrap">
                  <?php if ($hasActiveOrder && is_driver()): ?>
                    <button class="btn btn-sm btn-primary" disabled title="Selesaikan order aktif dulu">Terima</button>
                  <?php else: ?>
                    <form method="post"><input type="hidden" name="action" value="accept_order"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm btn-primary" <?= $status['blocked'] ? 'disabled' : '' ?>>Terima</button></form>
                  <?php endif; ?>
                  <form method="post" class="d-flex gap-1"><input type="hidden" name="action" value="reject_order"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="text" class="form-control form-control-sm" name="reason" placeholder="Alasan" style="width:160px"><button class="btn btn-sm btn-warning" <?= $status['blocked'] ? 'disabled' : '' ?>>Tolak</button></form>
                  <form method="post"><input type="hidden" name="action" value="complete_order"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm btn-success" <?= $status['blocked'] ? 'disabled' : '' ?>>Selesai</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    render_footer();
    exit;
}

render_header('404');
echo '<div class="alert alert-danger">Halaman tidak ditemukan.</div>';
render_footer();