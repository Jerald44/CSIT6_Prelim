<?php
session_start();
require_once 'includes/db.php';

// --- Helper Functions ---

// Function to generate IDs manually since schema doesn't specify AUTO_INCREMENT
function getNextId($pdo, $table, $column)
{
    $stmt = $pdo->query("SELECT MAX($column) as max_id FROM $table");
    $row = $stmt->fetch();
    return ($row['max_id'] ?? 0) + 1;
}

// Check Login State
$is_logged_in = isset($_SESSION['user_id']);
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_username = $_SESSION['username'] ?? 'Guest';

// Initialize Variables
$mode = 'create'; // create, view, edit
$quiz_data = [
    'title' => '',
    'description' => '',
    'is_public' => 0,
    'quiz_id' => null,
    'user_id' => 0
];
$prompt_data = '';
$pairs_data = [];
$error_msg = '';
$success_msg = '';

$pdo = getDBConnection();

// --- ADD THIS TO YOUR GET HANDLING SECTION ---
$saved_answers = [];
if (isset($_GET['attempt_id'])) {
    $attempt_id = $_GET['attempt_id'];
    $mode = 'review';

    // 1. Fetch Attempt Info (to get the quiz_id)
    $stmt = $pdo->prepare("SELECT quiz_id FROM attempts WHERE attempt_id = ?");
    $stmt->execute([$attempt_id]);
    $attempt_meta = $stmt->fetch();

    if ($attempt_meta) {
        $req_qid = $attempt_meta['quiz_id'];

        // 2. Load the original quiz data (so the title/description show up)
        $stmt = $pdo->prepare("SELECT * FROM quiz WHERE quiz_id = ?");
        $stmt->execute([$req_qid]);
        $quiz_data = $stmt->fetch();

        // 3. Load the Questions/Pairs
        $stmt = $pdo->prepare("SELECT question_id, user_prompt FROM question WHERE quiz_id = ?");
        $stmt->execute([$req_qid]);
        $q_data = $stmt->fetch();
        $prompt_data = $q_data['user_prompt'];

        $stmt = $pdo->prepare("SELECT * FROM matching_pairs WHERE question_id = ?");
        $stmt->execute([$q_data['question_id']]);
        $pairs_data = $stmt->fetchAll();
        $left_col_items = $pairs_data;
        $right_col_items = $pairs_data; // Don't shuffle in review mode usually

        // 4. THE FIX: Fetch the actual answers the user gave
        // We want an array where [left_pair_id => right_pair_id]
        $stmt = $pdo->prepare("
            SELECT aa.pair_id, mp_right.pair_id as matched_id
            FROM attempt_answer aa
            JOIN matching_pairs mp_right ON aa.chosen_text = mp_right.right_text
            WHERE aa.attempt_id = ? AND mp_right.question_id = ?
        ");
        $stmt->execute([$attempt_id, $q_data['question_id']]);

        // This creates the array your JavaScript/HTML is looking for
        while ($row = $stmt->fetch()) {
            $saved_answers[$row['pair_id']] = $row['matched_id'];
        }
    }
}

$attempt_history = [];
if ($is_logged_in) {
    // We join 'attempts' with 'quiz' to get the title and question count
    $stmt = $pdo->prepare("
        SELECT 
            a.attempt_id, 
            a.score, 
            a.taken_at, 
            q.title, 
            (SELECT COUNT(*) 
             FROM matching_pairs mp 
             JOIN question qu ON mp.question_id = qu.question_id 
             WHERE qu.quiz_id = q.quiz_id) as q_count 
        FROM attempts a
        JOIN quiz q ON a.quiz_id = q.quiz_id
        WHERE a.user_id = ?
        ORDER BY a.taken_at DESC
    ");
    $stmt->execute([$current_user_id]);
    $attempt_history = $stmt->fetchAll();
}

// --- POST REQUEST HANDLING (Save/Delete) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'save_quiz') {
        if (!$is_logged_in) {
            header("Location: pages/login.php");
            exit;
        }

        try {
            $pdo->beginTransaction();

            $title = $_POST['title'];
            $desc = $_POST['description'];
            $prompt = $_POST['user_prompt'];
            $is_public = isset($_POST['is_public']) ? 0 : 1; // Checkbox checked = private (0) per prompt logic? 
            // Prompt says: Col 1: "Private" Checkbox. If checked, is_public = 0.
            $is_public_val = isset($_POST['is_private']) ? 0 : 1;

            $quiz_id = $_POST['quiz_id'] ?? null;

            if ($quiz_id) {
                // Update Existing
                $stmt = $pdo->prepare("UPDATE quiz SET title=?, description=?, is_public=? WHERE quiz_id=? AND user_id=?");
                $stmt->execute([$title, $desc, $is_public_val, $quiz_id, $current_user_id]);

                // For simplicity in this scope, we wipe questions/pairs and recreate them on edit
                // Real-world apps should update specific IDs to preserve stats, but schema lacks cascading deletes 
                // We will just update the Question text and handle pairs carefully.
                $stmt = $pdo->prepare("UPDATE question SET user_prompt=? WHERE quiz_id=?");
                $stmt->execute([$prompt, $quiz_id]);

                // Get Question ID
                $stmt = $pdo->prepare("SELECT question_id FROM question WHERE quiz_id=?");
                $stmt->execute([$quiz_id]);
                $qid = $stmt->fetchColumn();

                // Delete old pairs
                $stmt = $pdo->prepare("DELETE FROM matching_pairs WHERE question_id=?");
                $stmt->execute([$qid]);

                $current_q_id = $qid;

            } else {
                // Insert New Quiz
                $quiz_id = getNextId($pdo, 'quiz', 'quiz_id');
                $stmt = $pdo->prepare("INSERT INTO quiz (quiz_id, user_id, title, description, is_public) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$quiz_id, $current_user_id, $title, $desc, $is_public_val]);

                // Insert Question
                $current_q_id = getNextId($pdo, 'question', 'question_id');
                $stmt = $pdo->prepare("INSERT INTO question (question_id, quiz_id, user_prompt) VALUES (?, ?, ?)");
                $stmt->execute([$current_q_id, $quiz_id, $prompt]);
            }

            // Insert Pairs
            if (isset($_POST['left_text']) && isset($_POST['right_text'])) {
                $lefts = $_POST['left_text'];
                $rights = $_POST['right_text'];

                for ($i = 0; $i < count($lefts); $i++) {
                    if (!empty($lefts[$i]) && !empty($rights[$i])) {
                        $pair_id = getNextId($pdo, 'matching_pairs', 'pair_id');
                        $stmt = $pdo->prepare("INSERT INTO matching_pairs (pair_id, question_id, left_text, right_text, position) VALUES (?, ?, ?, ?, ?)");
                        // Position logic: just storing generic 'LR' as schema requires a varchar(2)
                        $stmt->execute([$pair_id, $current_q_id, $lefts[$i], $rights[$i], 'LR']);
                    }
                }
            }

            $pdo->commit();
            header("Location: index.php?quiz_id=" . $quiz_id . "&mode=view");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Error saving: " . $e->getMessage();
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'submit_quiz') {
        $quiz_id = $_POST['quiz_id'];
        $user_id = $is_logged_in ? $current_user_id : null; // Logic for guest attempts
        $match_results = json_decode($_POST['match_results'], true);

        try {
            $pdo->beginTransaction();

            // 1. Fetch correct pairs from DB to validate
            $stmt = $pdo->prepare("SELECT pair_id, right_text FROM matching_pairs mp 
                               JOIN question q ON mp.question_id = q.question_id 
                               WHERE q.quiz_id = ?");
            $stmt->execute([$quiz_id]);
            $correct_data = $stmt->fetchAll(PDO::FETCH_UNIQUE); // pair_id as key

            $score = 0;
            $total = count($correct_data);
            $attempt_id = getNextId($pdo, 'attempts', 'attempt_id');

            // 2. Create the Attempt Record
            $stmt = $pdo->prepare("INSERT INTO attempts (attempt_id, user_id, quiz_id, score) VALUES (?, ?, ?, ?)");
            // Note: If guest, user_id might need to be a specific guest ID or handle null if DB allows
            $stmt->execute([$attempt_id, $user_id, $quiz_id, 0]);

            // 3. Process each match and save Attempt Answers
            foreach ($match_results as $left_pair_id => $right_pair_id) {
                // The user matched Left ID to Right ID. 
                // We need to see if Right ID's text matches the correct text for Left ID.
                $chosen_text = "";

                // Get the text the user actually picked from the right-side pair
                $stmtText = $pdo->prepare("SELECT right_text FROM matching_pairs WHERE pair_id = ?");
                $stmtText->execute([$right_pair_id]);
                $chosen_text = $stmtText->fetchColumn();

                $is_correct = ($correct_data[$left_pair_id]['right_text'] === $chosen_text) ? 1 : 0;
                if ($is_correct)
                    $score++;

                $ans_id = getNextId($pdo, 'attempt_answer', 'answer_id');
                $stmtAns = $pdo->prepare("INSERT INTO attempt_answer (answer_id, attempt_id, pair_id, chosen_text, is_correct) VALUES (?, ?, ?, ?, ?)");
                $stmtAns->execute([$ans_id, $attempt_id, $left_pair_id, $chosen_text, $is_correct]);
            }

            // 4. Update the final score in the attempt
            $stmtUpdate = $pdo->prepare("UPDATE attempts SET score = ? WHERE attempt_id = ?");
            $stmtUpdate->execute([$score, $attempt_id]);

            $pdo->commit();

            // Redirect with a success message
            header("Location: index.php?quiz_id=$quiz_id&mode=view&score=$score&total=$total");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = "Submission failed: " . $e->getMessage();
        }
    }
}

// --- GET REQUEST HANDLING (Load Data) ---

// 1. Fetch Sidebar Data
$my_tests = [];
$public_tests = [];

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT quiz_id, title, (SELECT COUNT(*) FROM matching_pairs mp JOIN question q ON mp.question_id = q.question_id WHERE q.quiz_id = quiz.quiz_id) as q_count FROM quiz WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$current_user_id]);
    $my_tests = $stmt->fetchAll();
}

