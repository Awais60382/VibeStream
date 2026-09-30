<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "vibestream";

$conn = new mysqli($servername,$username, $password,$dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

define('YOUTUBE_API_KEY', 'YOUR_YOUTUBE_API_KEY');

function getYouTubeDetails($videoId) {
    if (empty(YOUTUBE_API_KEY) || YOUTUBE_API_KEY === 'YOUR_YOUTUBE_API_KEY') {
        return null;
    }
    
    $url = "https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails,statistics&id={$videoId}&key=" . YOUTUBE_API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (!empty($data['items'][0])) {
        $item =$data['items'][0];
        return [
            'title'        => $item['snippet']['title'],
            'channelTitle' => $item['snippet']['channelTitle'],
            'publishedAt'  => date("M j, Y", strtotime($item['snippet']['publishedAt'])),
            'thumbnail'    => $item['snippet']['thumbnails']['high']['url'] ?? $item['snippet']['thumbnails']['default']['url'],
            'views'        => $item['statistics']['viewCount'] ?? 0
        ];
    }
    return null;
}

$action =$_GET['action'] ?? '';
$page   =$_GET['page'] ?? '';

if ($action === 'get_all') {
    header('Content-Type: application/json');
    $sql = "SELECT id, title, views FROM videos";
    $result =$conn->query($sql);$data = [];
    if ($result &&$result->num_rows > 0) {
        while ($row =$result->fetch_assoc()) {
            $data[$row['id']] = [
                "title" => $row['title'],
                "views" => (int)$row['views']
            ];
        }
    }
    echo json_encode($data);$conn->close();
    exit;
}

if ($action === 'watch') {
    header('Content-Type: application/json');
    $id =$_GET['id'] ?? '';
    if (!empty($id)) {
        $stmt =$conn->prepare("UPDATE videos SET views = views + 1 WHERE id = ?");
        $stmt->bind_param("s", $id);
        $stmt->execute();$stmt->close();
        
        $stmt_get =$conn->prepare("SELECT views FROM videos WHERE id = ?");
        $stmt_get->bind_param("s", $id);$stmt_get->execute();
        $result =$stmt_get->get_result();
        if ($row =$result->fetch_assoc()) {
            echo json_encode(["success" => true, "views" => (int)$row['views']]);
        } else {
            echo json_encode(["success" => false, "message" => "Video not found"]);
        }
        $stmt_get->close();
    } else {
        echo json_encode(["success" => false, "message" => "Invalid video ID"]);
    }
    $conn->close();
    exit;
}


if ($action === 'auth') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $usernameInput =$input['username'] ?? '';
    $isGuest =$input['isGuest'] ?? false;

    if ($isGuest || empty($usernameInput)) {$finalUser = "Guest_" . rand(1000, 9999);
        $role = "guest";
    } else {
        $finalUser = htmlspecialchars($usernameInput);$role = "user";
        
        $stmt =$conn->prepare("SELECT id, username, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $finalUser);$stmt->execute();
        $result =$stmt->get_result();
        
        if ($row =$result->fetch_assoc()) {
            $role =$row['role'];
        } else {
            $stmt_insert =$conn->prepare("INSERT INTO users (username, role) VALUES (?, ?)");
            $stmt_insert->bind_param("ss", $finalUser,$role);
            $stmt_insert->execute();$stmt_insert->close();
        }
        $stmt->close();
    }

    echo json_encode(["success" => true, "user" => ["username" => $finalUser, "role" => $role]]);$conn->close();
    exit;
}


if (isset($_POST['login']) || isset($_POST['login_submit']) ||$action === 'login_form') {
    $useremail =$_POST['useremail'] ?? '';
    $password =$_POST['password'] ?? '';

    $check_columns =$conn->query("SHOW COLUMNS FROM users LIKE 'email'");
    $has_email = ($check_columns &&$check_columns->num_rows > 0);

    if ($has_email) {
        $stmt =$conn->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $useremail,$useremail);
    } else {
        $stmt =$conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $useremail);
    }
    
    $stmt->execute();
    $result =$stmt->get_result();

    if ($row =$result->fetch_assoc()) {
        $dbPassword =$row['password'] ?? ($row['pass'] ?? ($row['user_password'] ?? ''));

        $passwordValid = false;
        if (!empty($dbPassword)) {
            if (password_verify($password, $dbPassword)) {
                $passwordValid = true;
            } elseif (hash_equals($dbPassword, $password)) {
                $passwordValid = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if ($upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?")) {
                    $upd->bind_param("si", $newHash, $row['id']);
                    $upd->execute();
                    $upd->close();
                }
            }
        }

        if ($passwordValid) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] =$row['id'] ?? 1;
            $_SESSION['username'] = $row['username'] ?? $useremail;
            $_SESSION['role'] =$row['role'] ?? 'user';

            if (strcasecmp($row["role"], "Admin") == 0) {
                $_SESSION["AdminSession"] = $row["username"];
                $_SESSION["AdminId"] = $row["id"]; 
                echo "<script>alert('Login successful!'); window.location.href='adminpanel/index.php';</script>";
                exit;
            } else {
                $_SESSION["UserSession"] = $row["username"];
                $_SESSION["UserId"] = $row["id"];
                echo "<script>alert('Login successful!'); window.location.href='index.php';</script>";
                exit;
            }
        } else {
            echo "<script>alert('Invalid password!'); window.location.href='login.php';</script>";
            exit;
        }
    } else {
        echo "<script>alert('User not found!'); window.location.href='login.php';</script>";
        exit;
    }
}

