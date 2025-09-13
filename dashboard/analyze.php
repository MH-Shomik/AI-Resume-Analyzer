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
$success = '';
$resume_id = null;
$job_id = null;
$resumes = [];
$jobs = [];
$analysis_result = null;

// Check if resume ID is provided
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $resume_id = intval($_GET['id']);
}

// Check if job ID is provided
if (isset($_GET['job_id']) && is_numeric($_GET['job_id'])) {
    $job_id = intval($_GET['job_id']);
}

// Get user's resumes
try {
    $stmt = $db->secureQuery(
        "SELECT id, original_filename FROM uploads WHERE user_id = :user_id ORDER BY upload_date DESC",
        [':user_id' => $user_id]
    );
    $resumes = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error loading resumes: " . $e->getMessage();
}

// Check if job_descriptions table exists
try {
    $stmt = $db->secureQuery(
        "SELECT COUNT(*) as table_exists
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
         AND table_name = 'job_descriptions'",
        []
    );
    $tableCheck = $stmt->fetch();
    
    if ($tableCheck['table_exists'] > 0) {
        // Get user's job descriptions
        $stmt = $db->secureQuery(
            "SELECT id, title, company FROM job_descriptions WHERE user_id = :user_id ORDER BY posted_date DESC",
            [':user_id' => $user_id]
        );
        $jobs = $stmt->fetchAll();
    }
} catch (Exception $e) {
    // Silently handle error
}

// Handle resume upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_resume'])) {
    if (!isset($_FILES['resume_file']) || $_FILES['resume_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please select a valid resume file.";
    } else {
        $file = $_FILES['resume_file'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'doc', 'docx', 'txt'];
        
        if (!in_array($fileExt, $allowedExts)) {
            $error = "Only PDF, DOC, DOCX, and TXT files are allowed.";
        } elseif ($fileSize > 5000000) { // 5MB max
            $error = "File size exceeds the limit (5MB).";
        } else {
            $newFileName = uniqid('resume_') . '.' . $fileExt;
            $uploadPath = '../uploads/' . $newFileName;
            
            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                try {
                    $db->beginTransaction();
                    
                    $stmt = $db->secureQuery(
                        "INSERT INTO uploads (user_id, filename, original_filename, file_type, file_size, upload_date)
                         VALUES (:user_id, :filename, :original_filename, :file_type, :file_size, NOW())",
                        [
                            ':user_id' => $user_id,
                            ':filename' => $newFileName,
                            ':original_filename' => $fileName,
                            ':file_type' => $fileExt,
                            ':file_size' => $fileSize
                        ]
                    );
                    
                    $new_resume_id = $db->lastInsertId();
                    
                    $resumeText = "";
                    $output = [];
                    if ($fileExt === 'pdf') {
                        if (function_exists('exec')) {
                            @exec("pdftotext " . escapeshellarg($uploadPath) . " -", $output, $return_var);
                            $resumeText = ($return_var === 0) ? implode("\n", $output) : "Failed to extract text from PDF.";
                        } else {
                           $resumeText = "Could not execute pdftotext because the exec() function is disabled.";
                        }
                    } elseif ($fileExt === 'txt') {
                        $resumeText = file_get_contents($uploadPath);
                    } else {
                        $resumeText = "Text extraction is not implemented for " . strtoupper($fileExt) . " files.";
                    }
                    
                    $stmt = $db->secureQuery(
                        "INSERT INTO parsed_data (upload_id, raw_text) VALUES (:upload_id, :raw_text)",
                        [':upload_id' => $new_resume_id, ':raw_text' => $resumeText]
                    );
                    
                    $db->commit();
                    $success = "Resume uploaded successfully! You can now select it for analysis.";
                    $resume_id = $new_resume_id;
                    
                    $stmt = $db->secureQuery(
                        "SELECT id, original_filename FROM uploads WHERE user_id = :user_id ORDER BY upload_date DESC",
                        [':user_id' => $user_id]
                    );
                    $resumes = $stmt->fetchAll();
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error uploading resume: " . $e->getMessage();
                }
            } else {
                $error = "Failed to upload file.";
            }
        }
    }
}