$stmt = $pdo->query("SELECT quiz_id, title, (SELECT COUNT(*) FROM matching_pairs mp JOIN question q ON mp.question_id = q.question_id WHERE q.quiz_id = quiz.quiz_id) as q_count FROM quiz WHERE is_public = 1 ORDER BY created_at DESC");
$public_tests = $stmt->fetchAll();

// 2. Determine Mode & Load Workspace Data
if (isset($_GET['quiz_id'])) {
    $req_qid = $_GET['quiz_id'];
    $req_mode = $_GET['mode'] ?? 'view';

    // Fetch Quiz Info
    $stmt = $pdo->prepare("SELECT * FROM quiz WHERE quiz_id = ?");
    $stmt->execute([$req_qid]);
    $fetched_quiz = $stmt->fetch();

    if ($fetched_quiz) {
        $quiz_data = $fetched_quiz;

        // Fetch Prompt
        $stmt = $pdo->prepare("SELECT * FROM question WHERE quiz_id = ?");
        $stmt->execute([$req_qid]);
        $q_data = $stmt->fetch();
        $prompt_data = $q_data['user_prompt'] ?? '';
        $q_db_id = $q_data['question_id'] ?? 0;

        // Fetch Pairs
        $stmt = $pdo->prepare("SELECT * FROM matching_pairs WHERE question_id = ?");
        $stmt->execute([$q_db_id]);
        $pairs_data = $stmt->fetchAll();

        // Access Control
        if ($req_mode === 'edit') {
            if ($fetched_quiz['user_id'] == $current_user_id) {
                $mode = 'edit';
            } else {
                $mode = 'view'; // Fallback if trying to edit someone else's test
            }
        } else {
            $mode = 'view';
        }
    }
}

