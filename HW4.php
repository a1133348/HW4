<?php
// 強制開啟錯誤回報，方便排查 (A1133348 除錯用)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 引入 PHPMailer 核心檔案
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// ====== 資料庫連線設定 (A1133348) ======
$host = 'localhost';
$db = 'homework4_db';
$user = 'root';
$pass = ''; // XAMPP 預設密碼為空

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\PDOException $e) {
    die("【A1133348 系統提示】資料庫連線失敗: " . $e->getMessage());
}

$current_page = basename(__FILE__);

// 處理 C 部分：刪除名單
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'delete_email') {
    $delete_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($delete_id !== false && $delete_id !== null) {
        try {
            try {
                $stmt = $pdo->prepare("DELETE FROM emails WHERE no = ?");
                $stmt->execute([$delete_id]);
            } catch (\Exception $ex) {
                $stmt = $pdo->prepare("DELETE FROM emails WHERE id = ?");
                $stmt->execute([$delete_id]);
            }
            echo "<script>alert('【A1133348 提示】名單已成功刪除！'); window.location.href='{$current_page}';</script>";
        } catch (\Exception $e) {
            echo "<script>alert('刪除失敗：" . addslashes($e->getMessage()) . "'); window.location.href='{$current_page}';</script>";
        }
    }
    exit;
}

// 處理 A 部分：新增 Email 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'add_email') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if ($email) {
        try {
            try {
                $stmt = $pdo->prepare("INSERT INTO emails (email) VALUES (?)");
                $stmt->execute([$email]);
            } catch (\Exception $ex) {
                $stmt = $pdo->prepare("INSERT INTO emails (gmail) VALUES (?)");
                $stmt->execute([$email]);
            }
            echo "<script>alert('【A1133348 提示】Email 已寫入資料庫！'); window.location.href='{$current_page}';</script>";
        } catch (\Exception $e) {
            echo "<script>alert('加入失敗（可能重複）：" . addslashes($e->getMessage()) . "'); window.location.href='{$current_page}';</script>";
        }
    } else {
        echo "<script>alert('Email 格式錯誤！'); window.location.href='{$current_page}';</script>";
    }
    exit;
}

// API 1：獲取發信名單 (後端 1)
if (isset($_GET['action']) && trim($_GET['action']) === 'get_targets') {
    header('Content-Type: application/json');
    $mode = $_GET['mode'] ?? 'all';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

    $col = 'email';
    try { $pdo->query("SELECT email FROM emails LIMIT 1"); } catch (\Exception $e) { $col = 'gmail'; }

    if ($mode === 'random') {
        $stmt = $pdo->prepare("SELECT {$col} FROM emails ORDER BY RAND() LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->query("SELECT {$col} FROM emails");
    }
    $targets = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    echo json_encode(['targets' => $targets]);
    exit;
}

// API 2：負責「單筆」發信 (後端 2 - 已移除 sleep 避免重複延遲)
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $clean_input = str_replace("\xc2\xa0", " ", $raw_input);
    $input = json_decode($clean_input, true);

    if (isset($input['action']) && $input['action'] === 'send_single') {
        header('Content-Type: application/json');
        $to = isset($input['to']) ? trim($input['to']) : '';
        $custom_subject = $input['subject'] ?? '預設主旨';
        $custom_content = $input['content'] ?? '預設內容';

        if (empty($to)) {
            echo json_encode(['success' => false, 'msg' => '電子郵件為空值']);
            exit;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'kevin12041104@gmail.com';         // 你的 Gmail
            $mail->Password = 'dnac zfgh nioc cjmd';           // 你的應用程式密碼
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom('kevin12041104@gmail.com', 'A1133348 群發系統');
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $custom_subject;
            $mail->Body = nl2br(htmlspecialchars($custom_content));

            $mail->send();
            echo json_encode(['success' => true, 'msg' => "成功寄出"]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'msg' => "失敗: {$mail->ErrorInfo}"]);
        }
        exit;
    }
}

