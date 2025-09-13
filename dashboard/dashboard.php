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
$chart_data_json = '[]';

// Get user statistics
try {
    // Stat Card 1: Resume Count
    $stmt = $db->secureQuery("SELECT COUNT(*) as resume_count FROM uploads WHERE user_id = :user_id", [':user_id' => $user_id]);
    $resumeStats = $stmt->fetch();
    
    // Stat Card 2: Analysis Count
    $stmt = $db->secureQuery("SELECT COUNT(*) as analysis_count FROM match_results mr JOIN uploads u ON mr.upload_id = u.id WHERE u.user_id = :user_id", [':user_id' => $user_id]);
    $analysisStats = $stmt->fetch();
    
    // Stat Card 3: Job Count
    $stmt = $db->secureQuery("SELECT COUNT(*) as job_count FROM job_descriptions WHERE user_id = :user_id", [':user_id' => $user_id]);
    $jobStats = $stmt->fetch();

    // NEW Stat Card 4: Average Score
    $stmt = $db->secureQuery("SELECT AVG(mr.overall_score) as avg_score FROM match_results mr JOIN uploads u ON mr.upload_id = u.id WHERE u.user_id = :user_id", [':user_id' => $user_id]);
    $avgScoreStats = $stmt->fetch();

    // Get recent analyses for the list
    $stmt = $db->secureQuery(
        "SELECT mr.id, mr.overall_score, mr.analyzed_at, u.original_filename as resume_name, jd.title as job_title
         FROM match_results mr
         JOIN uploads u ON mr.upload_id = u.id
         LEFT JOIN job_descriptions jd ON mr.job_id = jd.id
         WHERE u.user_id = :user_id
         ORDER BY mr.analyzed_at DESC
         LIMIT 3",
        [':user_id' => $user_id]
    );
    $recentAnalyses = $stmt->fetchAll();
    
    // Get recent resumes for the list
    $stmt = $db->secureQuery("SELECT id, original_filename FROM uploads WHERE user_id = :user_id ORDER BY upload_date DESC LIMIT 4", [':user_id' => $user_id]);
    $recentResumes = $stmt->fetchAll();

    // NEW: Get data for the performance chart (last 7 analyses)
    $stmt = $db->secureQuery(
        "SELECT mr.overall_score, jd.title as job_title, mr.analyzed_at
         FROM match_results mr
         JOIN uploads u ON mr.upload_id = u.id
         LEFT JOIN job_descriptions jd ON mr.job_id = jd.id
         WHERE u.user_id = :user_id
         ORDER BY mr.analyzed_at DESC
         LIMIT 7",
        [':user_id' => $user_id]
    );
    $chart_data = array_reverse($stmt->fetchAll()); // Reverse to show oldest to newest
    $chart_labels = array_map(fn($data) => date('M j', strtotime($data['analyzed_at'])), $chart_data);
    $chart_scores = array_map(fn($data) => $data['overall_score'], $chart_data);
    $chart_data_json = json_encode(['labels' => $chart_labels, 'scores' => $chart_scores]);
    
} catch (Exception $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
    $resumeStats = ['resume_count' => 0];
    $analysisStats = ['analysis_count' => 0];
    $jobStats = ['job_count' => 0];
    $avgScoreStats = ['avg_score' => 0];
    $recentAnalyses = [];
    $recentResumes = [];
}