if (isset($_POST['signup']) || isset($_POST['signup_submit']) || $action === 'signup_form') {$username = trim($_POST['username'] ?? '');$email = trim($_POST['email'] ?? '');$password = $_POST['password'] ?? '';$confirm_password = $_POST['confirmpassword'] ?? ($_POST['confirm_password'] ?? '');

    if (empty($username) || empty($password)) {
        echo "<script>alert('Username and password are required!'); window.location.href='signup.php';</script>";
        exit;
    }

    if ($password !==$confirm_password) {
        echo "<script>alert('Passwords do not match!'); window.location.href='signup.php';</script>";
        exit;
    }

    $check_email_col =$conn->query("SHOW COLUMNS FROM users LIKE 'email'");
    $has_email_col = ($check_email_col &&$check_email_col->num_rows > 0);

    if ($has_email_col && !empty($email)) {
        $check =$conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $check->bind_param("ss", $username,$email);
    } else {
        $check =$conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check->bind_param("s", $username);
    }
    
    $check->execute();$check->store_result();

    if ($check->num_rows > 0) {
        echo "<script>alert('Username or Email already exists!'); window.location.href='signup.php';</script>";
        exit;
    }
    $check->close();

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    if ($has_email_col) {
        $stmt =$conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
        $stmt->bind_param("sss", $username, $email,$hashed_password);
    } else {
        $stmt =$conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
        $stmt->bind_param("ss", $username,$hashed_password);
    }

    if ($stmt->execute()) {
        echo "<script>alert('Account created successfully! Please login.'); window.location.href='login.php';</script>";
        exit;
    } else {
        echo "<script>alert('Error registering account. Please try again.'); window.location.href='signup.php';</script>";
        exit;
    }
    $stmt->close();
}


if (isset($_POST['post_type']) ||$action === 'upload') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $username =$_SESSION['username'] ?? ('Guest_' . rand(1000, 9999));
    $title = trim($_POST['title'] ?? '');
    $video_url = trim($_POST['video_url'] ?? ''); 
    $type = trim($_POST['post_type'] ?? 'video');

    if (empty($title) || empty($video_url)) {
        echo "<script>alert('Video Title and YouTube Video ID are required!'); window.location.href='upload.php';</script>";
        exit;
    }

    $existsStmt = $conn->prepare("SELECT id FROM videos WHERE id = ?");
    $existsStmt->bind_param("s", $video_url);
    $existsStmt->execute();
    $alreadyExists = $existsStmt->get_result()->num_rows > 0;
    $existsStmt->close();

    if ($alreadyExists) {
        $stmt = $conn->prepare("UPDATE videos SET title = ?, type = ?, username = ? WHERE id = ?");
        $stmt->bind_param("ssss", $title, $type, $username, $video_url);
    } else {
        $stmt = $conn->prepare("INSERT INTO videos (id, title, views, type, username) VALUES (?, ?, 0, ?, ?)");
        $stmt->bind_param("ssss", $video_url, $title, $type, $username);
    }

    if ($stmt->execute()) {
        echo "<script>alert('Published successfully!'); window.location.href='index.php';</script>";
    } else {
        echo "<script>alert('Error publishing video. Please check database columns.'); window.location.href='upload.php';</script>";
    }
    $stmt->close();
    exit;
}


