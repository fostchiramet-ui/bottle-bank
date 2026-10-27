<?php
session_start();

// นำเข้าการเชื่อมต่อฐานข้อมูล (กรุณาปรับ path ให้ตรงกับตำแหน่งไฟล์ db.php ของคุณ)
require_once 'db/db.php';

$total_bottles = 0;
$total_students = 0;
$total_rewards = 0;

try {
    $pdo = Database::connect();
    
    // ดึงข้อมูลสถิติจริงจากฐานข้อมูล
    $stmt = $pdo->query("SELECT SUM(quantity) FROM bottle_entries");
    $total_bottles = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT student_id) FROM bottle_entries");
    $total_students = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM redemptions WHERE status = 'approved'");
    $total_rewards = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {
    // หากเกิด Error จะปล่อยให้ค่าเริ่มต้นเป็น 0
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoBank - ธนาคารขยะวัดสุทธิวราราม</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* [แก้ไข] บังคับให้การ์ด Stack ไม่ค้างทับเนื้อหาส่วนอื่น (เผื่อ CSS เดิมยังติด Cache) */
        .stack-card {
            position: relative !important;
            top: auto !important;
        }

        /* ปรับแต่ง Hamburger Menu ให้สไลด์จากบนลงล่าง (Top to Bottom) */
       
    </style>
</head>
<body>  

    <nav class="navbar">
        <div class="container nav-content">
            <a href="#" class="logo">
                <img src="img/suthi01.png" alt="EcoBank Logo" height="40">
                <div class="logo-text">
                    <span class="txt-green">Eco</span><span class="txt-pink">Bank</span>
                    <small>วัดสุทธิวราราม</small>
                </div>
            </a>
            <div class="nav-menu">
                <div class="nav-links">
                    <a href="index.php">หน้าหลัก</a>
                    <a href="#how-it-works">วิธีการใช้งาน</a>
                    <a href="#stats">ตรวจสอบแต้ม</a>
                    <a href="#about">เกี่ยวกับเรา</a>
                </div>
                <div class="nav-auth">
                    <a href="login.php" class="btn-pink">เข้าสู่ระบบ</a>
                </div>
            </div>
            <button class="hamburger" id="hamburger-menu" aria-label="Menu">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </button>
        </div>
    </nav>

    <section id="hero" class="hero">
        <div class="container hero-layout">
            <div class="hero-text">
                <div class="badge">ธนาคารขยะโรงเรียนวัดสุทธิวราราม</div>
                <h1>เปลี่ยนขยะ<br><span class="txt-pink">เป็นแต้ม</span><br><span class="txt-green">เปลี่ยนโลกให้ยั่งยืน</span></h1>
                <p>ร่วมเป็นส่วนหนึ่งของการเปลี่ยนแปลง ฝากขยะรีไซเคิล สะสมแต้ม แลกรางวัล และสร้างโลกที่ดีขึ้นไปด้วยกัน</p>
                <div class="hero-actions">
                    <a href="login.php" class="btn-pink btn-large btn-pulse">เริ่มต้นฝากขวด <i class="fas fa-arrow-right"></i></a>
                    <a href="#about" class="btn-outline btn-large">เรียนรู้เพิ่มเติม</a>
                </div>
            </div>
        </div> <!-- [แก้ไข] เพิ่ม </div> ที่ตกหล่นตรงนี้ ซึ่งเป็นตัวการทำให้หน้าเว็บค้าง -->
    </section>

    <section id="how-it-works" class="how-it-works">
        <div class="container text-center reveal">
            <span class="badge-pink">วิธีการใช้งาน</span>
            <h2>เริ่มต้นง่ายๆ แค่ 4 ขั้นตอน</h2>
            <p class="subtitle">ร่วมเป็นส่วนหนึ่งของการเปลี่ยนแปลงสิ่งแวดล้อม ด้วยขั้นตอนที่ง่ายดาย</p>
        </div>
        
        <div class="container stack-container">
            <div class="stack-card card-gradient-green reveal">
                <div class="step-bg txt-green">01</div>
                <div class="card-content">
                    <div class="icon-box bg-green txt-green"><i class="fas fa-recycle"></i></div>
                    <h3>นำขยะมาฝาก</h3>
                    <p>นำขวดพลาสติก กระป๋อง หรือขยะรีไซเคิลมาฝากที่จุดรับฝากของโรงเรียน ง่ายๆ แค่เดินมาฝาก</p>
                </div>
            </div>
            
            <div class="stack-card card-gradient-pink reveal">
                <div class="step-bg txt-pink">02</div>
                <div class="card-content">
                    <div class="icon-box bg-pink txt-pink"><i class="fas fa-coins"></i></div>
                    <h3>สะสมแต้ม/เงิน</h3>
                    <p>ทุกครั้งที่ฝากขยะ คุณจะได้รับแต้มสะสมตามน้ำหนักและประเภทของขยะ ยิ่งฝากมากยิ่งได้มาก</p>
                </div>
            </div>

            <div class="stack-card card-gradient-amber reveal">
                <div class="step-bg txt-amber">03</div>
                <div class="card-content">
                    <div class="icon-box bg-amber txt-amber"><i class="fas fa-gift"></i></div>
                    <h3>แลกรางวัล/คะแนนจิตพิสัย</h3>
                    <p>นำแต้มที่สะสมไปแลกรางวัลพิเศษ หรือแลกเป็นคะแนนจิตพิสัย สร้างแรงจูงใจในการทำดี</p>
                </div>
            </div>
            
             <div class="stack-card card-gradient-emerald reveal">
                <div class="step-bg txt-emerald">04</div>
                <div class="card-content">
                    <div class="icon-box bg-emerald txt-emerald"><i class="fas fa-tree"></i></div>
                    <h3>ช่วยเหลือสิ่งแวดล้อม</h3>
                    <p>ทุกขวดที่คุณฝาก คือการช่วยลดขยะ ลดมลพิษ และสร้างโลกที่สะอาดขึ้นสำหรับคนรุ่นต่อไป</p>
                </div>
            </div>
        </div>
    </section>

    <section id="stats" class="stats-section">
        <div class="container">
            <div class="text-center reveal">
                <span class="badge-green">ผลกระทบของเรา</span>
                <h2>ตัวเลขที่พิสูจน์ <span class="txt-pink">ความสำเร็จ</span></h2>
            </div>
            <div class="stats-grid">
                <div class="stat-box reveal" style="transition-delay: 0.1s;">
                    <div class="icon-box bg-green txt-green mx-auto"><i class="fas fa-leaf"></i></div>
                    <h3 class="txt-dark"><?php echo number_format($total_bottles); ?>+</h3>
                    <p>ขวดที่รีไซเคิลแล้ว</p>
                </div>
                <div class="stat-box reveal" style="transition-delay: 0.2s;">
                    <div class="icon-box bg-pink txt-pink mx-auto"><i class="fas fa-users"></i></div>
                    <h3 class="txt-dark"><?php echo number_format($total_students); ?>+</h3>
                    <p>นักเรียนร่วมโครงการ</p>
                </div>
                <div class="stat-box reveal" style="transition-delay: 0.3s;">
                    <div class="icon-box bg-amber txt-amber mx-auto"><i class="fas fa-trophy"></i></div>
                    <h3 class="txt-dark"><?php echo number_format($total_rewards); ?></h3>
                    <p>รางวัลที่แลกแล้ว</p>
                </div>
                <div class="stat-box reveal" style="transition-delay: 0.4s;">
                    <div class="icon-box bg-emerald txt-emerald mx-auto"><i class="fas fa-globe"></i></div>
                    <h3 class="txt-dark"><?php echo number_format($total_bottles * 0.02, 1); ?> kg</h3>
                    <p>ขยะที่ลดลง</p>
                </div>
            </div>
        </div> <!-- [แก้ไข] เอา </div> ที่เกินออก 1 อันเพื่อไม่ให้ Grid พัง -->
    </section>

    <section id="about" class="about">
        <div class="container about-layout">
            <div class="about-text reveal">
                <span class="badge-pink">เกี่ยวกับเรา</span>
                <h2>โครงการ <span class="txt-green">ธนาคารขยะ</span><br>โรงเรียนวัดสุทธิวราราม</h2>
                <p>Bottle Bank หรือ ธนาคารขยะ เป็นโครงการที่ริเริ่มโดยนักเรียน โรงเรียนวัดสุทธิวราราม เพื่อส่งเสริมการรีไซเคิลและสร้างจิตสำนึก ด้านสิ่งแวดล้อมให้กับเยาวชน ผ่านระบบสะสมแต้มและแลกรางวัล</p>
                <p>เราเชื่อว่าการเปลี่ยนแปลงเริ่มจากสิ่งเล็กๆ และทุกขวดที่คุณฝากคือก้าวสำคัญสู่โลกที่ดีกว่า</p>
            </div>
            <div class="about-values">
                <div class="value-card reveal" style="transition-delay: 0.1s;">
                    <div class="icon-box bg-pink txt-pink"><i class="fas fa-heart"></i></div>
                    <div>
                        <h4>รักษ์สิ่งแวดล้อม</h4>
                        <p>สร้างจิตสำนึกให้นักเรียนเห็นคุณค่าของการรีไซเคิลและการดูแลโลก</p>
                    </div>
                </div>
                <div class="value-card reveal" style="transition-delay: 0.2s;">
                    <div class="icon-box bg-green txt-green"><i class="fas fa-bullseye"></i></div>
                    <div>
                        <h4>เป้าหมายที่ชัดเจน</h4>
                        <p>ลดขยะในโรงเรียนให้เป็นศูนย์ และสร้างแรงบันดาลใจให้ชุมชนรอบข้าง</p>
                    </div>
                </div>
                <div class="value-card reveal" style="transition-delay: 0.3s;">
                    <div class="icon-box bg-amber txt-amber"><i class="fas fa-lightbulb"></i></div>
                    <div>
                        <h4>นวัตกรรมเพื่อสังคม</h4>
                        <p>ใช้เทคโนโลยีสร้างระบบจัดการขยะที่ทันสมัยและเข้าถึงง่าย</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container footer-layout">
            <div class="footer-brand">
                <div class="f-logo">
                    <div class="f-icon"><i class="fas fa-leaf"></i></div>
                    EcoBank
                </div>
                <p>ธนาคารขยะโรงเรียนวัดสุทธิวราราม — เปลี่ยนขยะเป็นแต้ม เปลี่ยนโลกให้ยั่งยืน</p>
            </div>
            <div class="footer-links">
                <h4>เมนูหลัก</h4>
                <a href="#hero">หน้าหลัก</a>
                <a href="#how-it-works">วิธีการใช้งาน</a>
                <a href="#stats">ตรวจสอบแต้ม</a>
                <a href="#about">เกี่ยวกับเรา</a>
            </div>
            <div class="footer-contact">
                <h4>ติดต่อเรา</h4>
                <p><i class="fas fa-map-marker-alt"></i> โรงเรียนวัดสุทธิวราราม เขตสาทร กรุงเทพฯ 10120</p>
                <p><i class="fas fa-phone"></i> 02-211-4042</p>
                <p><i class="fas fa-envelope"></i> ecobank@suthi.ac.th</p>
            </div>
            <div class="footer-social">
                <h4>ติดตามเรา</h4>
                <div class="social-icons">
                    <a href="#"><i class="fab fa-facebook"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                </div>
                <p class="dev-note">พัฒนาโดยนักเรียน ม.ปลาย<br>โรงเรียนวัดสุทธิวราราม</p>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="container bottom-content">
                <p>© 2026 EcoBank — ธนาคารขยะโรงเรียนวัดสุทธิวราราม. All rights reserved.</p>
                <p>Made with 💚 for a better planet</p>
            </div>
        </div>
    </footer>

    <script>
        const hamburger = document.getElementById('hamburger-menu');
        const navMenu = document.querySelector('.nav-menu');
        const navLinks = document.querySelectorAll('.nav-links a');

        // Toggle เมนูมือถือ
        if(hamburger) {
            hamburger.addEventListener('click', () => {
                hamburger.classList.toggle('active');
                navMenu.classList.toggle('active');
            });
        }

        // Close menu when clicking a link
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                hamburger.classList.remove('active');
                navMenu.classList.remove('active');
            });
        });

        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!hamburger.contains(e.target) && !navMenu.contains(e.target)) {
                hamburger.classList.remove('active');
                navMenu.classList.remove('active');
            }
        });

        // Navbar scroll effect
        const navbar = document.querySelector('.navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Scroll Reveal Animation
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                }
            });
        }, { threshold: 0.1 });

        const hiddenElements = document.querySelectorAll('.reveal');
        hiddenElements.forEach((el) => observer.observe(el));

        // [แก้ไข] ป้องกัน Error กรณีที่ไม่มีปุ่ม Theme Toggle ใน HTML
        const html = document.documentElement;
        const savedTheme = localStorage.getItem('theme') || 'light';
        html.setAttribute('data-theme', savedTheme);

        const themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) { // เช็คก่อนว่ามีปุ่มนี้ในระบบหรือไม่
            const themeIcon = themeToggle.querySelector('i');
            if (savedTheme === 'dark' && themeIcon) themeIcon.classList.replace('fa-moon', 'fa-sun');

            themeToggle.addEventListener('click', () => {
                const currentTheme = html.getAttribute('data-theme');
                const newTheme = currentTheme === 'light' ? 'dark' : 'light';
                
                html.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                
                if(themeIcon) {
                    themeIcon.classList.toggle('fa-moon');
                    themeIcon.classList.toggle('fa-sun');
                    themeIcon.style.transform = 'rotate(360deg)';
                    setTimeout(() => themeIcon.style.transform = '', 300);
                }
            });
        }
    </script>
</body>
</html>