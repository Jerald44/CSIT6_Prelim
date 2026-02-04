<?php
require_once '../includes/db.php';
$pdo = getDBConnection();

if (isset($_GET['id'])) {
    $quiz_id = (int)$_GET['id'];

    try {
        $pdo->beginTransaction();

        // 1. Delete Attempt Answers (The deepest level)
        // We find attempt_ids linked to this quiz to clear their answers
        $stmt1 = $pdo->prepare("DELETE FROM attempt_answer WHERE attempt_id IN (SELECT attempt_id FROM attempts WHERE quiz_id = ?)");
        $stmt1->execute([$quiz_id]);

        // 2. Delete Attempts
        $stmt2 = $pdo->prepare("DELETE FROM attempts WHERE quiz_id = ?");
        $stmt2->execute([$quiz_id]);

        // 3. Delete Matching Pairs (Linked via question_id)
        $stmt3 = $pdo->prepare("DELETE FROM matching_pairs WHERE question_id IN (SELECT question_id FROM question WHERE quiz_id = ?)");
        $stmt3->execute([$quiz_id]);

        // 4. Delete Questions
        $stmt4 = $pdo->prepare("DELETE FROM question WHERE quiz_id = ?");
        $stmt4->execute([$quiz_id]);

        // 5. Finally, Delete the Quiz itself
        $stmt5 = $pdo->prepare("DELETE FROM quiz WHERE quiz_id = ?");
        $stmt5->execute([$quiz_id]);

        $pdo->commit();
        header("Location: ../index.php?status=deleted");
        exit;

    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Database Error: " . $e->getMessage());
    }
} else {
    header("Location: ../index.php");
    exit;
}