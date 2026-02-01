<?php
session_start();
require_once 'db.php';

// Helpers
function h($s){ return htmlspecialchars($s, ENT_QUOTES); }
$pdo = getDBConnection();

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';

// Load lists for sidebar
// My Tests (only if logged in)
$myTests = [];
if ($user_id) {
    $stmt = $pdo->prepare("SELECT q.quiz_id, q.title, q.description,
        (SELECT COUNT(*) FROM question qu JOIN matching_pairs mp ON qu.question_id = mp.question_id WHERE qu.quiz_id = q.quiz_id GROUP BY qu.quiz_id) AS num_questions
        FROM quiz q
        WHERE q.user_id = ?
        ORDER BY q.created_at DESC");
    $stmt->execute([$user_id]);
    $myTests = $stmt->fetchAll();
}

// Public tests
$publicTests = [];
$stmt = $pdo->prepare("SELECT q.quiz_id, q.title, q.description,
    (SELECT COUNT(*) FROM question qu JOIN matching_pairs mp ON qu.question_id = mp.question_id WHERE qu.quiz_id = q.quiz_id GROUP BY qu.quiz_id) AS num_questions
    FROM quiz q
    WHERE q.is_public = 1
    ORDER BY q.created_at DESC");
$stmt->execute();
$publicTests = $stmt->fetchAll();

// Determine selected quiz and mode
$quiz_id = isset($_GET['quiz_id']) ? intval($_GET['quiz_id']) : null;
$mode = $_GET['mode'] ?? 'create'; // create, view, edit

$editing_quiz = null;
$question = null;
$pairs = [];

if ($quiz_id) {
    // load quiz
    $stmt = $pdo->prepare("SELECT * FROM quiz WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $editing_quiz = $stmt->fetch();

    // load question (assuming one question row per quiz)
    if ($editing_quiz) {
        $stmt = $pdo->prepare("SELECT * FROM question WHERE quiz_id = ? LIMIT 1");
        $stmt->execute([$quiz_id]);
        $question = $stmt->fetch();

        if ($question) {
            // load pairs ordered by position (or pair_id)
            $stmt = $pdo->prepare("SELECT * FROM matching_pairs WHERE question_id = ? ORDER BY pair_id ASC");
            $stmt->execute([$question['question_id']]);
            $pairs = $stmt->fetchAll();
        }
    }
}

// Handle create/save new quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_new') {
    // Save new quiz (Create Mode)
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $user_prompt = trim($_POST['user_prompt'] ?? '');
    $is_public = isset($_POST['is_public']) && $_POST['is_public'] == '1' ? 1 : 0;

    // pairs arrays
    $lefts = $_POST['left'] ?? [];
    $rights = $_POST['right'] ?? [];

    if (!$user_id) {
        // guest -> redirect to login page
        header("Location: pages/login.php");
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO quiz (user_id, title, description, is_public) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $title, $description, $is_public]);
        $new_quiz_id = $pdo->lastInsertId();

        // insert question (single)
        $stmt = $pdo->prepare("INSERT INTO question (quiz_id, user_prompt) VALUES (?, ?)");
        $stmt->execute([$new_quiz_id, $user_prompt]);
        $new_question_id = $pdo->lastInsertId();

        // insert pairs
        $pos = 1;
        $mpStmt = $pdo->prepare("INSERT INTO matching_pairs (question_id, left_text, right_text, position) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < count($lefts); $i++) {
            $l = trim($lefts[$i]);
            $r = trim($rights[$i]);
            if ($l === '' && $r === '') continue;
            $mpStmt->execute([$new_question_id, $l, $r, (string)$pos]);
            $pos++;
        }

        $pdo->commit();
        // Redirect to view mode for new quiz
        header("Location: index.php?quiz_id=" . intval($new_quiz_id) . "&mode=view");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Save failed: " . $e->getMessage();
    }
}

