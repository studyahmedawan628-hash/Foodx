<?php
require_once 'db.php';
function getMenuItems($category = null, $available_only = false) {
    global $pdo;
    
    $sql = "SELECT * FROM menu_items";
    $conditions = [];
    $params = [];
    
    if ($category) {
        $conditions[] = "category = ?";
        $params[] = $category;
    }
    
    if ($available_only) {
        $conditions[] = "is_available = 1";
    }
    
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    
    $sql .= " ORDER BY category, name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll();
}

function getCategories() {
    global $pdo;
    $stmt = $pdo->query("SELECT DISTINCT category FROM menu_items WHERE is_available = 1 ORDER BY category");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getMenuItem($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function addMenuItem($data) {
    global $pdo;
    
    $sql = "INSERT INTO menu_items (name, description, price, category, image, is_available) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        $data['name'],
        $data['description'],
        $data['price'],
        $data['category'],
        $data['image'] ?? 'default.jpg',
        $data['is_available'] ?? 1
    ]);
}

function updateMenuItem($id, $data) {
    global $pdo;
    
    $sql = "UPDATE menu_items SET 
            name = ?, 
            description = ?, 
            price = ?, 
            category = ?, 
            image = ?, 
            is_available = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        $data['name'],
        $data['description'],
        $data['price'],
        $data['category'],
        $data['image'],
        $data['is_available'],
        $id
    ]);
}

function deleteMenuItem($id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
    return $stmt->execute([$id]);
}

function placeOrder($customerData, $cartItems) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Generate order number: ORD + YYYYMMDD + random 4 digits
        $datePart = date('Ymd');
        $randomPart = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $orderNumber = 'ORD' . $datePart . $randomPart;
        
        // Calculate totals
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }
        $tax = $subtotal * 0.1; // 10% tax
        $total = $subtotal + $tax;
        
        // Insert order
        $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, customer_address, subtotal, tax, total_amount, notes) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $orderNumber,
            $customerData['name'],
            $customerData['email'],
            $customerData['phone'],
            $customerData['address'],
            $subtotal,
            $tax,
            $total,
            $customerData['notes'] ?? ''
        ]);
        
        $orderId = $pdo->lastInsertId();
        
        // Insert order items
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, price, total_price) 
                               VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($cartItems as $item) {
            $totalPrice = $item['price'] * $item['quantity'];
            $stmt->execute([
                $orderId,
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['price'],
                $totalPrice
            ]);
        }
        
        $pdo->commit();
        return ['success' => true, 'order_id' => $orderId, 'order_number' => $orderNumber];
        
    } catch(Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
// Add these functions to your existing functions.php file

function getOrder($orderId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    return $stmt->fetch();
}

function getOrderItems($orderId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT oi.*, mi.image 
        FROM order_items oi 
        LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id 
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

function getAllOrders($limit = null, $status = null) {
    global $pdo;
    
    $sql = "SELECT * FROM orders";
    $params = [];
    
    if ($status) {
        $sql .= " WHERE status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY order_date DESC";
    
    if ($limit) {
        $sql .= " LIMIT " . (int)$limit;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function updateOrderStatus($orderId, $status) {
    global $pdo;
    
    $validStatuses = ['pending', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        return false;
    }
    
    $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    return $stmt->execute([$status, $orderId]);
}

function searchOrders($searchTerm) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE order_number LIKE ? 
        OR customer_name LIKE ? 
        OR customer_email LIKE ? 
        OR customer_phone LIKE ?
        ORDER BY order_date DESC
    ");
    $searchTerm = "%$searchTerm%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    return $stmt->fetchAll();
}

function getDashboardStats() {
    global $pdo;
    
    $stats = [];
    
    // Total orders
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
    $stats['total_orders'] = $stmt->fetch()['total'];
    
    // Pending orders
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE status = 'pending'");
    $stats['pending_orders'] = $stmt->fetch()['total'];
    
    // Today's orders
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM orders WHERE DATE(order_date) = CURDATE()");
    $stats['today_orders'] = $stmt->fetch()['total'];
    
    // Total revenue
    $stmt = $pdo->query("SELECT SUM(total_amount) as total FROM orders WHERE status != 'cancelled'");
    $stats['total_revenue'] = $stmt->fetch()['total'] ?? 0;
    
    // Monthly revenue
    $stmt = $pdo->query("SELECT SUM(total_amount) as total FROM orders WHERE MONTH(order_date) = MONTH(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE()) AND status != 'cancelled'");
    $stats['monthly_revenue'] = $stmt->fetch()['total'] ?? 0;
    
    return $stats;
}
?>