// --- VIEW LOGIC: Shuffling for Take Mode ---
$left_col_items = $pairs_data;
$right_col_items = $pairs_data;

if ($mode === 'view') {
    shuffle($right_col_items);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Quiz Dashboard</title>
    <link rel="stylesheet" href="assets/index.css">
</head>

<body>

    <div class="sidebar">
        <div class="sb-row-1">
            <div class="user-info">
                <span class="user-icon">👤</span>
                <span class="username"><?php echo htmlspecialchars($current_username); ?></span>
            </div>

            <a href="pages/logout.php" class="logout-link" title="Logout">
                <span class="logout-icon">⏻</span>
            </a>
        </div>

        <div class="sb-main-content">
            <details class="sidebar-section" id="details-my-tests">
                <summary class="sb-header">
                    <span class="chevron">›</span> My Tests
                </summary>
                <div class="sb-list-container">
                    <?php if (empty($my_tests)): ?>
                        <div style="padding:15px; color:#7f8c8d; font-size:0.85em;">No tests created.</div>
                    <?php else: ?>
                        <?php foreach ($my_tests as $test): ?>
                            <div class="quiz-item-container">

                                <button class="quiz-btn"
                                    onclick="window.location.href='index.php?quiz_id=<?= $test['quiz_id'] ?>&mode=view'">
                                    <div class="quiz-title">
                                        📄
                                        <?= htmlspecialchars($test['title']) ?>
                                    </div>
                                    <span class="quiz-meta">
                                        <?= $test['q_count'] ?> Questions
                                    </span>
                                </button>

                                <button class="delete-btn"
                                    onclick="if(confirm('Delete this test?')) window.location.href='pages/delete_quiz.php?id=<?= $test['quiz_id'] ?>'">
                                    🗑️
                                </button>

                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </details>

            <details class="sidebar-section" id="details-public-tests">
                <summary class="sb-header">
                    <span class="chevron">›</span> Public Tests
                </summary>
                <div class="sb-list-container">
                    <?php foreach ($public_tests as $test): ?>
                        <button class="quiz-btn"
                            onclick="window.location.href='index.php?quiz_id=<?= $test['quiz_id'] ?>&mode=view'">
                            <div class="quiz-title">📄
                                <?= htmlspecialchars($test['title']) ?>
                            </div>
                            <span class="quiz-meta">
                                <?= $test['q_count'] ?> Questions
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </details>

            <details class="sidebar-section" id="details-attempt-history">
                <summary class="sb-header">
                    <span class="chevron">›</span> Attempt History
                </summary>
                <div class="sb-list-container">
                    <?php if (empty($attempt_history)): ?>
                        <div style="padding:15px; color:#7f8c8d; font-size:0.85em;">No attempts recorded.</div>
                    <?php else: ?>
                        <?php foreach ($attempt_history as $attempt): ?>
                            <button class="quiz-btn"
                                onclick="window.location.href='index.php?attempt_id=<?= $attempt['attempt_id'] ?>&mode=review'">
                                <div class="quiz-title">🕒
                                    <?= htmlspecialchars($attempt['title']) ?>
                                </div>
                                <span class="quiz-meta">Score:
                                    <?= $attempt['score'] ?>/
                                    <?= $attempt['q_count'] ?> -
                                    <?= date('M j', strtotime($attempt['taken_at'])) ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <div class="sb-footer">
            <a href="index.php" class="btn create-btn">+Create New</a>
        </div>
    </div>

    <div class="workspace">
        <form id="quizForm" method="POST" action="index.php" style="height:100%; display:flex; flex-direction:column;"
            data-total="<?= count($pairs_data) ?>">
            <input type="hidden" name="action" value="save_quiz">
            <input type="hidden" name="quiz_id" value="<?php echo $quiz_data['quiz_id']; ?>">
            <input type="hidden" name="match_results" id="match_results">

            <div class="ws-row">
                <input type="text" id="input-title" name="title" maxlength="50" placeholder="Quiz Title"
                    value="<?php echo htmlspecialchars($quiz_data['title']); ?>" <?php echo ($mode === 'view' || $mode === 'review') ? 'readonly' : ''; ?> required>
            </div>

            <div class="ws-row">
                <textarea id="input-desc" name="description" placeholder="Description..." <?php echo ($mode === 'view' || $mode === 'review') ? 'readonly' : ''; ?>><?php echo htmlspecialchars($quiz_data['description']); ?></textarea>
            </div>

            <div class="ws-row">
                <textarea id="input-prompt" name="user_prompt" maxlength="100"
                    placeholder="Instructions (e.g., Match the capitals to the countries)" <?php echo ($mode === 'view' || $mode === 'review') ? 'readonly' : ''; ?>><?php echo htmlspecialchars($prompt_data); ?></textarea>
            </div>

            <div class="qa-container">

                <?php if ($mode === 'review'): ?>
                    <div class="view-layout review-mode">
                        <canvas id="connection-canvas"></canvas>

                        <div class="col-left">
                            <?php foreach ($left_col_items as $pair):
                                // Determine if this specific item was matched correctly
                                $userMatchRId = $saved_answers[$pair['pair_id']] ?? null;
                                $isCorrect = ($userMatchRId == $pair['pair_id']);
                                $statusClass = $isCorrect ? 'correct-border' : 'wrong-border';
                                ?>
                                <div class="view-item left-item <?= $statusClass ?>" id="L_<?= $pair['pair_id'] ?>">
                                    <div class="text-part">
                                        <?= htmlspecialchars($pair['left_text']) ?>
                                        <?php if (!$isCorrect): ?>
                                            <div class="correct-answer-hint">Correct:
                                                <?= htmlspecialchars($pair['right_text']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="radio-part">
                                        <input type="radio" checked disabled>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-right">
                            <?php foreach ($right_col_items as $pair): ?>
                                <div class="view-item right-item" id="R_<?= $pair['pair_id'] ?>">
                                    <div class="radio-part">
                                        <input type="radio" disabled>
                                    </div>
                                    <div class="text-part">
                                        <?= htmlspecialchars($pair['right_text']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <script>
                        // Ensure matches is global and available immediately
                        const savedMatches = <?= json_encode($saved_answers) ?>;

                        // We use a small delay to ensure the DOM and Canvas context are fully ready
                        window.addEventListener('load', () => {
                            console.log("Loading Review Mode with matches:", savedMatches);

                            // 1. Assign the data
                            matches = savedMatches || {};

                            // 2. Force a canvas resize to match the review layout
                            if (typeof resizeCanvas === "function") {
                                resizeCanvas();
                            }

                            // 3. Draw the lines
                            if (typeof drawAllLines === "function") {
                                drawAllLines();
                            }
                        });
                    </script>

                <?php elseif ($mode === 'view'): ?>
                    <div class="view-layout">
                        <canvas id="connection-canvas"></canvas>

                        <div class="col-left">
                            <?php foreach ($left_col_items as $pair): ?>
                                <div class="view-item left-item" id="L_<?php echo $pair['pair_id']; ?>">
                                    <div class="text-part">
                                        <?php echo htmlspecialchars($pair['left_text']); ?>
                                    </div>
                                    <div class="radio-part">
                                        <input type="radio" name="match_L_<?php echo $pair['pair_id']; ?>"
                                            onclick="handleMatchSelect('L', <?php echo $pair['pair_id']; ?>)">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-right">
                            <?php foreach ($right_col_items as $pair): ?>
                                <div class="view-item right-item" id="R_<?php echo $pair['pair_id']; ?>">
                                    <div class="radio-part">
                                        <input type="radio" name="match_R_<?php echo $pair['pair_id']; ?>"
                                            onclick="handleMatchSelect('R', <?php echo $pair['pair_id']; ?>)">
                                    </div>
                                    <div class="text-part">
                                        <?php echo htmlspecialchars($pair['right_text']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="user-matches"></div>

                <?php else: ?>
                    <div class="edit-layout" id="pairs-wrapper">
                        <?php if (empty($pairs_data)): ?>
                            <div class="pair-row">
                                <input type="text" name="left_text[]" class="input-q" placeholder="Question (Left)"
                                    maxlength="100" required>
                                <input type="text" name="right_text[]" class="input-a" placeholder="Answer (Right)"
                                    maxlength="100" required>
                                <button type="button" onclick="this.parentElement.remove()"
                                    style="background:#e74c3c; color:white; border:none; padding:5px 10px; cursor:pointer;">X</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pairs_data as $pair): ?>
                                <div class="pair-row">
                                    <input type="text" name="left_text[]" class="input-q"
                                        value="<?php echo htmlspecialchars($pair['left_text']); ?>" maxlength="100" required>
                                    <input type="text" name="right_text[]" class="input-a"
                                        value="<?php echo htmlspecialchars($pair['right_text']); ?>" maxlength="100" required>
                                    <button type="button" onclick="this.parentElement.remove()"
                                        style="background:#e74c3c; color:white; border:none; padding:5px 10px; cursor:pointer;">X</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ws-row add-btn-row <?php echo ($mode === 'view' || $mode === 'review') ? 'hidden' : ''; ?>">
                <button type="button" class="btn-add" onclick="addNewPair()">+ Add Pair</button>
            </div>

            <div class="actions-row">
                <div>
                    <?php if ($mode !== 'view' && $mode !== 'review'): ?>
                        <label>
                            <input type="checkbox" name="is_private" <?php echo ($quiz_data['is_public'] == 0) ? 'checked' : ''; ?>>
                            Private
                        </label>
                    <?php elseif ($mode === 'view' && $is_logged_in && $quiz_data['user_id'] == $current_user_id): ?>
                        <a href="index.php?quiz_id=<?php echo $quiz_data['quiz_id']; ?>&mode=edit" class="btn btn-edit">Edit
                            Quiz</a>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:10px;">
                    <?php if ($mode === 'create'): ?>
                        <button type="submit" class="btn btn-save">Save Quiz</button>

                    <?php elseif ($mode === 'edit'): ?>
                        <a href="index.php?quiz_id=<?php echo $quiz_data['quiz_id']; ?>&mode=view" class="btn btn-take">Take
                            Quiz</a>
                        <button type="submit" class="btn btn-save">Save Changes</button>

                    <?php elseif ($mode === 'view'): ?>
                        <button type="button" class="btn btn-done" onclick="calculateScore()">Done</button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if (isset($_GET['score'])): ?>
        <div id="scoreModal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <strong>Quiz Complete!</strong>
                    <span class="close-btn" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="score-circle">
                        <span class="score-num">
                            <?php echo htmlspecialchars($_GET['score']); ?>
                        </span>
                        <span class="score-total">/
                            <?php echo htmlspecialchars($_GET['total']); ?>
                        </span>
                    </div>
                    <p>Great job completing the quiz!</p>
                </div>
                <div class="modal-footer">
                    <button class="btn-close" onclick="closeModal()">Dismiss</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div id="delete-toast" style="display:none;">🗑️ Quiz has been deleted successfully.</div>
    <script src="assets/index.js?v=1.1"></script>
</body>

</html>