// Handle edit-save update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_edit' && isset($_POST['quiz_id'])) {
    $quizId = intval($_POST['quiz_id']);
    // Ensure current user is owner
    $stmt = $pdo->prepare("SELECT user_id FROM quiz WHERE quiz_id = ?");
    $stmt->execute([$quizId]);
    $owner = $stmt->fetchColumn();
    if (!$owner || $owner != $user_id) {
        $error = "Not authorized to edit this quiz.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $user_prompt = trim($_POST['user_prompt'] ?? '');
        $is_public = isset($_POST['is_public']) && $_POST['is_public'] == '1' ? 1 : 0;
        $lefts = $_POST['left'] ?? [];
        $rights = $_POST['right'] ?? [];

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE quiz SET title = ?, description = ?, is_public = ? WHERE quiz_id = ?");
            $stmt->execute([$title, $description, $is_public, $quizId]);

            // update/insert question
            $stmt = $pdo->prepare("SELECT question_id FROM question WHERE quiz_id = ? LIMIT 1");
            $stmt->execute([$quizId]);
            $qId = $stmt->fetchColumn();
            if ($qId) {
                $stmt = $pdo->prepare("UPDATE question SET user_prompt = ? WHERE question_id = ?");
                $stmt->execute([$user_prompt, $qId]);
                // Delete old pairs and re-insert to keep it simple
                $stmt = $pdo->prepare("DELETE FROM matching_pairs WHERE question_id = ?");
                $stmt->execute([$qId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO question (quiz_id, user_prompt) VALUES (?, ?)");
                $stmt->execute([$quizId, $user_prompt]);
                $qId = $pdo->lastInsertId();
            }

            $mpStmt = $pdo->prepare("INSERT INTO matching_pairs (question_id, left_text, right_text, position) VALUES (?, ?, ?, ?)");
            $pos = 1;
            for ($i = 0; $i < count($lefts); $i++) {
                $l = trim($lefts[$i]);
                $r = trim($rights[$i]);
                if ($l === '' && $r === '') continue;
                $mpStmt->execute([$qId, $l, $r, (string)$pos]);
                $pos++;
            }

            $pdo->commit();
            header("Location: index.php?quiz_id=" . intval($quizId) . "&mode=view");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Update failed: " . $e->getMessage();
        }
    }
}

// Handle taking the quiz (Done button in view mode) - store attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_attempt' && isset($_POST['quiz_id'])) {
    $quizId = intval($_POST['quiz_id']);
    // gather user choices: expecting inputs named choice_left_{left_pair_id} => right_pair_id
    $answers = [];
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'choice_left_') === 0) {
            $left_pair_id = intval(substr($k, strlen('choice_left_')));
            $chosen_right_pair_id = intval($v);
            $answers[$left_pair_id] = $chosen_right_pair_id;
        }
    }

    // if no user_id, set null (guests can attempt but we store user_id null)
    $attempt_user_id = $user_id ?? null;

    try {
        $pdo->beginTransaction();

        // score calculation: each left where chosen_right_pair_id == left_pair_id is correct
        $score = 0;
        $total = count($answers);

        // fetch correct mapping for lefts
        // matching_pairs(pair_id, left_text, right_text, question_id)
        $mpStmt = $pdo->prepare("SELECT pair_id FROM matching_pairs WHERE question_id = (SELECT question_id FROM question WHERE quiz_id = ? LIMIT 1)");
        $mpStmt->execute([$quizId]);
        $validPairs = $mpStmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($answers as $left_pid => $chosen_right_pid) {
            if ($left_pid === $chosen_right_pid) $score++;
        }

        // store attempt
        $stmt = $pdo->prepare("INSERT INTO attempts (user_id, quiz_id, score) VALUES (?, ?, ?)");
        $stmt->execute([$attempt_user_id, $quizId, $score]);
        $attempt_id = $pdo->lastInsertId();

        // store attempt answers
        $insertAA = $pdo->prepare("INSERT INTO attempt_answer (attempt_id, pair_id, chosen_text, is_correct) VALUES (?, ?, ?, ?)");
        foreach ($answers as $left_pid => $chosen_right_pid) {
            // get chosen right text
            $stmt = $pdo->prepare("SELECT right_text FROM matching_pairs WHERE pair_id = ?");
            $stmt->execute([$chosen_right_pid]);
            $chosen_text = $stmt->fetchColumn();
            $is_correct = ($left_pid === $chosen_right_pid) ? 1 : 0;
            $insertAA->execute([$attempt_id, $left_pid, $chosen_text, $is_correct]);
        }

        $pdo->commit();
        $success = "Attempt saved. Score: {$score} / {$total}";
        // After submit, show view mode again
        header("Location: index.php?quiz_id=$quizId&mode=view&msg=" . urlencode($success));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Attempt save failed: " . $e->getMessage();
    }
}

