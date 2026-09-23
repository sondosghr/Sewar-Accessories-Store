<?php
session_start();

// Database connection settings
$host = 'localhost';
$dbname = 'sewar';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables if they don't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        is_admin TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        size VARCHAR(50),
        image VARCHAR(255) DEFAULT 'img.jpg',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        total_price DECIMAL(10, 2) NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        price DECIMAL(10, 2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id),
        FOREIGN KEY (product_id) REFERENCES products(id)
    )");
    
    
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create default admin account if it doesn't exist
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = 'admin@quradia.com'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 1)");
        $stmt->execute(['Admin', 'admin@quradia.com', $hashedPassword]);
    }
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['is_admin'] = $user['is_admin'];
        
        // Remember me
        if (isset($_POST['remember'])) {
            setcookie('user_id', $user['id'], time() + (86400 * 30), "/");
            setcookie('user_password', $user['password'], time() + (86400 * 30), "/");
        }
        
        header("Location: index.php?page=profile");
        exit;
    } else {
        $login_error = "Invalid email or password";
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    setcookie('user_id', '', time() - 3600, "/");
    setcookie('user_password', '', time() - 3600, "/");
    header("Location: index.php");
    exit;
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    try {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $password]);
        $register_success = "Registration successful! You can now login";
    } catch (PDOException $e) {
        $register_error = "Email already in use";
    }
}

// Handle adding product to cart
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = $_POST['product_id'];
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id] += $quantity;
    } else {
        $_SESSION['cart'][$product_id] = $quantity;
    }
    
    header("Location: index.php?page=products");
    exit;
}

// Handle updating cart
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_cart'])) {
    foreach ($_POST['quantities'] as $product_id => $quantity) {
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$product_id]);
        } else {
            $_SESSION['cart'][$product_id] = $quantity;
        }
    }
   
    header("Location: index.php?page=cart");
    exit;
}

// Handle checkout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['checkout'])) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php?page=login");
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $total_price = 0;
    
    // Calculate total price
    foreach ($_SESSION['cart'] as $product_id => $quantity) {
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        if ($product) {
            $total_price += $product['price'] * $quantity;
        }
    }
    
    // Create order
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total_price) VALUES (?, ?)");
    $stmt->execute([$user_id, $total_price]);
    $order_id = $pdo->lastInsertId();
    
    // Add products to order
    foreach ($_SESSION['cart'] as $product_id => $quantity) {
        $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if ($product) {
            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $product_id, $quantity, $product['price']]);
        }
    }
    
    // Clear cart
    unset($_SESSION['cart']);
    
    header("Location: index.php?page=profile");
    exit;
}



// Handle contact message
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['contact'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $subject = $_POST['subject'];
    $message = $_POST['message'];
    
    $stmt = $pdo->prepare("INSERT INTO messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $email, $subject, $message]);
    
    $contact_success = "Your message has been sent successfully! We will contact you soon";
}

// Handle admin operations
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    // Add new product
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $price = $_POST['price'];
        $size = $_POST['size'];
        
       
        
        $stmt = $pdo->prepare("INSERT INTO products (name, description, price, size, image) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $price, $size, $image]);
        
        $admin_success = "Product added successfully";
    }
    
    // Delete product
    if (isset($_GET['action']) && $_GET['action'] == 'delete_product' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header("Location: index.php?page=admin_products");
        exit;
    }
    
    // Update order status
    if (isset($_GET['action']) && $_GET['action'] == 'update_order_status' && isset($_GET['id']) && isset($_GET['status'])) {
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$_GET['status'], $_GET['id']]);
        header("Location: index.php?page=admin_orders");
        exit;
    }
    
   
    
    // Delete message
    if (isset($_GET['action']) && $_GET['action'] == 'delete_message' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header("Location: index.php?page=admin_messages");
        exit;
    }
}

