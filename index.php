<?php
session_start();
require_once 'includes/db.php';

$pdo = getDBConnection();

// Get current user from session
$user = null;
$username = 'Guest';
$userId = null;

if (isset($_SESSION['user_id'])) {
    $user = [
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role_id' => $_SESSION['role_id'] ?? null
    ];
    $username = $user['username'];
    $userId = $user['user_id'];
}

// Initialize variables
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'create';
$quizId = isset($_GET['quiz_id']) ? $_GET['quiz_id'] : null;
$currentQuiz = null;
$questions = [];
$pairs = [];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_quiz'])) {
        handleSaveQuiz($pdo, $userId);
    } elseif (isset($_POST['load_quiz'])) {
        $quizId = $_POST['quiz_id'];
        $mode = 'view';
        loadQuiz($pdo, $quizId);
    } elseif (isset($_POST['switch_to_edit'])) {
        $quizId = $_POST['quiz_id'];
        $mode = 'edit';
        loadQuiz($pdo, $quizId);
    } elseif (isset($_POST['switch_to_view'])) {
        $quizId = $_POST['quiz_id'];
        $mode = 'view';
        loadQuiz($pdo, $quizId);
    } elseif (isset($_POST['update_quiz'])) {
        handleUpdateQuiz($pdo, $userId);
    }
} elseif (isset($_GET['load'])) {
    $quizId = $_GET['load'];
    $mode = 'view';
    loadQuiz($pdo, $quizId);
} elseif (isset($_GET['edit'])) {
    $quizId = $_GET['edit'];
    $mode = 'edit';
    loadQuiz($pdo, $quizId);
}

// Function to load quiz data
function loadQuiz($pdo, $quizId) {
    global $currentQuiz, $questions, $pairs;
    
    // Load quiz info
    $stmt = $pdo->prepare("SELECT * FROM quiz WHERE quiz_id = ?");
    $stmt->execute([$quizId]);
    $currentQuiz = $stmt->fetch();
    
    if ($currentQuiz) {
        // Load question
        $stmt = $pdo->prepare("SELECT * FROM question WHERE quiz_id = ?");
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll();
        
        // Load matching pairs
        if (!empty($questions)) {
            $questionId = $questions[0]['question_id'];
            $stmt = $pdo->prepare("SELECT * FROM matching_pairs WHERE question_id = ? ORDER BY position");
            $stmt->execute([$questionId]);
            $pairs = $stmt->fetchAll();
        }
    }
}

