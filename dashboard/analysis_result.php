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
$analysis = null;
$analysis_data = []; // This will hold the full decoded JSON from the feedback column

// Check if analysis ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $error = "Invalid analysis ID.";
} else {
    $analysis_id = intval($_GET['id']);
    
    try {
        // Get analysis details with resume and job info
        $stmt = $db->secureQuery(
            "SELECT mr.*, 
                    u.id as resume_id, u.original_filename as resume_name, u.file_size, u.upload_date,
                    jd.title as job_title, jd.company as job_company, jd.id as job_id
             FROM match_results mr
             JOIN uploads u ON mr.upload_id = u.id
             LEFT JOIN job_descriptions jd ON mr.job_id = jd.id
             WHERE mr.id = :analysis_id AND u.user_id = :user_id",
            [':analysis_id' => $analysis_id, ':user_id' => $user_id]
        );
        
        $analysis = $stmt->fetch();
        
        if (!$analysis) {
            $error = "Analysis not found or you don't have permission to view it.";
        } else {
            // Decode the full analysis data from the feedback column
            if (!empty($analysis['feedback'])) {
                $analysis_data = json_decode($analysis['feedback'], true);
                if (!is_array($analysis_data)) {
                    $analysis_data = [];
                }
            }
            
            // Get similar analyses for comparison
            $stmt = $db->secureQuery(
                "SELECT mr.id, mr.overall_score, mr.analyzed_at,
                        u.original_filename as resume_name,
                        jd.title as job_title
                 FROM match_results mr
                 JOIN uploads u ON mr.upload_id = u.id
                 LEFT JOIN job_descriptions jd ON mr.job_id = jd.id
                 WHERE u.user_id = :user_id 
                 AND mr.id != :analysis_id
                 ORDER BY mr.analyzed_at DESC
                 LIMIT 5",
                [':user_id' => $user_id, ':analysis_id' => $analysis_id]
            );
            
            $similar_analyses = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = "Error loading analysis: " . $e->getMessage();
    }
}

// --- VISUAL HELPER FUNCTIONS (UPDATED FOR TAILWIND) ---

// Gets a hex color for the score circle gradient
function getScoreColorHex($score) {
    if ($score >= 80) return '#10B981'; // Tailwind green-500
    if ($score >= 60) return '#3B82F6'; // Tailwind blue-500
    if ($score >= 40) return '#F59E0B'; // Tailwind amber-500
    return '#EF4444'; // Tailwind red-500
}

// Gets a Tailwind background class for progress bars
function getScoreBarClass($score) {
    if ($score >= 80) return 'bg-green-500';
    if ($score >= 60) return 'bg-sky-500';
    if ($score >= 40) return 'bg-yellow-500';
    return 'bg-red-500';
}

// Gets a Font Awesome icon name based on feedback type
function getFeedbackIcon($type) {
    switch ($type) {
        case 'positive': return 'check-circle';
        case 'improvement': return 'exclamation-triangle';
        case 'tip': return 'lightbulb';
        default: return 'comment';
    }
}
require_once '../templates/header.php';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analysis Result | ResumeAI</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        #particle-canvas { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -10; }
        .glass-effect { background: rgba(17, 24, 39, 0.5); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        @media print {
            body { background: #fff !important; color: #000 !important; }
            .glass-effect, header, #particle-canvas, .print-hidden { display: none !important; }
            main { padding-top: 1rem !important; padding-bottom: 1rem !important; }
            .print-text-black { color: #000 !important; }
            .print-bg-white { background-color: #fff !important; }
            .print-border { border: 1px solid #ddd !important; }
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-200 antialiased">
    <canvas id="particle-canvas"></canvas>
    
    <main class="pt-18 pb-16">
        <div class="container mx-auto px-6" id="printable-content">
            <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
                <div>
                    <h1 class="text-4xl md:text-5xl font-extrabold tracking-tighter">Analysis <span class="bg-clip-text text-transparent bg-gradient-to-r from-indigo-400 to-pink-500">Report</span></h1>
                    <p class="text-lg text-gray-400 mt-2">A detailed breakdown of your resume's match score.</p>
                </div>
                <div class="print-hidden flex items-center gap-3">
                    <button onclick="window.print()" class="bg-gray-700 text-white px-4 py-2 font-semibold rounded-lg hover:bg-gray-600 transition-colors">
                        <i class="fas fa-print mr-2"></i>Print
                    </button>
                    <a href="dashboard.php" class="bg-indigo-500 text-white px-4 py-2 font-semibold rounded-lg hover:bg-indigo-600 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-900/50 border-l-4 border-red-500 text-red-300 p-4 mb-6 rounded-r-lg" role="alert">
                    <p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></p>
                </div>
            <?php elseif ($analysis): ?>
                <div class="glass-effect rounded-2xl p-6 md:p-8 shadow-2xl space-y-8 print-bg-white print-border">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-800/50 p-6 rounded-lg print-bg-white print-border">
                            <h3 class="text-lg font-bold mb-2 print-text-black">Resume</h3>
                            <p class="text-xl font-semibold text-white truncate print-text-black" title="<?= htmlspecialchars($analysis['resume_name']) ?>"><?= htmlspecialchars($analysis['resume_name']) ?></p>
                            <p class="text-sm text-gray-400">Uploaded: <?= date('M j, Y', strtotime($analysis['upload_date'])) ?></p>
                        </div>
                        <div class="bg-gray-800/50 p-6 rounded-lg print-bg-white print-border">
                            <h3 class="text-lg font-bold mb-2 print-text-black">Job Position</h3>
                            <?php if (!empty($analysis['job_title'])): ?>
                                <p class="text-xl font-semibold text-white truncate print-text-black" title="<?= htmlspecialchars($analysis['job_title']) ?>"><?= htmlspecialchars($analysis['job_title']) ?></p>
                                <p class="text-sm text-gray-400"><?= htmlspecialchars($analysis['job_company']) ?></p>
                            <?php else: ?>
                                <p class="text-xl font-semibold text-white print-text-black">Custom Job Description</p>
                                <p class="text-sm text-gray-400">Analysis against provided text</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-center">
                        <div class="lg:col-span-1 flex justify-center">
                            <div class="relative w-48 h-48">
                                <svg class="absolute inset-0 w-full h-full" viewBox="0 0 36 36">
                                    <path class="stroke-current text-gray-700" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke-width="3"></path>
                                    <path class="stroke-current" style="color: <?= getScoreColorHex($analysis['overall_score']) ?>;" stroke-dasharray="<?= round($analysis['overall_score']) ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke-width="3" stroke-linecap="round"></path>
                                </svg>
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <div class="text-center">
                                        <div class="text-4xl font-bold text-white print-text-black"><?= round($analysis['overall_score']) ?>%</div>
                                        <div class="text-sm text-gray-400">Overall Match</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-6">
                            <div class="bg-gray-800/50 rounded-lg p-6 text-center print-bg-white print-border">
                                <i class="fas fa-cogs text-3xl text-indigo-400 mb-4"></i>
                                <h3 class="font-bold mb-1 print-text-black">Skills Match</h3>
                                <div class="text-2xl font-bold text-white print-text-black"><?= round($analysis['skills_match']) ?>%</div>
                            </div>
                            <div class="bg-gray-800/50 rounded-lg p-6 text-center print-bg-white print-border">
                                <i class="fas fa-briefcase text-3xl text-green-400 mb-4"></i>
                                <h3 class="font-bold mb-1 print-text-black">Experience Match</h3>
                                <div class="text-2xl font-bold text-white print-text-black"><?= round($analysis['experience_match']) ?>%</div>
                            </div>
                            <div class="bg-gray-800/50 rounded-lg p-6 text-center print-bg-white print-border">
                                <i class="fas fa-graduation-cap text-3xl text-pink-400 mb-4"></i>
                                <h3 class="font-bold mb-1 print-text-black">Education Match</h3>
                                <div class="text-2xl font-bold text-white print-text-black"><?= round($analysis['education_match']) ?>%</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-800/50 rounded-lg p-6 print-bg-white print-border">
                        <h3 class="text-xl font-bold mb-4 print-text-black">Skills Analysis</h3>
                        <div class="grid lg:grid-cols-2 gap-8">
                            <div>
                                <h4 class="text-lg font-semibold mb-3 text-green-400 flex items-center"><i class="fas fa-check-circle mr-2"></i>Matching Skills</h4>
                                <?php if (!empty($analysis_data['matching_skills']) && is_array($analysis_data['matching_skills'])): ?>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($analysis_data['matching_skills'] as $skill): ?>
                                            <span class="bg-green-900/50 text-green-300 px-3 py-1 rounded-full text-sm font-medium"><?= htmlspecialchars($skill) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-400">No matching skills identified.</p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h4 class="text-lg font-semibold mb-3 text-red-400 flex items-center"><i class="fas fa-times-circle mr-2"></i>Missing Skills</h4>
                                <?php if (!empty($analysis_data['missing_skills']) && is_array($analysis_data['missing_skills'])): ?>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($analysis_data['missing_skills'] as $skill): ?>
                                            <span class="bg-red-900/50 text-red-300 px-3 py-1 rounded-full text-sm font-medium"><?= htmlspecialchars($skill) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                     <p class="text-gray-400">No critical missing skills found.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-800/50 rounded-lg p-6 print-bg-white print-border">
                        <h3 class="text-xl font-bold mb-4 print-text-black"><i class="fas fa-lightbulb text-indigo-400 mr-2"></i>AI Feedback & Recommendations</h3>
                        <?php if (!empty($analysis_data['feedback']) && is_array($analysis_data['feedback'])): ?>
                            <div class="space-y-4">
                                <?php foreach ($analysis_data['feedback'] as $item): ?>
                                    <div class="bg-gray-900/50 p-4 rounded-lg flex items-start space-x-4 print-border">
                                        <i class="fas fa-<?= getFeedbackIcon($item['type']) ?> text-2xl text-indigo-400 mt-1"></i>
                                        <p class="text-gray-300 print-text-black"><?= htmlspecialchars($item['message']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-400">No detailed feedback was generated for this analysis.</p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isset($similar_analyses) && count($similar_analyses) > 0): ?>
                    <div class="glass-effect rounded-2xl p-6 shadow-2xl print-hidden">
                        <h3 class="text-xl font-bold text-white mb-4">Compare with Other Analyses</h3>
                        <div class="space-y-4">
                            <?php foreach ($similar_analyses as $similar): ?>
                            <div class="bg-gray-800/50 p-4 rounded-lg">
                                <div class="flex items-center justify-between flex-wrap gap-4">
                                    <div>
                                        <p class="font-bold text-white truncate" title="<?= htmlspecialchars($similar['resume_name']) ?>"><?= htmlspecialchars($similar['resume_name']) ?></p>
                                        <p class="text-sm text-gray-400">
                                            <i class="fas fa-briefcase mr-1"></i> <?= htmlspecialchars($similar['job_title'] ?? 'Custom Analysis') ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center space-x-4 w-full sm:w-auto">
                                        <div class="flex-grow text-right">
                                            <p class="font-bold text-xl text-white"><?= number_format($similar['overall_score'], 0) ?>%</p>
                                        </div>
                                        <a href="analysis_result.php?id=<?= (int)$similar['id'] ?>" class="bg-indigo-500 text-white px-4 py-2 text-sm font-semibold rounded-lg hover:bg-indigo-600 transition-colors">View</a>
                                    </div>
                                </div>
                                <div class="w-full bg-gray-700 rounded-full h-1.5 mt-3">
                                    <div class="<?= getScoreBarClass($similar['overall_score']) ?> h-1.5 rounded-full" style="width: <?= round($similar['overall_score']) ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
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
        
        function setCanvasSize() { 
            canvas.width = window.innerWidth; 
            canvas.height = window.innerHeight; 
        }
        
        function getThemeColors() { 
            return { 
                particleColor: 'rgba(255, 255, 255, 0.7)', 
                lineColor: 'rgba(255, 255, 255, 0.1)' 
            }; 
        }
        
        class Particle {
            constructor() {
                this.x = Math.random() * canvas.width; 
                this.y = Math.random() * canvas.height;
                this.vx = (Math.random() - 0.5) * 0.5; 
                this.vy = (Math.random() - 0.5) * 0.5;
                this.radius = Math.random() * 1.5 + 1;
            }
            
            update() {
                this.x += this.vx; 
                this.y += this.vy;
                if (this.x < 0 || this.x > canvas.width) this.vx *= -1;
                if (this.y < 0 || this.y > canvas.height) this.vy *= -1;
            }
            
            draw() {
                ctx.beginPath(); 
                ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
                ctx.fillStyle = getThemeColors().particleColor; 
                ctx.fill();
            }
        }
        
        function initParticles() {
            particles = [];
            const particleCount = Math.floor((canvas.width * canvas.height) / 15000);
            for (let i = 0; i < particleCount; i++) { 
                particles.push(new Particle()); 
            }
        }
        
        function connectParticles() {
            const colors = getThemeColors();
            for (let i = 0; i < particles.length; i++) {
                for (let j = i; j < particles.length; j++) {
                    const distance = Math.sqrt(Math.pow(particles[i].x - particles[j].x, 2) + Math.pow(particles[i].y - particles[j].y, 2));
                    if (distance < 120) {
                        ctx.beginPath(); 
                        ctx.strokeStyle = colors.lineColor; 
                        ctx.lineWidth = 0.5;
                        ctx.moveTo(particles[i].x, particles[i].y); 
                        ctx.lineTo(particles[j].x, particles[j].y);
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
        
        setCanvasSize(); 
        initParticles(); 
        animate();
        window.addEventListener('resize', () => { setCanvasSize(); initParticles(); });
    });
    
    </script>
</body>
</html>