if ($action === 'like_short') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $videoId =$input['videoId'] ?? '';
    $user =$input['user'] ?? '';

  if (empty($videoId) || empty($user)) {
        echo json_encode(["success" => false, "message" => "Invalid parameters"]);
        exit;
    }

    $check =$conn->prepare("SELECT id FROM video_likes WHERE video_id = ? AND username = ?");
    $check->bind_param("ss", $videoId,$user);
    $check->execute();$check->store_result();

    $liked = false;
    if ($check->num_rows > 0) {
        $del =$conn->prepare("DELETE FROM video_likes WHERE video_id = ? AND username = ?");
        $del->bind_param("ss", $videoId, $user);$del->execute();
        $del->close();$liked = false;
    } else {
        $ins =$conn->prepare("INSERT INTO video_likes (video_id, username) VALUES (?, ?)");
        $ins->bind_param("ss", $videoId, $user);$ins->execute();
        $ins->close();$liked = true;
    }
    $check->close();

    $countStmt =$conn->prepare("SELECT COUNT(*) as total FROM video_likes WHERE video_id = ?");
    $countStmt->bind_param("s", $videoId);$countStmt->execute();
    $likesCount =$countStmt->get_result()->fetch_assoc()['total'] ?? 0;
    $countStmt->close();

    echo json_encode(["success" => true, "liked" => $liked, "likesCount" => (int)$likesCount]);$conn->close();
    exit;
}


if ($action === 'add_comment') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $videoId =$input['videoId'] ?? '';
    $commentText = trim($input['comment'] ?? '');
    $username =$input['username'] ?? 'Anonymous';

   if (empty($videoId) || empty($commentText)) {
        echo json_encode(["success" => false, "message" => "Comment cannot be empty"]);
        exit;
    }

    $stmt =$conn->prepare("INSERT INTO video_comments (video_id, username, comment_text) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $videoId, $username,$commentText);
    
    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "comment" => [
                "username" => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
                "text" => htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8')
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to save comment"]);
    }
    $stmt->close();$conn->close();
    exit;
}