// Helper function to format score progress bar
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
    <title>Dashboard | AI Resume Analyzer</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            <div class="mb-8">
                <h1 class="text-4xl md:text-5xl font-extrabold tracking-tighter">Welcome back, <span class="bg-clip-text text-transparent bg-gradient-to-r from-indigo-400 to-pink-500"><?= htmlspecialchars($_SESSION['user']['name']) ?>!</span></h1>
                <p class="text-lg text-gray-400 mt-2">Here's your performance overview. Ready to land that job?</p>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-900/50 border-l-4 border-red-500 text-red-300 p-4 mb-6 rounded-r-lg" role="alert">
                    <p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                <div class="glass-effect rounded-2xl p-6 flex items-center space-x-5 shadow-2xl">
                    <div class="flex-shrink-0 w-16 h-16 rounded-full bg-indigo-500/20 flex items-center justify-center"><i class="fas fa-file-alt text-3xl text-indigo-300"></i></div>
                    <div><p class="text-gray-400 text-sm font-medium">Resumes Uploaded</p><p class="text-3xl font-bold text-white"><?= (int)$resumeStats['resume_count'] ?></p></div>
                </div>
                 <div class="glass-effect rounded-2xl p-6 flex items-center space-x-5 shadow-2xl">
                    <div class="flex-shrink-0 w-16 h-16 rounded-full bg-green-500/20 flex items-center justify-center"><i class="fas fa-briefcase text-3xl text-green-300"></i></div>
                    <div><p class="text-gray-400 text-sm font-medium">Jobs Saved</p><p class="text-3xl font-bold text-white"><?= (int)$jobStats['job_count'] ?></p></div>
                </div>
                 <div class="glass-effect rounded-2xl p-6 flex items-center space-x-5 shadow-2xl">
                    <div class="flex-shrink-0 w-16 h-16 rounded-full bg-pink-500/20 flex items-center justify-center"><i class="fas fa-chart-line text-3xl text-pink-300"></i></div>
                    <div><p class="text-gray-400 text-sm font-medium">Analyses Ran</p><p class="text-3xl font-bold text-white"><?= (int)$analysisStats['analysis_count'] ?></p></div>
                </div>
                <div class="glass-effect rounded-2xl p-6 flex items-center space-x-5 shadow-2xl">
                    <div class="flex-shrink-0 w-16 h-16 rounded-full bg-sky-500/20 flex items-center justify-center"><i class="fas fa-star-half-alt text-3xl text-sky-300"></i></div>
                    <div><p class="text-gray-400 text-sm font-medium">Average Score</p><p class="text-3xl font-bold text-white"><?= number_format($avgScoreStats['avg_score'] ?? 0, 1) ?>%</p></div>
                </div>
            </div>

            <div class="mb-8">
                <div class="glass-effect rounded-2xl shadow-2xl p-6">
                    <h3 class="text-xl font-bold text-white mb-4">Performance Trend</h3>
                    <?php if (count($chart_data) > 1): ?>
                        <div class="h-80"><canvas id="performanceChart"></canvas></div>
                    <?php else: ?>
                        <div class="text-center py-16">
                            <i class="fas fa-chart-line fa-3x text-gray-500 mb-4"></i>
                            <h4 class="font-bold text-white">Not Enough Data</h4>
                            <p class="text-gray-400">Run at least two analyses to see your performance trend.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                <div class="xl:col-span-2">
                    <div class="glass-effect rounded-2xl shadow-2xl h-full">
                        <div class="p-6 border-b border-gray-700/50 flex justify-between items-center">
                            <h3 class="text-xl font-bold text-white">Recent Analyses</h3>
                            <a href="analysis_history.php" class="text-sm font-semibold text-indigo-400 hover:underline">View All</a>
                        </div>
                        <div class="p-6">
                            <?php if (count($recentAnalyses) > 0): ?>
                                <div class="space-y-4">
                                    <?php foreach ($recentAnalyses as $analysis): ?>
                                    <div class="bg-gray-800/50 p-4 rounded-lg">
                                        <div class="flex items-center justify-between flex-wrap gap-4">
                                            <div>
                                                <p class="font-bold text-white"><?= htmlspecialchars($analysis['job_title'] ?? 'Custom Analysis') ?></p>
                                                <p class="text-sm text-gray-400"><i class="fas fa-file-alt mr-1"></i> <?= htmlspecialchars($analysis['resume_name']) ?></p>
                                            </div>
                                            <div class="flex items-center space-x-4">
                                                <div class="text-right">
                                                    <p class="font-bold text-2xl text-white"><?= number_format($analysis['overall_score'], 0) ?>%</p>
                                                </div>
                                                <a href="analysis_result.php?id=<?= (int)$analysis['id'] ?>" class="bg-indigo-500 text-white px-4 py-2 font-semibold rounded-lg hover:bg-indigo-600 transition-colors text-sm">View</a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-10"><i class="fas fa-search-plus fa-3x text-gray-500 mb-4"></i><h4 class="font-bold text-white">No Analyses Yet</h4><p class="text-gray-400">Run an analysis to see your results.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-1 space-y-8">
                    <div class="glass-effect rounded-2xl p-6 shadow-2xl">
                         <h3 class="text-xl font-bold mb-4 text-white">Quick Actions</h3>
                         <div class="space-y-3">
                             <a href="analyze.php" class="w-full text-center bg-indigo-500 text-white px-6 py-3 font-semibold rounded-lg hover:bg-indigo-600 shadow-md shadow-indigo-500/30 transition-all flex items-center justify-center"><i class="fas fa-rocket mr-2"></i> New Analysis</a>
                             <a href="../jobs/job_edit.php" class="w-full text-center bg-gray-700 text-white px-6 py-3 font-semibold rounded-lg hover:bg-gray-600 flex items-center justify-center"><i class="fas fa-plus-circle mr-2"></i> Add Job</a>
                         </div>
                    </div>
                    <div class="glass-effect rounded-2xl p-6 shadow-2xl">
                         <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-bold text-white">Recent Resumes</h3>
                            <a href="../resumes/resumes.php" class="text-sm font-semibold text-indigo-400 hover:underline">View All</a>
                         </div>
                         <div class="space-y-3">
                            <?php if (count($recentResumes) > 0): ?>
                                <?php foreach ($recentResumes as $resume): ?>
                                    <div class="flex items-center justify-between bg-gray-800/50 p-3 rounded-lg"><div class="flex items-center space-x-3 overflow-hidden"><i class="fas fa-file-alt text-indigo-400"></i><p class="text-sm font-medium truncate" title="<?= htmlspecialchars($resume['original_filename']) ?>"><?= htmlspecialchars($resume['original_filename']) ?></p></div><a href="analyze.php?id=<?= (int)$resume['id'] ?>" class="text-xs font-bold text-indigo-400 hover:underline flex-shrink-0">ANALYZE</a></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5"><i class="fas fa-file-upload fa-2x text-gray-500 mb-2"></i><p class="text-sm text-gray-400">No resumes uploaded.</p></div>
                            <?php endif; ?>
                         </div>
                    </div>
                </div>
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

        // --- Performance Chart ---
        const chartData = JSON.parse('<?= $chart_data_json ?>');
        const performanceChartCanvas = document.getElementById('performanceChart');
        if (performanceChartCanvas && chartData.labels && chartData.labels.length > 1) {
            const ctx = performanceChartCanvas.getContext('2d');

            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(99, 102, 241, 0.5)');
            gradient.addColorStop(1, 'rgba(99, 102, 241, 0)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Overall Score',
                        data: chartData.scores,
                        backgroundColor: gradient,
                        borderColor: '#818CF8',
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#818CF8',
                        pointHoverBackgroundColor: '#818CF8',
                        pointHoverBorderColor: '#fff',
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        fill: 'start',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1F2937',
                            titleColor: '#E5E7EB',
                            bodyColor: '#D1D5DB',
                            padding: 12,
                            cornerRadius: 6,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return `Score: ${context.raw}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                            ticks: { 
                                color: '#9CA3AF',
                                font: { size: 12 },
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#9CA3AF', font: { size: 12 } }
                        }
                    }
                }
            });
        }

        // --- Particle background script ---
        const canvas = document.getElementById('particle-canvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let particles = [];
        function setCanvasSize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        function getThemeColors() { return { particleColor: 'rgba(255, 255, 255, 0.7)', lineColor: 'rgba(255, 255, 255, 0.1)' }; }
        class Particle {
            constructor() { this.x = Math.random() * canvas.width; this.y = Math.random() * canvas.height; this.vx = (Math.random() - 0.5) * 0.5; this.vy = (Math.random() - 0.5) * 0.5; this.radius = Math.random() * 1.5 + 1; }
            update() { this.x += this.vx; this.y += this.vy; if (this.x < 0 || this.x > canvas.width) this.vx *= -1; if (this.y < 0 || this.y > canvas.height) this.vy *= -1; }
            draw() { ctx.beginPath(); ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2); ctx.fillStyle = getThemeColors().particleColor; ctx.fill(); }
        }
        function initParticles() { particles = []; const particleCount = Math.floor((canvas.width * canvas.height) / 15000); for (let i = 0; i < particleCount; i++) { particles.push(new Particle()); } }
        function connectParticles() { const colors = getThemeColors(); for (let i = 0; i < particles.length; i++) { for (let j = i; j < particles.length; j++) { const distance = Math.sqrt(Math.pow(particles[i].x - particles[j].x, 2) + Math.pow(particles[i].y - particles[j].y, 2)); if (distance < 120) { ctx.beginPath(); ctx.strokeStyle = colors.lineColor; ctx.lineWidth = 0.5; ctx.moveTo(particles[i].x, particles[i].y); ctx.lineTo(particles[j].x, particles[j].y); ctx.stroke(); } } } }
        function animate() { ctx.clearRect(0, 0, canvas.width, canvas.height); particles.forEach(p => { p.update(); p.draw(); }); connectParticles(); requestAnimationFrame(animate); }
        setCanvasSize(); initParticles(); animate();
        window.addEventListener('resize', () => { setCanvasSize(); initParticles(); });
    });
    </script>
</body>
</html>
