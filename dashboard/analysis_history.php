<?php
require_once '../includes/config.php';
require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user']['id'];
$db = Database::getInstance();
$error = '';
$analyses = [];

// Pagination setup
$limit = 10; // Number of records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

try {
    // Get total number of analyses for pagination
    $total_stmt = $db->secureQuery(
        "SELECT COUNT(mr.id) 
         FROM match_results mr
         JOIN uploads u ON mr.upload_id = u.id
         WHERE u.user_id = :user_id",
        [':user_id' => $user_id]
    );
    $total_results = $total_stmt->fetchColumn();
    $total_pages = ceil($total_results / $limit);

    // Get analyses for the current page
    $stmt = $db->secureQuery(
        "SELECT mr.id, mr.overall_score, mr.analyzed_at, u.original_filename as resume_name, jd.title as job_title, jd.company as job_company
         FROM match_results mr
         JOIN uploads u ON mr.upload_id = u.id
         LEFT JOIN job_descriptions jd ON mr.job_id = jd.id
         WHERE u.user_id = :user_id
         ORDER BY mr.analyzed_at DESC
         LIMIT :limit OFFSET :offset",
        [':user_id' => $user_id, ':limit' => $limit, ':offset' => $offset]
    );
    $analyses = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error loading analysis history: " . $e->getMessage();
}

// Helper function to format score progress bar color
function getScoreColor($score) {
    if ($score >= 85) return 'bg-green-500';
    if ($score >= 70) return 'bg-sky-500';
    if ($score >= 50) return 'bg-yellow-500';
    return 'bg-red-500';
}
require_once '../templates/header.php';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analysis History | ResumeAI</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        #particle-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -10; }
        .glass-effect { background: rgba(17, 24, 39, 0.5); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-gray-900 text-gray-200 antialiased">
    <canvas id="particle-canvas"></canvas>
    
    <main class="pt-18 pb-16">
        <div class="container mx-auto px-6">
            <!-- PAGE HEADER -->
            <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
                <div>
                    <h1 class="text-4xl md:text-5xl font-extrabold tracking-tighter">Analysis <span class="bg-clip-text text-transparent bg-gradient-to-r from-indigo-400 to-pink-500">History</span></h1>
                    <p class="text-lg text-gray-400 mt-2">A complete log of all your past resume analyses.</p>
                </div>
                 <a href="dashboard.php" class="bg-gray-700 text-white px-4 py-2 font-semibold rounded-lg hover:bg-gray-600 transition-colors flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                </a>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-900/50 border-l-4 border-red-500 text-red-300 p-4 mb-6 rounded-r-lg" role="alert">
                    <p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <div class="glass-effect rounded-2xl shadow-2xl">
                <div class="p-6">
                    <?php if (empty($analyses)): ?>
                        <div class="text-center py-16">
                            <i class="fas fa-history fa-4x text-gray-600 mb-4"></i>
                            <h3 class="text-2xl font-bold text-white">No History Found</h3>
                            <p class="text-gray-400 mt-2">You haven't performed any analyses yet.</p>
                             <a href="analyze.php" class="mt-6 inline-block bg-indigo-500 text-white px-6 py-3 font-semibold rounded-lg hover:bg-indigo-600 shadow-md shadow-indigo-500/30 transition-all">
                                Run Your First Analysis
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($analyses as $analysis): ?>
                            <div class="bg-gray-800/50 p-4 rounded-lg hover:bg-gray-800 transition-colors">
                                <div class="flex items-center justify-between flex-wrap gap-4">
                                    <div>
                                        <p class="font-bold text-white text-lg"><?= htmlspecialchars($analysis['job_title'] ?? 'Custom Analysis') ?></p>
                                        <p class="text-sm text-gray-400">
                                            <i class="fas fa-file-alt mr-1 opacity-70"></i> <?= htmlspecialchars($analysis['resume_name']) ?> &bull; <i class="far fa-calendar-alt ml-2 mr-1 opacity-70"></i> <?= date('M j, Y, g:i a', strtotime($analysis['analyzed_at'])) ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center space-x-4">
                                        <div class="text-right">
                                            <p class="font-bold text-3xl text-white"><?= number_format($analysis['overall_score'], 0) ?>%</p>
                                            <p class="text-xs text-gray-400">Match Score</p>
                                        </div>
                                        <a href="analysis_result.php?id=<?= (int)$analysis['id'] ?>" class="bg-indigo-500 text-white px-5 py-2.5 font-semibold rounded-lg hover:bg-indigo-600 transition-colors">View Report</a>
                                    </div>
                                </div>
                                <div class="w-full bg-gray-700 rounded-full h-2 mt-3">
                                    <div class="<?= getScoreColor($analysis['overall_score']) ?> h-2 rounded-full" style="width: <?= round($analysis['overall_score']) ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>
                <div class="border-t border-gray-700/50 p-4 flex justify-center items-center space-x-2">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>" class="<?= $i == $page ? 'bg-indigo-500 text-white' : 'bg-gray-700 hover:bg-gray-600' ?> h-10 w-10 flex items-center justify-center font-bold rounded-lg transition-colors"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <script>
         document.addEventListener('DOMContentLoaded', () => {
        // --- START: MOBILE MENU SCRIPT ---
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const closeMenuButton = document.getElementById('close-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');

        if (mobileMenuButton && mobileMenu && closeMenuButton) {
            mobileMenuButton.addEventListener('click', () => {
                mobileMenu.classList.remove('-translate-x-full');
            });

            closeMenuButton.addEventListener('click', () => {
                mobileMenu.classList.add('-translate-x-full');
            });
        }
        // --- END: MOBILE MENU SCRIPT ---
    // Particle background script
    
        const canvas = document.getElementById('particle-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let particles = [];
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        function getThemeColors() { return { particleColor: 'rgba(255, 255, 255, 0.7)', lineColor: 'rgba(255, 255, 255, 0.1)' }; }
        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width; this.y = Math.random() * canvas.height;
                this.vx = (Math.random() - 0.5) * 0.5; this.vy = (Math.random() - 0.5) * 0.5;
                this.radius = Math.random() * 1.5 + 1;
            }
            update() {
                this.x += this.vx; this.y += this.vy;
                if (this.x < 0 || this.x > canvas.width) this.vx *= -1;
                if (this.y < 0 || this.y > canvas.height) this.vy *= -1;
            }
            draw() {
                ctx.beginPath(); ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
                ctx.fillStyle = getThemeColors().particleColor; ctx.fill();
            }
        }
        function initParticles() {
            particles = [];
            const particleCount = Math.floor((canvas.width * canvas.height) / 15000);
            for (let i = 0; i < particleCount; i++) { particles.push(new Particle()); }
        }
        function connectParticles() {
            const colors = getThemeColors();
            for (let i = 0; i < particles.length; i++) {
                for (let j = i; j < particles.length; j++) {
                    const distance = Math.sqrt(Math.pow(particles[i].x - particles[j].x, 2) + Math.pow(particles[i].y - particles[j].y, 2));
                    if (distance < 120) {
                        ctx.beginPath(); ctx.strokeStyle = colors.lineColor; ctx.lineWidth = 0.5;
                        ctx.moveTo(particles[i].x, particles[i].y); ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.stroke();
                    }
                }
            }
        }
        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            particles.forEach(p => { p.update(); p.draw(); });
            connectParticles();
            requestAnimationFrame(animate);
        }
        setCanvasSize(); initParticles(); animate();
        window.addEventListener('resize', () => { setCanvasSize(); initParticles(); });
    });
    
    </script>
</body>
</html>