// Function to handle quiz save
function handleSaveQuiz($pdo, $userId) {
    if (!$userId) {
        header("Location: pages/login.php");
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Insert quiz
        $stmt = $pdo->prepare("INSERT INTO quiz (user_id, title, description, is_public) VALUES (?, ?, ?, ?)");
        $isPublic = isset($_POST['is_private']) ? 0 : 1;
        $stmt->execute([$userId, $_POST['title'], $_POST['description'], $isPublic]);
        $quizId = $pdo->lastInsertId();
        
        // Insert question
        $stmt = $pdo->prepare("INSERT INTO question (quiz_id, user_prompt) VALUES (?, ?)");
        $stmt->execute([$quizId, $_POST['user_prompt']]);
        $questionId = $pdo->lastInsertId();
        
        // Insert matching pairs
        $leftTexts = $_POST['left_text'] ?? [];
        $rightTexts = $_POST['right_text'] ?? [];
        
        for ($i = 0; $i < count($leftTexts); $i++) {
            if (!empty($leftTexts[$i]) && !empty($rightTexts[$i])) {
                $stmt = $pdo->prepare("INSERT INTO matching_pairs (question_id, left_text, right_text, position) VALUES (?, ?, ?, ?)");
                $stmt->execute([$questionId, $leftTexts[$i], $rightTexts[$i], $i + 1]);
            }
        }
        
        $pdo->commit();
        header("Location: index.php?load=" . $quizId);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Save quiz error: " . $e->getMessage());
        echo "<script>alert('Error saving quiz: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Function to handle quiz update
function handleUpdateQuiz($pdo, $userId) {
    if (!$userId) {
        header("Location: pages/login.php");
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        $quizId = $_POST['quiz_id'];
        
        // Update quiz
        $stmt = $pdo->prepare("UPDATE quiz SET title = ?, description = ?, is_public = ? WHERE quiz_id = ? AND user_id = ?");
        $isPublic = isset($_POST['is_private']) ? 0 : 1;
        $stmt->execute([$_POST['title'], $_POST['description'], $isPublic, $quizId, $userId]);
        
        // Update question prompt
        $stmt = $pdo->prepare("UPDATE question SET user_prompt = ? WHERE quiz_id = ?");
        $stmt->execute([$_POST['user_prompt'], $quizId]);
        
        // Get question ID
        $stmt = $pdo->prepare("SELECT question_id FROM question WHERE quiz_id = ?");
        $stmt->execute([$quizId]);
        $question = $stmt->fetch();
        $questionId = $question ? $question['question_id'] : null;
        
        if ($questionId) {
            // Clear existing pairs
            $stmt = $pdo->prepare("DELETE FROM matching_pairs WHERE question_id = ?");
            $stmt->execute([$questionId]);
            
            // Insert updated pairs
            $leftTexts = $_POST['left_text'] ?? [];
            $rightTexts = $_POST['right_text'] ?? [];
            
            for ($i = 0; $i < count($leftTexts); $i++) {
                if (!empty($leftTexts[$i]) && !empty($rightTexts[$i])) {
                    $stmt = $pdo->prepare("INSERT INTO matching_pairs (question_id, left_text, right_text, position) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$questionId, $leftTexts[$i], $rightTexts[$i], $i + 1]);
                }
            }
        }
        
        $pdo->commit();
        header("Location: index.php?load=" . $quizId);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Update quiz error: " . $e->getMessage());
        echo "<script>alert('Error updating quiz: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Fetch user's tests
$myTests = [];
if ($userId) {
    $stmt = $pdo->prepare("
        SELECT q.*, COUNT(mp.pair_id) as question_count 
        FROM quiz q 
        LEFT JOIN question qu ON q.quiz_id = qu.quiz_id 
        LEFT JOIN matching_pairs mp ON qu.question_id = mp.question_id 
        WHERE q.user_id = ? 
        GROUP BY q.quiz_id
        ORDER BY q.created_at DESC
    ");
    $stmt->execute([$userId]);
    $myTests = $stmt->fetchAll();
}

// Fetch public tests
$stmt = $pdo->prepare("
    SELECT q.*, COUNT(mp.pair_id) as question_count 
    FROM quiz q 
    LEFT JOIN question qu ON q.quiz_id = qu.quiz_id 
    LEFT JOIN matching_pairs mp ON qu.question_id = mp.question_id 
    WHERE q.is_public = 1 
    GROUP BY q.quiz_id
    ORDER BY q.created_at DESC
");
$stmt->execute();
$publicTests = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Quiz Dashboard</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Column 1: Sidebar -->
        <div class="sidebar">
            <!-- Row 1: User Profile -->
            <div class="sidebar-row profile-row">
                <div class="user-profile">
                    <h3>Welcome, <?php echo htmlspecialchars($username); ?></h3>
                    <?php if (!$userId): ?>
                        <a href="pages/login.php" class="login-link">Login</a>
                    <?php else: ?>
                        <a href="pages/logout.php" class="login-link">Logout</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Row 2: My Tests -->
            <div class="sidebar-row my-tests-row">
                <h4>My Tests</h4>
                <div class="test-list">
                    <?php if ($userId): ?>
                        <?php foreach ($myTests as $test): ?>
                            <form method="GET" action="index.php" class="test-form">
                                <input type="hidden" name="load" value="<?php echo $test['quiz_id']; ?>">
                                <button type="submit" class="test-btn">
                                    <span class="test-title"><?php echo htmlspecialchars($test['title']); ?></span>
                                    <span class="question-count"><?php echo $test['question_count']; ?> questions</span>
                                </button>
                            </form>
                        <?php endforeach; ?>
                        <?php if (empty($myTests)): ?>
                            <p class="no-tests">No tests created yet</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="login-prompt">Login to see your tests</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Row 3: Public Tests -->
            <div class="sidebar-row public-tests-row">
                <h4>Public Tests</h4>
                <div class="test-list">
                    <?php foreach ($publicTests as $test): ?>
                        <form method="GET" action="index.php" class="test-form">
                            <input type="hidden" name="load" value="<?php echo $test['quiz_id']; ?>">
                            <button type="submit" class="test-btn">
                                <span class="test-title"><?php echo htmlspecialchars($test['title']); ?></span>
                                <span class="question-count"><?php echo $test['question_count']; ?> questions</span>
                            </button>
                        </form>
                    <?php endforeach; ?>
                    <?php if (empty($publicTests)): ?>
                        <p class="no-tests">No public tests available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Column 2: Main Workspace -->
        <div class="main-workspace">
            <form id="quizForm" method="POST">
                <!-- Hidden fields for mode and quiz ID -->
                <input type="hidden" name="mode" id="mode" value="<?php echo $mode; ?>">
                <input type="hidden" name="quiz_id" id="quiz_id" value="<?php echo $quizId; ?>">
                
                <!-- Row 1: Title -->
                <div class="workspace-row title-row">
                    <input type="text" 
                           name="title" 
                           id="title" 
                           placeholder="Quiz Title" 
                           maxlength="50" 
                           value="<?php echo isset($currentQuiz['title']) ? htmlspecialchars($currentQuiz['title']) : ''; ?>"
                           <?php echo ($mode === 'view') ? 'readonly' : ''; ?>>
                </div>
                
                <!-- Row 2: Description -->
                <div class="workspace-row description-row">
                    <textarea name="description" 
                              id="description" 
                              placeholder="Quiz Description"
                              <?php echo ($mode === 'view') ? 'readonly' : ''; ?>><?php echo isset($currentQuiz['description']) ? htmlspecialchars($currentQuiz['description']) : ''; ?></textarea>
                </div>
                
                <!-- Row 3: User Prompt -->
                <div class="workspace-row prompt-row">
                    <textarea name="user_prompt" 
                              id="user_prompt" 
                              placeholder="Quiz Instructions (max 100 chars)" 
                              maxlength="100"
                              <?php echo ($mode === 'view') ? 'readonly' : ''; ?>><?php echo isset($questions[0]['user_prompt']) ? htmlspecialchars($questions[0]['user_prompt']) : ''; ?></textarea>
                </div>
                
                <!-- Row 4: Q&A Pairs -->
                <div class="workspace-row pairs-row" id="pairsContainer">
                    <?php if ($mode === 'view'): ?>
                        <!-- View Mode: Shuffled answers -->
                        <?php 
                        // Shuffle answers for view mode
                        $shuffledPairs = $pairs;
                        $rightTexts = array_column($shuffledPairs, 'right_text');
                        shuffle($rightTexts);
                        ?>
                        <div class="pairs-view-mode">
                            <?php foreach ($shuffledPairs as $index => $pair): ?>
                                <div class="pair-row view-pair">
                                    <div class="pair-left">
                                        <span class="pair-text"><?php echo htmlspecialchars($pair['left_text']); ?></span>
                                        <div class="radio-area">
                                            <input type="radio" name="match_<?php echo $index; ?>" value="<?php echo $index; ?>">
                                        </div>
                                    </div>
                                    <div class="pair-right">
                                        <div class="radio-area">
                                            <input type="radio" name="match_<?php echo $index; ?>" value="<?php echo $index; ?>">
                                        </div>
                                        <span class="pair-text"><?php echo htmlspecialchars($rightTexts[$index]); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- Create/Edit Mode: Editable pairs -->
                        <div class="pairs-edit-mode">
                            <?php 
                            $pairsToShow = !empty($pairs) ? $pairs : [['left_text' => '', 'right_text' => '']];
                            foreach ($pairsToShow as $index => $pair): 
                            ?>
                                <div class="pair-row edit-pair" data-index="<?php echo $index; ?>">
                                    <input type="text" 
                                           name="left_text[]" 
                                           class="pair-input left-input" 
                                           placeholder="Question" 
                                           maxlength="100"
                                           value="<?php echo htmlspecialchars($pair['left_text']); ?>">
                                    <input type="text" 
                                           name="right_text[]" 
                                           class="pair-input right-input" 
                                           placeholder="Answer" 
                                           maxlength="100"
                                           value="<?php echo htmlspecialchars($pair['right_text']); ?>">
                                    <button type="button" class="remove-pair-btn" onclick="removePair(this)">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Row 5: Add Button (only in create/edit mode) -->
                <div class="workspace-row add-row" <?php echo ($mode === 'view') ? 'style="display:none;"' : ''; ?>>
                    <button type="button" class="add-pair-btn" onclick="addPair()">+ Add New Pair</button>
                </div>
                
                <!-- Row 6: Actions -->
                <div class="workspace-row actions-row">
                    <?php if ($mode === 'create'): ?>
                        <!-- Create Mode Actions -->
                        <div class="action-col">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_private" id="is_private">
                                <span>Private</span>
                            </label>
                        </div>
                        <div class="action-col">
                            <button type="submit" name="save_quiz" class="action-btn save-btn">Save Quiz</button>
                        </div>
                        
                    <?php elseif ($mode === 'view'): ?>
                        <!-- View Mode Actions -->
                        <?php if ($userId && isset($currentQuiz['user_id']) && $userId == $currentQuiz['user_id']): ?>
                            <div class="action-col">
                                <form method="GET" action="index.php" style="display: inline;">
                                    <input type="hidden" name="edit" value="<?php echo $quizId; ?>">
                                    <button type="submit" class="action-btn edit-btn">Edit</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <div class="action-col">
                            <button type="button" class="action-btn done-btn" onclick="submitQuiz()">Done</button>
                        </div>
                        
                    <?php elseif ($mode === 'edit'): ?>
                        <!-- Edit Mode Actions -->
                        <div class="action-col">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_private" id="is_private" 
                                    <?php echo (isset($currentQuiz['is_public']) && $currentQuiz['is_public'] == 0) ? 'checked' : ''; ?>>
                                <span>Private</span>
                            </label>
                        </div>
                        <div class="action-col">
                            <button type="submit" name="switch_to_view" class="action-btn view-btn">Take Quiz</button>
                        </div>
                        <div class="action-col">
                            <button type="submit" name="update_quiz" class="action-btn save-btn">Save Changes</button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
</body>
</html>