<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// Database configuration
$db_host = 'localhost';
$db_user = 'root';  // default XAMPP username
$db_pass = '';      // default XAMPP password is empty
$db_name = 'photomatch';

// Connect to database
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die(json_encode(['error' => 'Connection failed: ' . $e->getMessage()]));
}

// Handle different API endpoints
$action = $_GET['action'] ?? '';

switch($action) {
    case 'get_photos':
        getRandomPair();
        break;
    case 'vote':
        handleVote();
        break;
    case 'leaderboard':
        getLeaderboard();
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

function getRandomPair() {
    global $pdo;
    $stmt = $pdo->query("SELECT id, url, rating FROM photos ORDER BY RAND() LIMIT 2");
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['photos' => $photos]);
}

function handleVote() {
    global $pdo;
    
    if (!isset($_POST['winner_id']) || !isset($_POST['loser_id'])) {
        echo json_encode(['error' => 'Missing parameters']);
        return;
    }

    $winner_id = (int)$_POST['winner_id'];
    $loser_id = (int)$_POST['loser_id'];

    try {
        $pdo->beginTransaction();

        // Record the vote
        $stmt = $pdo->prepare("INSERT INTO votes (winner_id, loser_id) VALUES (?, ?)");
        $stmt->execute([$winner_id, $loser_id]);

        // Get current ratings
        $stmt = $pdo->prepare("SELECT id, rating FROM photos WHERE id IN (?, ?)");
        $stmt->execute([$winner_id, $loser_id]);
        $ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $winner_rating = 0;
        $loser_rating = 0;
        foreach ($ratings as $rating) {
            if ($rating['id'] == $winner_id) {
                $winner_rating = $rating['rating'];
            } else {
                $loser_rating = $rating['rating'];
            }
        }

        // Calculate new ratings
        $K = 32;
        $expected_score = 1 / (1 + pow(10, ($loser_rating - $winner_rating) / 400));
        $rating_change = round($K * (1 - $expected_score));

        // Update ratings
        $stmt = $pdo->prepare("UPDATE photos SET rating = rating + ? WHERE id = ?");
        $stmt->execute([$rating_change, $winner_id]);
        
        $stmt = $pdo->prepare("UPDATE photos SET rating = rating - ? WHERE id = ?");
        $stmt->execute([$rating_change, $loser_id]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getLeaderboard() {
    global $pdo;
    $stmt = $pdo->query("SELECT id, url, rating FROM photos ORDER BY rating DESC");
    $leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['leaderboard' => $leaderboard]);
}