// Determine current page
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سوار    </title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap">
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">
                <h1>سوار</h1>
            </div>
            <nav>
                <ul>
                    <li><a href="index.php?page=home">الرئيسية</a></li>
                    <li><a href="index.php?page=products">المنتجات</a></li>
                   
                    <li><a href="index.php?page=about">من نحن</a></li>
                    <li><a href="index.php?page=contact">تواصل معنا</a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li><a href="index.php?page=profile">الملف الشخصي</a></li>
                        <li><a href="index.php?action=logout">تسجيل الخروج</a></li>
                    <?php else: ?>
                        <li><a href="index.php?page=login">تسجيل الدخول</a></li>
                    <?php endif; ?>
                    <li><a href="index.php?page=cart">السلة (<?php echo isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0; ?>)</a></li>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1): ?>
                        <li><a href="index.php?page=admin">لوحة التحكم</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="search-box">
                <form action="index.php?page=products" method="get">
                    <input type="hidden" name="page" value="products">
                    <input type="text" name="search" placeholder="بحث عن منتج...">
                    <button type="submit">بحث</button>
                </form>
            </div>
        </div>
    </header>

    <main>
        <?php
        switch ($page) {
            case 'home':
                ?>
                <section class="hero">
                    <div class="container">
                        <h2>مرحباً بك في سوار</h2>
                        <p>متجر الاكسسوارات والحلي  </p>
                        <a href="index.php?page=products" class="btn">تصفح المنتجات</a>
                    </div>
                </section>
                <section class="sizes-section">
    <div class="container">
        <h2>بعض منتجاتنا</h2>

        <div class="sizes-grid">

            <div class="size-card">
                <img src="image/5.jpg" alt="اكسسوار">
                <h3>  منظم اكسسوارات</h3>
               
            </div>

            <div class="size-card">
                <img src="image/1.jpg" alt="اكسسوار">
                <h3>طقم</h3>
               
            </div>

            <div class="size-card">
                <img src="image/2.jpg" alt="اكسسوار">
                <h3>سوار</h3>
              
            </div>

            <div class="size-card">
                <img src="image/3.jpg" alt="اكسسوار">
                <h3>سلسال</h3>
               
            </div>

        </div>
    </div>
</section>
                <section class="featured-products">
                    <div class="container">
                        <h2>منتجاتنا المميزة</h2>
                        <div class="products-grid">
                            <?php
                            $stmt = $pdo->query("SELECT * FROM products ORDER BY created_at DESC LIMIT 6");
                            while ($product = $stmt->fetch()):
                            ?>
                            <div class="product-card">
                                <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                <h3><?php echo $product['name']; ?></h3>
                                <p class="size">الحجم: <?php echo $product['size']; ?></p>
                                <p class="price"><?php echo $product['price']; ?> دينار</p>
                                <form action="index.php" method="post">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <button type="submit" name="add_to_cart" class="btn">أضف للسلة</button>
                                </form>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </section>
                <?php
                break;
                
            case 'products':
                ?>
                <section class="products">
                    <div class="container">
                        <h2>جميع المنتجات</h2>
                        
                        <?php if (isset($login_error)): ?>
                            <div class="alert alert-danger"><?php echo $login_error; ?></div>
                        <?php endif; ?>
                        
                        <div class="products-grid">
                            <?php
                            if (isset($_GET['search']) && !empty($_GET['search'])) {
                                $search = $_GET['search'];
                                $stmt = $pdo->prepare("SELECT * FROM products WHERE name LIKE ? OR description LIKE ?");
                                $stmt->execute(["%$search%", "%$search%"]);
                            } else {
                                $stmt = $pdo->query("SELECT * FROM products ORDER BY created_at DESC");
                            }
                            
                            while ($product = $stmt->fetch()):
                            ?>
                            <div class="product-card">
                                <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                <h3><?php echo $product['name']; ?></h3>
                                <p class="size">الحجم: <?php echo $product['size']; ?></p>
                                <p class="price"><?php echo $product['price']; ?> دينار</p>
                                <form action="index.php" method="post">
                                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                    <button type="submit" name="add_to_cart" class="btn">أضف للسلة</button>
                                </form>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </section>
                <?php
                break;
                
            case 'cart':
                ?>
                <section class="cart">
                    <div class="container">
                        <h2>سلة المشتريات</h2>
                        
                        <?php if (empty($_SESSION['cart'])): ?>
                            <p>سلة المشتريات فارغة</p>
                        <?php else: ?>
                            <form action="index.php" method="post">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>المنتج</th>
                                            <th>السعر</th>
                                            <th>الكمية</th>
                                            <th>المجموع</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total = 0;
                                        foreach ($_SESSION['cart'] as $product_id => $quantity):
                                            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                                            $stmt->execute([$product_id]);
                                            $product = $stmt->fetch();
                                            
                                            if ($product):
                                                $subtotal = $product['price'] * $quantity;
                                                $total += $subtotal;
                                        ?>
                                        <tr>
                                            <td>
                                                <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                                <span><?php echo $product['name']; ?></span>
                                            </td>
                                            <td><?php echo $product['price']; ?> دينار</td>
                                            <td>
                                                <input type="number" name="quantities[<?php echo $product_id; ?>]" value="<?php echo $quantity; ?>" min="1">
                                            </td>
                                            <td><?php echo $subtotal; ?> دينار</td>
                                        </tr>
                                        <?php
                                            endif;
                                        endforeach;
                                        ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3">الإجمالي</td>
                                            <td><?php echo $total; ?> دينار</td>
                                        </tr>
                                    </tfoot>
                                </table>
                                
                                <div class="cart-actions">
                                    <button type="submit" name="update_cart" class="btn">تحديث السلة</button>
                                    <button type="submit" name="checkout" class="btn btn-primary">إتمام الطلب</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>
                <?php
                break;
                
           
                
            case 'about':
                ?>
                <section class="about">
                    <div class="container">
                        <h2>من نحن</h2>
                        <div class="about-content">
                            <div class="about-text">
                                <h3>من نحن:</h3>
                                <p> في سِـوار (Sewar)، نؤمن أن الإكسسوارات ليست مجرد قطع إضافية، بل هي اللمسة الساحرة التي تعبر عن شخصيتكِ وتكمل تفاصيل أناقتكِ.
                                بدأنا بشغف كبير لعالم الموضة والجمال، وحرصنا منذ اليوم الأول على تقديم تشكيلات فريدة وعصرية تجمع بين الرقي والبساطة، لتناسب كل من تبحث عن التميز في 
                                إطلالتها اليومية أو مناسباتها الخاصة.</p>
                                 <h3> رؤيتنا:</h3>
                               <p>
                                أن نكون الوجهة الأولى والملهمة في عالم الإكسسوارات، وأن نضفي لمسة من الثقة والأناقة على مظهركِ في كل وقت</p>
                                  <h3>ما الذي يميزنا ؟</h3>
                              <p> جودة نثق بها: نختار قطعنا بعناية فائقة لضمان الجودة والاستدامة التي تليق بكِ.
                                تصاميم عصرية: نسعى دائماً لتوفير أحدث الصيحات والتصاميم المميزة التي تُرضي جميع الأذواق.
                                اهتمام بالتفاصيل: نهتم بأدق التفاصيل، من اختيار القطعة وحتى وصولها إليكِ بتغليف فخم وأنيق.</p>
                            </div>
                            <div class="about-image">
                                <img src="image/2.jpg" alt="من نحن">
                            </div>
                        </div>
                    </div>
                </section>
                <?php
                break;
                
            case 'contact':
                ?>
                <section class="contact">
                    <div class="container">
                        <h2>تواصل معنا</h2>
                        
                        <?php if (isset($contact_success)): ?>
                            <div class="alert alert-success"><?php echo $contact_success; ?></div>
                        <?php endif; ?>
                        
                        <div class="contact-content">
                            <div class="contact-info">
                                <h3>معلومات الاتصال</h3>
                                <p>العنوان:  ليبيا، طرابلس</p>
                                <p>الهاتف: +218 0915567799   </p>
                                <p>البريد الإلكتروني: sewar@gmail.com</p>
                            </div>
                            
                            <form action="index.php" method="post">
                                <div class="form-group">
                                    <label for="name">الاسم</label>
                                    <input type="text" id="name" name="name" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email">البريد الإلكتروني</label>
                                    <input type="email" id="email" name="email" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="subject">الموضوع</label>
                                    <input type="text" id="subject" name="subject" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="message">الرسالة</label>
                                    <textarea id="message" name="message" required></textarea>
                                </div>
                                
                                <button type="submit" name="contact" class="btn btn-primary">إرسال الرسالة</button>
                            </form>
                        </div>
                    </div>
                </section>
                <?php
                break;
                
            case 'login':
                ?>
                <section class="login">
                    <div class="container">
                        <h2>تسجيل الدخول</h2>
                        
                        <?php if (isset($login_error)): ?>
                            <div class="alert alert-danger"><?php echo $login_error; ?></div>
                        <?php endif; ?>
                        
                        <form action="index.php" method="post">
                            <div class="form-group">
                                <label for="email">البريد الإلكتروني</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">كلمة المرور</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="remember"> تذكرني
                                </label>
                            </div>
                            
                            <button type="submit" name="login" class="btn btn-primary">تسجيل الدخول</button>
                        </form>
                        
                        <p>ليس لديك حساب؟ <a href="index.php?page=register">إنشاء حساب جديد</a></p>
                    </div>
                </section>
                <?php
                break;
                
            case 'register':
                ?>
                <section class="register">
                    <div class="container">
                        <h2>إنشاء حساب جديد</h2>
                        
                        <?php if (isset($register_success)): ?>
                            <div class="alert alert-success"><?php echo $register_success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($register_error)): ?>
                            <div class="alert alert-danger"><?php echo $register_error; ?></div>
                        <?php endif; ?>
                        
                        <form action="index.php" method="post">
                            <div class="form-group">
                                <label for="name">الاسم</label>
                                <input type="text" id="name" name="name" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">البريد الإلكتروني</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">كلمة المرور</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">تأكيد كلمة المرور</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <button type="submit" name="register" class="btn btn-primary">إنشاء الحساب</button>
                        </form>
                        
                        <p>لديك حساب بالفعل؟ <a href="index.php?page=login">تسجيل الدخول</a></p>
                    </div>
                </section>
                <?php
                break;
                
            case 'profile':
                ?>
                <section class="profile">
                    <div class="container">
                        <h2>الملف الشخصي</h2>
                        
                        <div class="profile-info">
                            <h3>معلومات المستخدم</h3>
                            <p>الاسم: <?php echo $_SESSION['user_name']; ?></p>
                        </div>
                        
                        <div class="profile-orders">
                            <h3>طلباتي</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>التاريخ</th>
                                        <th>الإجمالي</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
                                    $stmt->execute([$_SESSION['user_id']]);
                                    
                                    while ($order = $stmt->fetch()):
                                    ?>
                                    <tr>
                                        <td><?php echo $order['id']; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                                        <td><?php echo $order['total_price']; ?> دينار</td>
                                        <td><?php echo $order['status']; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        
                </section>
                <?php
                break;
                
            case 'admin':
                ?>
                <section class="admin">
                    <div class="container">
                        <h2>لوحة التحكم</h2>
                        
                        <div class="admin-tabs">
                            <button class="active" data-tab="admin-products">المنتجات</button>
                            <button data-tab="admin-users">المستخدمين</button>
                            <button data-tab="admin-orders">الطلبات</button>
                           
                            <button data-tab="admin-messages">الرسائل</button>
                        </div>
                        
                        <div id="admin-products" class="admin-tab-content active">
                            <h3>إدارة المنتجات</h3>
                            
                            <?php if (isset($admin_success)): ?>
                                <div class="alert alert-success"><?php echo $admin_success; ?></div>
                            <?php endif; ?>
                            
                            <form action="index.php" method="post" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label for="name">اسم المنتج</label>
                                    <input type="text" id="name" name="name" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="description">وصف المنتج</label>
                                    <textarea id="description" name="description"></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="price">السعر</label>
                                    <input type="number" id="price" name="price" step="0.01" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="size">الحجم</label>
                                    <input type="text" id="size" name="size">
                                </div>
                                
                                <div class="form-group">
                                    <label for="image">صورة المنتج</label>
                                    <input type="file" id="image" name="image">
                                </div>
                                
                                <button type="submit" name="add_product" class="btn btn-primary">إضافة المنتج</button>
                            </form>
                            
                            <h3>قائمة المنتجات</h3>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>الصورة</th>
                                        <th>الاسم</th>
                                        <th>السعر</th>
                                        <th>الحجم</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("SELECT * FROM products ORDER BY created_at DESC");
                                    
                                    while ($product = $stmt->fetch()):
                                    ?>
                                    <tr>
                                        <td><img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>"></td>
                                        <td><?php echo $product['name']; ?></td>
                                        <td><?php echo $product['price']; ?> دينار</td>
                                        <td><?php echo $product['size']; ?></td>
                                        <td>
                                            <a href="index.php?action=delete_product&id=<?php echo $product['id']; ?>" class="btn delete-btn">حذف</a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div id="admin-users" class="admin-tab-content">
                            <h3>إدارة المستخدمين</h3>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>المعرف</th>
                                        <th>الاسم</th>
                                        <th>البريد الإلكتروني</th>
                                        <th>تاريخ التسجيل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
                                    
                                    while ($user = $stmt->fetch()):
                                    ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td><?php echo $user['name']; ?></td>
                                        <td><?php echo $user['email']; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div id="admin-orders" class="admin-tab-content">
                            <h3>إدارة الطلبات</h3>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>رقم الطلب</th>
                                        <th>المستخدم</th>
                                        <th>الإجمالي</th>
                                        <th>التاريخ</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("SELECT o.*, u.name as user_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC");
                                    
                                    while ($order = $stmt->fetch()):
                                    ?>
                                    <tr>
                                        <td><?php echo $order['id']; ?></td>
                                        <td><?php echo $order['user_name']; ?></td>
                                        <td><?php echo $order['total_price']; ?> دينار</td>
                                        <td><?php echo date('Y-m-d', strtotime($order['created_at'])); ?></td>
                                        <td><?php echo $order['status']; ?></td>
                                        <td>
                                            <a href="index.php?action=update_order_status&id=<?php echo $order['id']; ?>&status=pending" class="btn">معلق</a>
                                            <a href="index.php?action=update_order_status&id=<?php echo $order['id']; ?>&status=processing" class="btn">قيد المعالجة</a>
                                            <a href="index.php?action=update_order_status&id=<?php echo $order['id']; ?>&status=completed" class="btn">مكتمل</a>
                                            <a href="index.php?action=update_order_status&id=<?php echo $order['id']; ?>&status=cancelled" class="btn">ملغي</a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        
                        
                        <div id="admin-messages" class="admin-tab-content">
                            <h3>إدارة الرسائل</h3>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>المعرف</th>
                                        <th>الاسم</th>
                                        <th>البريد الإلكتروني</th>
                                        <th>الموضوع</th>
                                        <th>التاريخ</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query("SELECT * FROM messages ORDER BY created_at DESC");
                                    
                                    while ($message = $stmt->fetch()):
                                    ?>
                                    <tr>
                                        <td><?php echo $message['id']; ?></td>
                                        <td><?php echo $message['name']; ?></td>
                                        <td><?php echo $message['email']; ?></td>
                                        <td><?php echo $message['subject']; ?></td>
                                        <td><?php echo date('Y-m-d', strtotime($message['created_at'])); ?></td>
                                        <td>
                                            <a href="index.php?action=delete_message&id=<?php echo $message['id']; ?>" class="btn delete-btn">حذف</a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
                <?php
                break;
        }
        ?>
    </main>

    <footer>
        <div class="container">
            <p>جميع الحقوق محفوظة &copy; 2026 سوار</p>
        </div>
    </footer>

    <script src="script.js"></script>
</body>
</html>