// 撈取名單列表
try {
    $stmt_list = $pdo->query("SELECT * FROM emails ORDER BY id ASC");
    $all_emails = $stmt_list->fetchAll();
} catch (Exception $e) {
    try { $stmt_list = $pdo->query("SELECT * FROM emails ORDER BY no ASC"); $all_emails = $stmt_list->fetchAll(); } catch (Exception $ex) { $all_emails = []; }
}
$total_emails = count($all_emails);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<title>A1133348 - 垃圾郵件寄送系統</title>
<style>
body { font-family: "Microsoft JhengHei", Arial, sans-serif; margin: 30px; background-color: #f5f7fa; }
h1 { color: #2c3e50; text-align: center; margin-bottom: 5px; }
.banner { text-align: center; color: #7f8c8d; font-size: 15px; margin-bottom: 25px; }
.section { margin-bottom: 30px; padding: 25px; border: 1px solid #e1e8ed; border-radius: 8px; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
h2 { color: #2980b9; border-bottom: 2px solid #ebf5fb; padding-bottom: 8px; margin-top: 0; }
label { display: inline-block; width: 130px; font-weight: bold; color: #34495e; }
input[type="text"], input[type="email"], input[type="number"], textarea { padding: 8px; width: 320px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
textarea { width: 500px; height: 120px; resize: vertical; }
.form-group { margin-bottom: 15px; }
button { padding: 9px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; }
.btn-start { background: #28a745; color: white; width: 100%; font-size: 16px; margin-top: 10px; }
.btn-start:hover { background: #218838; }
.btn-stop { background: #dc3545; color: white; width: 100%; font-size: 16px; margin-top: 10px; display: none; }
.btn-stop:hover { background: #c82333; }
.btn-add { background: #007BFF; color: white; padding: 8px 15px; margin-left: 10px; }
.btn-delete { background: #dc3545; color: white; padding: 5px 12px; font-size: 12px; }

#progress-section { display: none; margin-top: 20px; padding: 20px; background: #eceff1; border-radius: 6px; border: 1px solid #cfd8dc; }
.progress-container { background: #b0bec5; width: 100%; height: 25px; border-radius: 15px; overflow: hidden; margin: 12px 0; }
.progress-bar { background: #2ecc71; width: 0%; height: 100%; text-align: center; color: white; line-height: 25px; font-weight: bold; transition: width 0.3s; }
#log-box { max-height: 250px; overflow-y: auto; background: #2c3e50; color: #ecf0f1; padding: 15px; font-family: monospace; border-radius: 4px; font-size: 13px; border-left: 5px solid #ffc107; }
table { width: 100%; border-collapse: collapse; margin-top: 15px; }
table, th, td { border: 1px solid #e1e8ed; padding: 12px; text-align: left; }
th { background-color: #f8f9fa; }
</style>
</head>
<body>

<h1>垃圾郵件寄送系統</h1>
<div class="banner">開發學生學號：<strong>A1133348</strong></div>

<div class="section">
    <h2>A. 建構資料庫 (名單總數: <?php echo $total_emails; ?> 筆)</h2>
    <form action="<?php echo $current_page; ?>" method="POST">
        <input type="hidden" name="action" value="add_email">
        <div class="form-group">
            <label>新增 Email 位址:</label>
            <input type="email" name="email" required placeholder="例如: a1133348@mail.nuk.edu.tw">
            <button type="submit" class="btn-add">加入資料庫</button>
        </div>
    </form>
</div>

<div class="section">
    <h2>B. 郵件內容與無限循環設定</h2>
    <form id="mailForm" onsubmit="startSending(event)" novalidate>
        <fieldset style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
            <legend style="padding: 0 5px; font-weight: bold; color: #e74c3c;">郵件內容介面</legend>
            <div class="form-group">
                <label for="mail_subject">郵件主旨:</label>
                <input type="text" id="mail_subject" required value="【高大 A1133348】無限轟炸測試信">
            </div>
            <div class="form-group" style="display: flex; align-items: flex-start;">
                <label for="mail_content">郵件內容:</label>
                <textarea id="mail_content" required>此系統正在執行 A1133348 專案之重複發送測試...</textarea>
            </div>
        </fieldset>

        <div class="form-group">
            <label>① 篩選模式:</label>
            <input type="radio" id="mode_all" name="mode" value="all" checked onclick="document.getElementById('limit_field').style.display='none'">
            <label for="mode_all" style="width:auto; font-weight: normal; margin-right: 15px;">跑完一輪全部，再重複下一輪</label>

            <input type="radio" id="mode_rand" name="mode" value="random" onclick="document.getElementById('limit_field').style.display='block'">
            <label for="mode_rand" style="width:auto; font-weight: normal;">每輪隨機抽幾筆，無限抽發</label>
        </div>

        <div class="form-group" id="limit_field" style="display:none;">
            <label>隨機抽取筆數:</label>
            <input type="number" id="limit" value="3" min="1" max="<?php echo $total_emails; ?>">
        </div>

        <div class="form-group">
            <label>② 發信間隔(秒):</label>
            <input type="number" id="interval" value="3" min="0" step="0.1">
        </div>

        <button type="submit" id="submitBtn" class="btn-start">開始 A1133348 無限循環發送</button>
        <button type="button" id="stopBtn" class="btn-stop" onclick="stopSending()">停止發送任務</button>
    </form>

    <div id="progress-section">
        <h3 style="color: #d35400; margin-top: 0;">● A1133348 轟炸模式運行中...</h3>
        <div id="progress-text">初始化中...</div>
        <div class="progress-container">
            <div class="progress-bar" id="p-bar">0%</div>
        </div>
        <div id="log-box"></div>
    </div>
</div>

<div class="section">
    <h2>C. 資料庫名單列表</h2>
    <?php if ($total_emails > 0): ?>
    <table>
        <thead>
            <tr>
                <th>No (流水號)</th>
                <th>Email (電子郵件名單)</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all_emails as $row): ?>
        <?php 
            $row_id = $row['no'] ?? $row['id'] ?? 0; 
            $row_email = $row['email'] ?? $row['gmail'] ?? '無資料';
        ?>
        <tr>
            <td><?php echo $row_id; ?></td>
            <td><?php echo htmlspecialchars($row_email); ?></td>
            <td>
                <form action="<?php echo $current_page; ?>" method="POST" style="margin:0; padding:0;" onsubmit="return confirm('確定要刪除此筆 Email 名單嗎？');">
                    <input type="hidden" name="action" value="delete_email">
                    <input type="hidden" name="id" value="<?php echo $row_id; ?>">
                    <button type="submit" class="btn-delete">刪除</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color: #999;">目前資料庫中沒有任何名單，請使用上方表單新增。</p>
    <?php endif; ?>
</div>

<script>
let sendQueue = [];
let currentIndex = 0;
let roundCount = 1;
let isRunning = false;
const currentPage = '<?php echo $current_page; ?>';

function startSending(e) {
    e.preventDefault();
    if (isRunning) return;

    isRunning = true;
    roundCount = 1;
    currentIndex = 0;

    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('stopBtn').style.display = 'inline-block';
    document.getElementById('progress-section').style.display = 'block';
    document.getElementById('log-box').innerHTML = '<span style="color:#ffc107;">[A1133348 預熱] 正在啟動發送核心...</span><br>';

    fetchListAndSend();
}

function fetchListAndSend() {
    if (!isRunning) return;

    const mode = document.querySelector('input[name="mode"]:checked').value;
    const limit = document.getElementById('limit').value;

    document.getElementById('log-box').innerHTML += `<br><span style="color:#00ffff;">【第 ${roundCount} 輪開始】正在向後端同步最新發送名單...</span><br>`;

    // 呼叫後端 API 1：取得要發信的目標
    fetch(`${currentPage}?action=get_targets&mode=${mode}&limit=${limit}`)
    .then(res => res.json())
    .then(data => {
        sendQueue = data.targets;
        currentIndex = 0;

        if (!sendQueue || sendQueue.length === 0) {
            document.getElementById('log-box').innerHTML += '<span style="color:red;">錯誤: 資料庫名單為空，請先加入 Email！</span><br>';
            stopSending();
            return;
        }
        sendNextSingle();
    })
    .catch(err => {
        document.getElementById('log-box').innerHTML += '<span style="color:red;">連線失敗，3秒後重新嘗試...</span><br>';
        setTimeout(fetchListAndSend, 3000);
    });
}

function sendNextSingle() {
    if (!isRunning) return;

    // 當目前輪次的名單全數發送完畢，自動跳下一輪（達成無限循環）
    if (currentIndex >= sendQueue.length) {
        roundCount++;
        fetchListAndSend();
        return;
    }

    const currentEmail = sendQueue[currentIndex].trim();
    const subject = document.getElementById('mail_subject').value;
    const content = document.getElementById('mail_content').value;
    const interval = parseFloat(document.getElementById('interval').value);

    // 即時計算並更新進度條比例 (%)
    const progressPercent = Math.floor(((currentIndex + 1) / sendQueue.length) * 100);
    const progressBar = document.getElementById('p-bar');
    progressBar.style.width = progressPercent + '%';
    progressBar.innerText = progressPercent + '%';
    document.getElementById('progress-text').innerText = `第 ${roundCount} 輪 - 進度: ${progressPercent}% | 正在發送 ${currentIndex + 1}/${sendQueue.length} 筆：${currentEmail}`;

    // 呼叫後端 API 2：負責單筆發信
    fetch(currentPage, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'send_single',
            to: currentEmail,
            subject: subject,
            content: content
        })
    })
    .then(res => res.json())
    .then(result => {
        const logBox = document.getElementById('log-box');
        if (result && result.success) {
            logBox.innerHTML += `[A1133348 輪次:${roundCount}] 成功寄給 -> <span style="color:#00ff00;">${currentEmail}</span><br>`;
        } else {
            logBox.innerHTML += `[A1133348 輪次:${roundCount}] <span style="color:#ff0000;">${result.msg}</span> -> ${currentEmail}<br>`;
        }
        logBox.scrollTop = logBox.scrollHeight;

        if (isRunning) {
            currentIndex++;
            // 【前端排程控制】完全依照設定的秒數間隔發送下一筆，且加入少許隨機變動滿足「隨機寄送時間」
            const randomFuzz = (Math.random() * 400) - 200; // 正負 0.2 秒誤差
            const nextWait = Math.max(0, (interval * 1000) + randomFuzz);
            setTimeout(sendNextSingle, nextWait); 
        }
    })
    .catch(err => {
        currentIndex++;
        setTimeout(sendNextSingle, interval * 1000);
    });
}

function stopSending() {
    isRunning = false;
    document.getElementById('submitBtn').style.display = 'inline-block';
    document.getElementById('stopBtn').style.display = 'none';
    document.getElementById('log-box').innerHTML += `<br><span style="color:#ff0000; font-weight:bold;">★ A1133348 任務提示：系統已手動停止發送。</span><br>`;
}
</script>
</body>
</html>