if ($page === 'watch') {
    $id =$_GET['id'] ?? 'FmLzfYBHfuY';
    
    $stmt =$conn->prepare("UPDATE videos SET views = views + 1 WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();$stmt->close();

    $stmt_get =$conn->prepare("SELECT * FROM videos WHERE id = ?");
    $stmt_get->bind_param("s", $id);
    $stmt_get->execute();$res = $stmt_get->get_result();$video = $res ? $res->fetch_assoc() : null;
    $stmt_get->close();

    $ytData = getYouTubeDetails($id);

    $title =$ytData['title'] ?? ($video['title'] ?? 'IF ASIAN PARENTS MADE A RAP SONG');$channelName = $ytData['channelTitle'] ?? 'Korean Comic';$uploadDate = $ytData['publishedAt'] ?? 'Dec 18, 2022';$views = $video['views'] ?? ($ytData['views'] ?? 52000000);

    $videoSrc = file_exists("$id.mp4") ? "$id.mp4" : "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?> - VibeStream</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <header class="navbar">
            <div class="nav-left">
                <a href="index.php" style="text-decoration: none;"><h1>VibeStream</h1></a>
            </div>
            <div class="nav-center">
                <input type="text" placeholder="Search">
            </div>
        </header>

        <div class="watch-container">
            <div class="main-player-section">
                <video controls autoplay>
                    <source src="<?php echo htmlspecialchars($videoSrc); ?>" type="video/mp4">
                </video>
                <h2><?php echo htmlspecialchars($title); ?></h2>
                <p class="channel-name" style="font-weight: bold; margin: 4px 0;"><?php echo htmlspecialchars($channelName); ?> ✔</p>
                <p class="video-meta"><?php echo number_format($views); ?> views • Published on <?php echo htmlspecialchars($uploadDate); ?></p>
            </div>

            <div class="recommendations-section">
                <h3>Related Videos</h3>

                <?php
                $recId = 'FmLzfYBHfuY';
                $recDetails = getYouTubeDetails($recId);
                $recTitle =$recDetails['title'] ?? 'RADWIMPS - Suzume (Lyrics) ft. Toaka';
                $recChannel = $recDetails['channelTitle'] ?? 'Authentic Music';$recThumb = $recDetails['thumbnail'] ?? "https://i.ytimg.com/vi/{$recId}/hqdefault.jpg";
                ?>
                <a href="code.php?page=watch&id=<?php echo htmlspecialchars($recId); ?>" class="video-card-link">
                    <div class="video-card compact">
                        <div class="thumbnail">
                            <img src="<?php echo htmlspecialchars($recThumb); ?>" alt="<?php echo htmlspecialchars($recTitle); ?>">
                            <span class="duration-badge">3:56</span>
                        </div>
                        <div class="video-info">
                            <h3 class="video-title"><?php echo htmlspecialchars($recTitle); ?></h3>
                            <p class="channel-name"><?php echo htmlspecialchars($recChannel); ?></p>
                            <p class="video-meta">28M views • 3 yr ago</p>
                        </div>
                    </div>
                </a>

            </div>
        </div>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

if ($page === 'shorts') {
    $id =$_GET['id'] ?? 'short1';
    
    $stmt =$conn->prepare("UPDATE videos SET views = views + 1 WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();$stmt->close();

    $stmt_get =$conn->prepare("SELECT * FROM videos WHERE id = ?");
    $stmt_get->bind_param("s", $id);
    $stmt_get->execute();$res = $stmt_get->get_result();$video = $res ? $res->fetch_assoc() : null;
    $stmt_get->close();

    $likeCountStmt =$conn->prepare("SELECT COUNT(*) as total FROM video_likes WHERE video_id = ?");
    $likeCountStmt->bind_param("s", $id);$likeCountStmt->execute();
    $likesCount =$likeCountStmt->get_result()->fetch_assoc()['total'] ?? 0;
    $likeCountStmt->close();

    $commStmt =$conn->prepare("SELECT username, comment_text, created_at FROM video_comments WHERE video_id = ? ORDER BY id DESC");
    $commStmt->bind_param("s", $id);
    $commStmt->execute();$commentsRes = $commStmt->get_result();$comments = [];
    while($cRow =$commentsRes->fetch_assoc()) {
        $comments[] =$cRow;
    }
    $commStmt->close();

    $ytData = getYouTubeDetails($id);$title = $ytData['title'] ?? ($video['title'] ?? 'Short Title');
    $channelName =$ytData['channelTitle'] ?? 'Creator';
    $views =$video['views'] ?? ($ytData['views'] ?? 0);$videoSrc = file_exists("$id.mp4") ? "$id.mp4" : "https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?> - Shorts</title>
        <link rel="stylesheet" href="style.css">
        <style>
            .shorts-page { display: flex; justify-content: center; align-items: center; height: calc(100vh - var(--nav-height)); background: #000; position: relative; gap: 20px; }
            .short-player-wrapper { position: relative; width: 320px; height: 570px; background: #111; border-radius: 16px; overflow: hidden; display: flex; justify-content: center; align-items: center; }
            .short-player-wrapper video { width: 100%; height: 100%; object-fit: cover; }
            .short-overlay-info { position: absolute; bottom: 16px; left: 16px; right: 70px; color: #fff; text-shadow: 0 1px 4px rgba(0,0,0,0.8); }
            .short-overlay-info h3 { font-size: 14px; margin: 0 0 6px 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .short-actions { display: flex; flex-direction: column; gap: 16px; position: absolute; right: calc(50% - 190px); bottom: 40px; align-items: center; }
            .action-btn { background: rgba(255,255,255,0.15); border: none; color: #fff; width: 48px; height: 48px; border-radius: 50%; display: flex; flex-direction: column; justify-content: center; align-items: center; cursor: pointer; font-size: 16px; transition: background 0.2s; }
            .action-btn:hover { background: rgba(255,255,255,0.3); }
            .action-btn span { font-size: 11px; margin-top: 2px; font-weight: bold; }
            
            #comments-drawer { position: absolute; right: 0; top: 0; width: 350px; height: 100%; background: var(--surface-raised); border-left: 1px solid var(--border); box-shadow: -4px 0 16px rgba(0,0,0,0.5); display: none; flex-direction: column; z-index: 100; padding: 16px; color: var(--text-primary); }
            #comments-drawer.open { display: flex; }
            .drawer-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 10px; font-weight: bold; }
            .comments-list { flex: 1; overflow-y: auto; margin: 12px 0; display: flex; flex-direction: column; gap: 12px; }
            .comment-item { font-size: 13px; background: var(--surface-base); padding: 8px 12px; border-radius: 8px; }
            .comment-user { font-weight: bold; color: #3ea6ff; font-size: 12px; margin-bottom: 2px; }
            .comment-form { display: flex; gap: 8px; }
            .comment-form input { flex: 1; padding: 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface-base); color: #fff; outline: none; }
            .comment-form button { background: #3ea6ff; border: none; padding: 0 12px; border-radius: 6px; font-weight: bold; cursor: pointer; color: #000; }
        </style>
    </head>
    <body>
        <header class="navbar">
            <div class="nav-left">
                <a href="index.php" style="text-decoration: none;"><h1>VibeStream</h1></a>
            </div>
        </header>

        <div class="shorts-page">
            <div class="short-player-wrapper">
                <video controls autoplay loop>
                    <source src="<?php echo htmlspecialchars($videoSrc); ?>" type="video/mp4">
                </video>
                <div class="short-overlay-info">
                    <h3><?php echo htmlspecialchars($title); ?></h3>
                    <p style="font-weight: 500; font-size: 13px; margin: 4px 0;"><?php echo htmlspecialchars($channelName); ?></p>
                    <p><?php echo number_format($views); ?> views</p>
                </div>
            </div>

            <div class="short-actions">
                <button class="action-btn" id="like-btn" onclick="toggleLike('<?php echo $id; ?>')">
                    👍 <span id="like-count"><?php echo $likesCount; ?></span>
                </button>
                <button class="action-btn" onclick="toggleCommentsDrawer()">
                    💬 <span><?php echo count($comments); ?></span>
                </button>
                <button class="action-btn" onclick="navigator.share ? navigator.share({url: window.location.href}) : alert('Link copied!')">
                    ↪
                </button>
            </div>

            <div id="comments-drawer">
                <div class="drawer-header">
                    <span>Comments (<span id="drawer-total"><?php echo count($comments); ?></span>)</span>
                    <button onclick="toggleCommentsDrawer()" style="background:none; border:none; color:#fff; cursor:pointer; font-size:16px;">✕</button>
                </div>
                <div class="comments-list" id="comments-container">
                    <?php if(empty($comments)): ?>
                        <p style="color: var(--text-secondary); text-align: center; margin-top: 20px;" id="no-comments">No comments yet. Be the first!</p>
                    <?php else: ?>
                        <?php foreach($comments as$com): ?>
                            <div class="comment-item">
                                <div class="comment-user"><?php echo htmlspecialchars($com['username']); ?></div>
                                <div><?php echo htmlspecialchars($com['comment_text']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="comment-form">
                    <input type="text" id="comment-input" placeholder="Add a comment...">
                    <button onclick="postComment('<?php echo $id; ?>')">Post</button>
                </div>
            </div>
        </div>

        <script>
            let userId = localStorage.getItem('vibestream_user');
            if(!userId) {
                userId = 'User_' + Math.floor(Math.random() * 90000 + 10000);
                localStorage.setItem('vibestream_user', userId);
            }

            function toggleLike(videoId) {
                fetch('code.php?action=like_short', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ videoId: videoId, user: userId })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('like-count').innerText = data.likesCount;
                        const btn = document.getElementById('like-btn');
                        btn.style.color = data.liked ? '#ff0033' : '#fff';
                    }
                });
            }

            function toggleCommentsDrawer() {
                const drawer = document.getElementById('comments-drawer');
                drawer.classList.toggle('open');
            }

            function postComment(videoId) {
                const input = document.getElementById('comment-input');
                const text = input.value.trim();
                if(!text) return;

                fetch('code.php?action=add_comment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ videoId: videoId, comment: text, username: userId })
                })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        input.value = '';
                        const list = document.getElementById('comments-container');
                        const noCom = document.getElementById('no-comments');
                        if(noCom) noCom.remove();


                        const newDiv = document.createElement('div');
                        newDiv.className = 'comment-item';

                        const userEl = document.createElement('div');
                        userEl.className = 'comment-user';
                        userEl.textContent = data.comment.username;

                        const textEl = document.createElement('div');
                        textEl.textContent = data.comment.text;

                        newDiv.append(userEl, textEl);
                        list.prepend(newDiv);

                        let currentCount = parseInt(document.getElementById('drawer-total').innerText) + 1;
                        document.getElementById('drawer-total').innerText = currentCount;
                    }
                });
            }
        </script>
    </body>
    </html>
    <?php
    $conn->close();
    exit;
}

header('Content-Type: application/json');
echo json_encode(["success" => false, "message" => "Invalid endpoint"]);
$conn->close();
?>