// Handle analysis form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['analyze_resume'])) {
    $selected_resume_id = $_POST['resume_id'] ?? null;
    $selected_job_id = $_POST['job_id'] ?? null;
    $custom_job_description = $_POST['custom_job_description'] ?? '';
    
    if (empty($selected_resume_id)) {
        $error = "Please select a resume to analyze.";
    } elseif (empty($selected_job_id) && empty($custom_job_description)) {
        $error = "Please select a job description or enter a custom one.";
    } else {
        try {
            $stmt = $db->secureQuery(
                "SELECT pd.raw_text FROM parsed_data pd JOIN uploads u ON pd.upload_id = u.id WHERE pd.upload_id = :resume_id AND u.user_id = :user_id",
                [':resume_id' => $selected_resume_id, ':user_id' => $user_id]
            );
            $resumeData = $stmt->fetch();
            
            if (!$resumeData || empty($resumeData['raw_text'])) {
                $error = "Resume data not found or is empty. Please re-upload the resume.";
            } else {
                $jobDescription = '';
                $jobTitle = 'Custom Job';
                $jobCompany = '';
                
                if (!empty($selected_job_id)) {
                    $stmt = $db->secureQuery(
                        "SELECT title, company, description FROM job_descriptions WHERE id = :job_id AND user_id = :user_id",
                        [':job_id' => $selected_job_id, ':user_id' => $user_id]
                    );
                    $jobData = $stmt->fetch();
                    if ($jobData) {
                        $jobDescription = $jobData['description'];
                        $jobTitle = $jobData['title'];
                        $jobCompany = $jobData['company'];
                    }
                } else {
                    $jobDescription = $custom_job_description;
                }
                
                $prompt = "You are an expert recruitment assistant. Analyze the provided resume against the job description.
                Provide a detailed analysis in JSON format. The JSON object must have the following structure:
                {
                  \"overall_score\": <A percentage score from 0 to 100 representing the overall match>,
                  \"skills_score\": <A percentage score from 0 to 100 for skills match>,
                  \"experience_score\": <A percentage score from 0 to 100 for experience match>,
                  \"education_score\": <A percentage score from 0 to 100 for education match>,
                  \"matching_skills\": [<An array of strings of skills present in both the resume and job description>],
                  \"missing_skills\": [<An array of strings of important skills from the job description that are missing from the resume>],
                  \"feedback\": [
                    {\"type\": \"positive\", \"message\": \"<A positive feedback message>\"},
                    {\"type\": \"improvement\", \"message\": \"<A message about an area for improvement>\"},
                    {\"type\": \"tip\", \"message\": \"<A general tip for the candidate>\"}
                  ]
                }

                Job Description:\n---\n{$jobDescription}\n---\n\nResume Text:\n---\n{$resumeData['raw_text']}\n---\n\nProvide the JSON analysis.";

                $response = callGeminiApi($prompt, GEMINI_API_KEY);

                $jsonResponse = trim($response['text']);
                if (strpos($jsonResponse, '```json') === 0) {
                    $jsonResponse = str_replace(['```json', '```'], '', $jsonResponse);
                }
                
                $analysisData = json_decode($jsonResponse, true);

                if (json_last_error() !== JSON_ERROR_NONE || !isset($analysisData['overall_score'])) {
                    throw new Exception("Failed to get a valid analysis from the AI. Response: " . htmlspecialchars($jsonResponse));
                }

                $db->beginTransaction();
                
                // FIX: Use a ternary operator to set job_id to null if it's empty.
                $jobIdToInsert = !empty($selected_job_id) ? $selected_job_id : null;

                $stmt = $db->secureQuery(
                    "INSERT INTO match_results (upload_id, job_id, overall_score, skills_match, experience_match, education_match, feedback, analyzed_at)
                     VALUES (:upload_id, :job_id, :overall_score, :skills_match, :experience_match, :education_match, :feedback, NOW())",
                    [
                        ':upload_id' => $selected_resume_id,
                        ':job_id' => $jobIdToInsert, // Use the corrected variable here
                        ':overall_score' => $analysisData['overall_score'],
                        ':skills_match' => $analysisData['skills_score'],
                        ':experience_match' => $analysisData['experience_score'],
                        ':education_match' => $analysisData['education_score'],
                        ':feedback' => json_encode($analysisData) // Store the full JSON payload
                    ]
                );
                $analysis_id = $db->lastInsertId();
                $db->commit();
                
                $analysis_result = array_merge($analysisData, [
                    'id' => $analysis_id,
                    'job_title' => $jobTitle,
                    'job_company' => $jobCompany
                ]);
                
                $success = "Gemini analysis completed successfully!";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Error during analysis: " . $e->getMessage();
        }
    }
}

function callGeminiApi(string $prompt, string $apiKey): array {
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;
    $data = ['contents' => [['parts' => [['text' => $prompt]]]]];
    $jsonData = json_encode($data);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception("cURL Error: " . $error);
    if ($http_code !== 200) throw new Exception("API Error (HTTP {$http_code}): " . $response);
    
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to decode API JSON response.");

    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        return ['text' => $responseData['candidates'][0]['content']['parts'][0]['text']];
    }
    
    $errorMessage = 'No content in API response.';
    if (isset($responseData['promptFeedback']['blockReason'])) {
        $errorMessage = 'Prompt blocked by API. Reason: ' . $responseData['promptFeedback']['blockReason'];
    }
    throw new Exception($errorMessage . ' Full Response: ' . $response);
}

function getScoreColor($score) {
    if ($score >= 80) return '#1abc9c';
    if ($score >= 60) return '#4a69bd';
    if ($score >= 40) return '#f39c12';
    return '#e74c3c';
}

function getFeedbackClass($type) {
    $classes = ['positive' => 'success', 'improvement' => 'warning', 'tip' => 'primary'];
    return $classes[$type] ?? 'secondary';
}

function getFeedbackIcon($type) {
    $icons = ['positive' => 'check-circle', 'improvement' => 'exclamation-triangle', 'tip' => 'lightbulb'];
    return $icons[$type] ?? 'comment';
}
require_once '../templates/header.php';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Analysis | ResumeAI</title>
    
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
    <!-- Particle Background Canvas -->
    <canvas id="particle-canvas"></canvas>
    
    <!-- Main Content -->
    <main class="pt-18 pb-16">
        <div class="container mx-auto px-6">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-4xl md:text-5xl font-extrabold tracking-tighter">
                    AI-Powered <span class="bg-clip-text text-transparent bg-gradient-to-r from-indigo-400 to-pink-500">Analysis</span>
                </h1>
                <p class="text-lg text-gray-400 mt-2">Upload your resume and get instant AI feedback to land your dream job.</p>
            </div>

            <!-- Error/Success Messages -->
            <?php if ($error): ?>
                <div class="bg-red-900/50 border-l-4 border-red-500 text-red-300 p-4 mb-6 rounded-r-lg">
                    <p><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($success && !$analysis_result): ?>
                <div class="bg-green-900/50 border-l-4 border-green-500 text-green-300 p-4 mb-6 rounded-r-lg">
                    <p><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?></p>
                </div>
            <?php endif; ?>

            <!-- Analysis Results Card -->
            <?php if ($analysis_result): ?>
                <div class="glass-effect rounded-2xl p-8 mb-8 shadow-2xl">
                    <div class="text-center mb-8">
                        <h2 class="text-3xl font-bold mb-2">Analysis Complete!</h2>
                        <p class="text-gray-400">Here's your match for the <?= htmlspecialchars($analysis_result['job_title']) ?> position</p>
                    </div>
                    
                    <!-- Overall Score Circle -->
                    <div class="flex justify-center mb-8">
                        <div class="relative w-48 h-48">
                            <div class="absolute inset-0 rounded-full" style="background: conic-gradient(from 0deg, <?= getScoreColor($analysis_result['overall_score']) ?> 0%, <?= getScoreColor($analysis_result['overall_score']) ?> <?= $analysis_result['overall_score'] ?>%, #374151 <?= $analysis_result['overall_score'] ?>%, #374151 100%)">
                                <div class="absolute inset-4 bg-gray-900 rounded-full flex items-center justify-center">
                                    <div class="text-center">
                                        <div class="text-4xl font-bold text-white"><?= round($analysis_result['overall_score']) ?>%</div>
                                        <div class="text-sm text-gray-400">Overall Match</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sub-scores -->
                    <div class="grid md:grid-cols-3 gap-6 mb-8">
                        <div class="bg-gray-800/50 rounded-lg p-6 text-center">
                            <i class="fas fa-cogs text-3xl text-indigo-400 mb-4"></i>
                            <h3 class="font-bold mb-2">Skills Match</h3>
                            <div class="text-2xl font-bold text-white"><?= round($analysis_result['skills_score']) ?>%</div>
                        </div>
                        <div class="bg-gray-800/50 rounded-lg p-6 text-center">
                            <i class="fas fa-briefcase text-3xl text-green-400 mb-4"></i>
                            <h3 class="font-bold mb-2">Experience Match</h3>
                            <div class="text-2xl font-bold text-white"><?= round($analysis_result['experience_score']) ?>%</div>
                        </div>
                        <div class="bg-gray-800/50 rounded-lg p-6 text-center">
                            <i class="fas fa-graduation-cap text-3xl text-pink-400 mb-4"></i>
                            <h3 class="font-bold mb-2">Education Match</h3>
                            <div class="text-2xl font-bold text-white"><?= round($analysis_result['education_score']) ?>%</div>
                        </div>
                    </div>
                    
                    <!-- Skills Analysis -->
                    <div class="grid lg:grid-cols-2 gap-8 mb-8">
                        <div>
                            <h4 class="text-lg font-bold mb-4 text-green-400">
                                <i class="fas fa-check-circle mr-2"></i>Matching Skills
                            </h4>
                            <?php if (!empty($analysis_result['matching_skills'])): ?>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($analysis_result['matching_skills'] as $skill): ?>
                                        <span class="bg-green-900/30 text-green-300 px-3 py-1 rounded-full text-sm"><?= htmlspecialchars($skill) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-400">No specific matching skills identified.</p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 class="text-lg font-bold mb-4 text-red-400">
                                <i class="fas fa-times-circle mr-2"></i>Skills to Add
                            </h4>
                            <?php if (!empty($analysis_result['missing_skills'])): ?>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($analysis_result['missing_skills'] as $skill): ?>
                                        <span class="bg-red-900/30 text-red-300 px-3 py-1 rounded-full text-sm"><?= htmlspecialchars($skill) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-400">Great! No critical missing skills found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Feedback -->
                    <div class="mb-8">
                        <h4 class="text-lg font-bold mb-4">
                            <i class="fas fa-lightbulb text-indigo-400 mr-2"></i>AI Recommendations
                        </h4>
                        <?php if (!empty($analysis_result['feedback'])): ?>
                            <div class="space-y-3">
                                <?php foreach ($analysis_result['feedback'] as $item): ?>
                                    <div class="bg-gray-800/50 p-4 rounded-lg flex items-start space-x-3">
                                        <i class="fas fa-<?= getFeedbackIcon($item['type']) ?> text-indigo-400 mt-1"></i>
                                        <p class="text-gray-300"><?= htmlspecialchars($item['message']) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex justify-center gap-4">
                        <button class="bg-indigo-500 text-white px-6 py-3 font-semibold rounded-lg hover:bg-indigo-600 shadow-md shadow-indigo-500/30 transition-all duration-300 transform hover:scale-105">
                            <a href="analysis_result.php?id=<?= $analysis_id ?>" class="flex items-center"><i class="fas fa-file-alt mr-2"></i>View Full Report</a>
                        </button>
                        <button onclick="window.location.reload()" class="bg-gray-700 text-white px-6 py-3 font-semibold rounded-lg hover:bg-gray-600 transition-colors">
                            <i class="fas fa-redo mr-2"></i>New Analysis
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Analysis Wizard -->
            <div class="glass-effect rounded-2xl shadow-2xl overflow-hidden">
                <form method="post" enctype="multipart/form-data">
                    <!-- Step 1: Resume Selection -->
                    <div class="border-b border-gray-700/50 p-8">
                        <div class="flex items-center mb-6">
                            <div class="w-12 h-12 rounded-full bg-indigo-500 text-white flex items-center justify-center font-bold text-xl mr-4">1</div>
                            <h3 class="text-2xl font-bold">Select Your Resume</h3>
                        </div>
                        
                        <div class="space-y-6">
                            <div>
                                <label for="resume_id" class="block text-sm font-medium text-gray-300 mb-2">Choose from saved resumes:</label>
                                <select class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-colors" name="resume_id" required>
                                    <option value="">-- Select a Resume --</option>
                                    <?php foreach ($resumes as $resume): ?>
                                        <option value="<?= (int)$resume['id'] ?>" <?= $resume_id == $resume['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($resume['original_filename']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="text-center text-gray-400">OR</div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Upload a new resume:</label>
                                <div class="flex gap-3">
                                    <input type="file" class="flex-1 px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-colors file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-500 file:text-white hover:file:bg-indigo-600" name="resume_file" accept=".pdf,.doc,.docx,.txt" />
                                    <button type="submit" name="upload_resume" class="bg-gray-600 text-white px-6 py-3 font-semibold rounded-lg hover:bg-gray-500 transition-colors">
                                        <i class="fas fa-upload mr-2"></i>Upload
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Job Details -->
                    <div class="border-b border-gray-700/50 p-8">
                        <div class="flex items-center mb-6">
                            <div class="w-12 h-12 rounded-full bg-indigo-500 text-white flex items-center justify-center font-bold text-xl mr-4">2</div>
                            <h3 class="text-2xl font-bold">Provide Job Details</h3>
                        </div>
                        
                        <div class="space-y-6">
                            <div>
                                <label for="job_id" class="block text-sm font-medium text-gray-300 mb-2">Select a saved job:</label>
                                <select class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-colors" name="job_id">
                                    <option value="">-- Select a Job --</option>
                                    <?php foreach ($jobs as $job): ?>
                                        <option value="<?= (int)$job['id'] ?>" <?= $job_id == $job['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($job['title']) ?> at <?= htmlspecialchars($job['company']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="text-center text-gray-400">OR</div>
                            
                            <div>
                                <label for="custom_job_description" class="block text-sm font-medium text-gray-300 mb-2">Paste job description:</label>
                                <textarea class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-colors resize-none" name="custom_job_description" rows="8" placeholder="Paste the full job description here..."><?= isset($_POST['custom_job_description']) ? htmlspecialchars($_POST['custom_job_description']) : '' ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Launch Analysis -->
                    <div class="p-8 text-center bg-gray-800/30">
                        <div class="mb-6">
                            <h4 class="text-2xl font-bold mb-2">Ready to Launch?</h4>
                            <p class="text-gray-400">Let our AI analyze how well you match this opportunity!</p>
                        </div>
                        <button type="submit" name="analyze_resume" class="bg-gradient-to-r from-indigo-500 to-pink-500 text-white px-8 py-4 font-bold text-lg rounded-lg hover:from-indigo-600 hover:to-pink-600 shadow-xl shadow-indigo-500/30 transition-all duration-300 transform hover:scale-105">
                            <i class="fas fa-rocket mr-3"></i>Run AI Analysis
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900/80 backdrop-blur-md flex flex-col items-center justify-center z-[9999] opacity-0 pointer-events-none transition-opacity duration-500">
    <div class="text-center">
        <div class="flex justify-center items-center space-x-3 mb-8">
            <div class="w-4 h-4 bg-indigo-400 rounded-full animate-pulse-dots"></div>
            <div class="w-4 h-4 bg-indigo-400 rounded-full animate-pulse-dots" style="animation-delay: 0.2s;"></div>
            <div class="w-4 h-4 bg-indigo-400 rounded-full animate-pulse-dots" style="animation-delay: 0.4s;"></div>
        </div>
        
        <h2 class="text-3xl font-extrabold text-white mb-2 tracking-tight">AI Analysis in Progress</h2>
        <p class="text-gray-300 max-w-md mx-auto">Our digital assistant is carefully reviewing your documents. This should only take a moment.</p>
        
        <div class="mt-8 text-left inline-block bg-gray-800/50 px-6 py-4 rounded-lg border border-gray-700/50 text-sm">
            <ul class="space-y-2">
                <li class="flex items-center text-gray-400"><span class="text-indigo-400 w-5"><i class="fas fa-check"></i></span> Parsing Resume Structure...</li>
                <li class="flex items-center text-gray-400"><span class="text-indigo-400 w-5 animate-pulse"><i class="fas fa-spinner"></i></span> Matching Key Skills...</li>
                <li class="flex items-center text-gray-500"><span class="text-gray-600 w-5"><i class="far fa-circle"></i></span> Generating Final Report...</li>
            </ul>
        </div>
    </div>
</div>

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

        // Loading overlay functionality
        const form = document.querySelector('form');
        const loadingOverlay = document.getElementById('loading-overlay');
        
        if (form && loadingOverlay) {
            form.addEventListener('submit', function(e) {
                const submitter = e.submitter;
                if (submitter && submitter.name === 'analyze_resume') {
                    const resumeId = document.querySelector('select[name="resume_id"]').value;
                    const jobId = document.querySelector('select[name="job_id"]').value;
                    const customJob = document.querySelector('textarea[name="custom_job_description"]').value;

                    if (resumeId && (jobId || customJob.trim() !== '')) {
                        loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');
                    }
                }
            });
        }
    });
    
    </script>
</body>
</html>