// Re-load updated data if we saved
if ($quiz_id) {
    $stmt = $pdo->prepare("SELECT * FROM quiz WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $editing_quiz = $stmt->fetch();
    if ($editing_quiz) {
        $stmt = $pdo->prepare("SELECT * FROM question WHERE quiz_id = ? LIMIT 1");
        $stmt->execute([$quiz_id]);
        $question = $stmt->fetch();
        if ($question) {
            $stmt = $pdo->prepare("SELECT * FROM matching_pairs WHERE question_id = ? ORDER BY pair_id ASC");
            $stmt->execute([$question['question_id']]);
            $pairs = $stmt->fetchAll();
        }
    }
}

// For view mode: prepare shuffled right side
$shuffled_right = [];
if ($mode === 'view' && !empty($pairs)) {
    $shuffled_right = $pairs;
    // shuffle while preserving pair_id
    shuffle($shuffled_right);
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Quiz Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root{--sidebar-width:320px;--gap:12px;--accent:#2563eb}
    html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial}
    .container{display:flex;height:100vh;}
    /* Column 1 (Sidebar) */
    .sidebar{width:var(--sidebar-width);min-width:260px;background:#f5f7fb;padding:16px;box-sizing:border-box;display:flex;flex-direction:column;gap:var(--gap)}
    .sidebar .row{background:white;padding:12px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
    .user-row{height:10vh;display:flex;align-items:center;justify-content:center;font-weight:600}
    .list-row{overflow:auto}
    .test-button{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;margin:6px 0;border-radius:6px;border:1px solid #e6e9f0;background:transparent;cursor:pointer;}
    .test-button:hover{background:#eef2ff}
    .test-button a{display:block;text-decoration:none;color:inherit;width:100%}
    .test-title{font-weight:600}
    .test-count{font-size:0.85rem;color:#666}

    /* Column 2 (Main) */
    .main{flex:1;padding:18px;box-sizing:border-box;display:flex;flex-direction:column;gap:var(--gap)}
    .main .row{background:white;padding:12px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
    .title-row{height:auto}
    .desc-row{height:140px}
    .prompt-row{height:64px}
    .qa-row{flex:1;overflow:auto}
    .add-row{height:48px;display:flex;align-items:center}
    .actions-row{height:60px;display:flex;align-items:center;justify-content:space-between;gap:12px}

    input[type="text"], textarea {width:100%;padding:8px;border:1px solid #d7dbe8;border-radius:6px;font-size:1rem;box-sizing:border-box}
    textarea[readonly], input[readonly] {background:#f7f9fc}
    .pair-row{display:flex;gap:10px;margin-bottom:8px}
    .pair-row input{width:100%}
    .pairs-columns{display:flex;gap:10px}
    .pairs-columns > div{flex:1}
    .small{font-size:0.9rem;color:#444}
    .btn{padding:8px 12px;border-radius:6px;border:none;cursor:pointer}
    .btn-primary{background:var(--accent);color:white}
    .btn-ghost{background:transparent;border:1px solid #cfd6ef}
    .checkbox{display:flex;align-items:center;gap:6px}

    /* View mode special layout */
    .view-qa{display:flex;gap:10px}
    .view-left,.view-right{flex:1;background:transparent;padding:8px;border-radius:6px}
    .view-item{display:flex;align-items:center;justify-content:space-between;padding:8px;border-radius:6px;border:1px solid #eef2ff;margin-bottom:8px;background:#fff}
    .radio-area{width:10%;text-align:center}
    .text-area{width:90%}
    .flex-row{display:flex;gap:8px;align-items:center}
    .muted{color:#666;font-size:0.9rem}

    /* scrollbars */
    .list-row::-webkit-scrollbar, .qa-row::-webkit-scrollbar {height:8px;width:8px}
    .list-row::-webkit-scrollbar-thumb, .qa-row::-webkit-scrollbar-thumb {background:#d6d8e8;border-radius:8px}
</style>
</head>
<body>
<div class="container">
    <aside class="sidebar">
        <div class="row user-row">
            <div>
                <div class="small">User</div>
                <div style="font-size:1.1rem;margin-top:4px;font-weight:700"><?= h($username) ?></div>
            </div>
        </div>

        <div class="row list-row" style="height:45vh;">
            <div style="font-weight:700;margin-bottom:8px">My Tests</div>
            <?php if (empty($myTests)): ?>
                <div class="muted">No tests yet.</div>
            <?php else: ?>
                <?php foreach($myTests as $t): ?>
                    <div class="test-button">
                        <a href="index.php?quiz_id=<?= intval($t['quiz_id']) ?>&mode=view">
                            <div class="test-title"><?= h($t['title']) ?></div>
                            <div class="test-count"><?= intval($t['num_questions'] ?: 0) ?> pairs</div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="row list-row" style="height:45vh;">
            <div style="font-weight:700;margin-bottom:8px">Public Tests</div>
            <?php if (empty($publicTests)): ?>
                <div class="muted">No public tests.</div>
            <?php else: ?>
                <?php foreach($publicTests as $t): ?>
                    <div class="test-button">
                        <a href="index.php?quiz_id=<?= intval($t['quiz_id']) ?>&mode=view">
                            <div class="test-title"><?= h($t['title']) ?></div>
                            <div class="test-count"><?= intval($t['num_questions'] ?: 0) ?> pairs</div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <main class="main">
        <!-- Row 1: Title -->
        <div class="row title-row">
            <?php if ($mode === 'view' && $editing_quiz): ?>
                <div style="font-weight:700;font-size:1.1rem"><?= h($editing_quiz['title']) ?></div>
            <?php else: ?>
                <form id="mainForm" method="post">
                    <input type="hidden" name="action" value="<?= $mode === 'edit' ? 'save_edit' : 'save_new' ?>">
                    <?php if ($mode === 'edit' && $editing_quiz): ?>
                        <input type="hidden" name="quiz_id" value="<?= intval($editing_quiz['quiz_id']) ?>">
                    <?php endif; ?>
                    <label class="small">Title (max 50 chars)</label>
                    <input type="text" name="title" maxlength="50" required value="<?= h($editing_quiz['title'] ?? '') ?>" <?= $mode === 'view' ? 'readonly' : '' ?>>
                </form>
            <?php endif; ?>
        </div>

        <!-- Row 2: Description -->
        <div class="row desc-row">
            <?php if ($mode === 'view' && $editing_quiz): ?>
                <div class="small">Description</div>
                <div style="margin-top:8px"><?= nl2br(h($editing_quiz['description'])) ?></div>
            <?php else: ?>
                <label class="small">Description</label>
                <textarea name="description" form="mainForm" rows="6" maxlength="1000" <?= $mode === 'view' ? 'readonly' : '' ?>><?= h($editing_quiz['description'] ?? '') ?></textarea>
            <?php endif; ?>
        </div>

        <!-- Row 3: User Prompt -->
        <div class="row prompt-row">
            <?php if ($mode === 'view' && $question): ?>
                <div class="small">Prompt</div>
                <div style="margin-top:8px"><?= nl2br(h($question['user_prompt'])) ?></div>
            <?php else: ?>
                <label class="small">User Prompt (max 100 chars)</label>
                <textarea name="user_prompt" form="mainForm" rows="2" maxlength="100"><?= h($question['user_prompt'] ?? '') ?></textarea>
            <?php endif; ?>
        </div>

        <!-- Row 4: Q&A Pairs -->
        <div class="row qa-row">
            <?php if ($mode === 'view' && !empty($pairs)): ?>
                <!-- VIEW / TAKE MODE: left items (questions) on left, shuffled right items on right
                     Left side: each left has radio-group to pick a right.
                     We'll render N x N radio matrix (one group per left).
                -->
                <div class="view-qa">
                    <div class="view-left">
                        <div class="small" style="margin-bottom:8px">Questions</div>
                        <?php foreach ($pairs as $left): ?>
                            <div class="view-item">
                                <div class="text-area"><?= h($left['left_text']) ?></div>
                                <div class="radio-area muted">#<?= intval($left['pair_id']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="view-right">
                        <div class="small" style="margin-bottom:8px">Answers (shuffled)</div>
                        <?php foreach ($shuffled_right as $r): ?>
                            <div class="view-item">
                                <div class="radio-area muted">#<?= intval($r['pair_id']) ?></div>
                                <div class="text-area"><?= h($r['right_text']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:12px;">
                    <div class="small">Match answers by selecting the right option for each question:</div>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="action" value="submit_attempt">
                        <input type="hidden" name="quiz_id" value="<?= intval($quiz_id) ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px">
                            <div>
                                <div class="small" style="margin-bottom:6px"><strong>Question</strong></div>
                                <?php foreach ($pairs as $left): ?>
                                    <div style="padding:8px;border:1px solid #eef2ff;border-radius:6px;margin-bottom:8px">
                                        <div><?= h($left['left_text']) ?></div>
                                        <div style="margin-top:6px" class="muted">Select matching answer:</div>
                                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                                            <?php foreach ($shuffled_right as $r): ?>
                                                <label style="display:flex;align-items:center;gap:6px">
                                                    <input type="radio" name="choice_left_<?= intval($left['pair_id']) ?>" value="<?= intval($r['pair_id']) ?>" required>
                                                    <span style="font-size:0.95rem"><?= h($r['right_text']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div>
                                <div class="small" style="margin-bottom:6px"><strong>Answers (for reference)</strong></div>
                                <?php foreach ($shuffled_right as $r): ?>
                                    <div style="padding:8px;border:1px solid #eef2ff;border-radius:6px;margin-bottom:8px"><?= h($r['right_text']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
                            <?php if ($user_id && $editing_quiz && $editing_quiz['user_id'] == $user_id): ?>
                                <a class="btn btn-ghost" href="index.php?quiz_id=<?= intval($quiz_id) ?>&mode=edit">Edit</a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">Done</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- CREATE or EDIT MODE: show pairs in 50/50 columns with add/remove -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <div class="small"><?= $mode === 'edit' ? 'Edit Pairs' : 'Create Pairs' ?></div>
                    <button class="btn btn-ghost" id="addPairBtn" type="button">+ Add Pair</button>
                </div>

                <form id="pairsForm" method="post" form="mainForm">
                    <div id="pairsContainer">
                        <?php
                        // if editing, use $pairs; if create default, create 3 blank pair rows
                        $initial = (!empty($pairs)) ? $pairs : [
                            ['pair_id' => '', 'left_text' => '', 'right_text' => ''],
                            ['pair_id' => '', 'left_text' => '', 'right_text' => ''],
                            ['pair_id' => '', 'left_text' => '', 'right_text' => ''],
                        ];
                        foreach ($initial as $idx => $p):
                        ?>
                        <div class="pair-row" data-index="<?= $idx ?>">
                            <input type="hidden" name="pair_id[]" value="<?= h($p['pair_id'] ?? '') ?>">
                            <input type="text" name="left[]" maxlength="100" placeholder="Question (left) - max 100 chars" value="<?= h($p['left_text'] ?? '') ?>">
                            <input type="text" name="right[]" maxlength="100" placeholder="Answer (right) - max 100 chars" value="<?= h($p['right_text'] ?? '') ?>">
                            <button type="button" class="btn btn-ghost removePairBtn">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Row 5: Add Button (hidden in view) -->
        <div class="row add-row" style="<?= $mode === 'view' ? 'display:none' : '' ?>">
            <div class="muted">Use the Add Pair button to add more matching pairs.</div>
        </div>

        <!-- Row 6: Actions -->
        <div class="row actions-row">
            <?php if ($mode === 'view'): ?>
                <div>
                    <?php if ($editing_quiz && $user_id && $editing_quiz['user_id'] == $user_id): ?>
                        <!-- Creator -->
                        <a class="btn btn-ghost" href="index.php?quiz_id=<?= intval($quiz_id) ?>&mode=edit">Edit</a>
                    <?php endif; ?>
                </div>

                <div class="muted">
                    <?php if (isset($_GET['msg'])) echo h($_GET['msg']); ?>
                </div>

            <?php elseif ($mode === 'edit'): ?>
                <div style="display:flex;gap:12px;align-items:center">
                    <label class="checkbox"><input type="checkbox" name="is_public" value="1" form="mainForm" <?= (!empty($editing_quiz) && $editing_quiz['is_public']) ? 'checked' : '' ?>> Public</label>
                </div>

                <div style="display:flex;gap:8px">
                    <a class="btn btn-ghost" href="index.php?quiz_id=<?= intval($quiz_id) ?>&mode=view">Take Quiz</a>
                    <button class="btn btn-primary" form="mainForm" type="submit">Save</button>
                </div>

            <?php else: // create mode ?>
                <div style="display:flex;gap:12px;align-items:center">
                    <label class="checkbox"><input type="checkbox" name="is_public" value="1" form="mainForm"> Public</label>
                </div>

                <div>
                    <button class="btn btn-primary" type="submit" form="mainForm">Save</button>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
// Dynamic add/remove pair rows
(function(){
    const addBtn = document.getElementById('addPairBtn');
    const container = document.getElementById('pairsContainer');
    addBtn && addBtn.addEventListener('click', () => {
        const idx = container.children.length;
        const div = document.createElement('div');
        div.className = 'pair-row';
        div.setAttribute('data-index', idx);
        div.innerHTML = `
            <input type="hidden" name="pair_id[]" value="">
            <input type="text" name="left[]" maxlength="100" placeholder="Question (left) - max 100 chars">
            <input type="text" name="right[]" maxlength="100" placeholder="Answer (right) - max 100 chars">
            <button type="button" class="btn btn-ghost removePairBtn">Remove</button>
        `;
        container.appendChild(div);
    });

    document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('removePairBtn')){
            const el = e.target.closest('.pair-row');
            el && el.remove();
        }
    });

    // prevent mainForm default submit if Save is pressed and no pairs
    const mainForm = document.getElementById('mainForm');
    mainForm && mainForm.addEventListener('submit', function(e){
        // Check pairs; only for create/edit saving
        const action = this.querySelector('input[name="action"]').value;
        if (action === 'save_new' || action === 'save_edit') {
            const lefts = document.querySelectorAll('input[name="left[]"]');
            let valid = false;
            lefts.forEach((i) => { if (i.value.trim() !== '') valid = true; });
            if (!valid) {
                e.preventDefault();
                alert('Add at least one pair before saving.');
            }
        }
    });

})();
</script>

<?php if (isset($error)): ?>
    <script>console.error(<?= json_encode($error) ?>); alert(<?= json_encode($error) ?>);</script>
<?php endif; ?>

</body>
</html>
