<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Kolkata');

$db = new PDO('sqlite:' . __DIR__ . '/quiz.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON');

$db->exec("CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS quizzes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL,
  category TEXT NOT NULL,
  deadline TEXT,
  created_by INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  quiz_id INTEGER NOT NULL,
  question TEXT NOT NULL,
  options TEXT NOT NULL,
  answer INTEGER NOT NULL,
  FOREIGN KEY(quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  quiz_id INTEGER NOT NULL,
  score INTEGER NOT NULL,
  total INTEGER NOT NULL,
  completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS quiz_assignments (
  quiz_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(quiz_id, user_id),
  FOREIGN KEY(quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);");

$quizColumns = $db->query('PRAGMA table_info(quizzes)')->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('deadline', $quizColumns, true)) $db->exec('ALTER TABLE quizzes ADD COLUMN deadline TEXT');

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function flash(string $type, string $message): void { $_SESSION['flash'] = [$type, $message]; }
function user(): ?array { return $_SESSION['user'] ?? null; }
function requireUser(): void { if (!user()) { flash('error', 'Please log in to continue.'); redirect('?page=login'); } }
function requireAdmin(): void { if (!user() || user()['role'] !== 'admin') { flash('error', 'Admin access is required.'); redirect('?page=admin-login'); } }
function deadlineText(?string $deadline): string { return $deadline ? date('d M Y, h:i A', strtotime($deadline)) : 'No deadline'; }
function quizIsAssigned(PDO $db, int $quizId, int $userId): bool { $stmt=$db->prepare('SELECT 1 FROM quiz_assignments WHERE quiz_id=? AND user_id=?'); $stmt->execute([$quizId,$userId]); return (bool)$stmt->fetchColumn(); }
function quizIsExpired(?string $deadline): bool { return $deadline !== null && $deadline !== '' && strtotime($deadline) < time(); }

function seed(PDO $db): void {
    $stmt = $db->prepare('INSERT OR IGNORE INTO users (name,email,password,role) VALUES (?,?,?,?)');
    $stmt->execute(['Demo Player', 'user@example.com', password_hash('password', PASSWORD_DEFAULT), 'user']);
    $stmt->execute(['Quiz Master', 'admin@example.com', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
    $stmt->execute(['Aarav Mehta', 'aarav@example.com', password_hash('password', PASSWORD_DEFAULT), 'user']);
    $stmt->execute(['Maya Singh', 'maya@example.com', password_hash('password', PASSWORD_DEFAULT), 'user']);
    $stmt->execute(['Noah Williams', 'noah@example.com', password_hash('password', PASSWORD_DEFAULT), 'user']);
    if ((int)$db->query('SELECT COUNT(*) FROM quizzes')->fetchColumn() > 0) {
        seedAssignments($db);
        seedAttempts($db);
        return;
    }
    $adminId = (int)$db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
    $quizzes = [
        ['World Geography', 'Test your knowledge of landmarks, capitals, and our amazing planet.', 'Geography', [
            ['What is the capital of Canada?', ['Toronto','Ottawa','Vancouver','Montreal'], 1],
            ['Which is the longest river in South America?', ['Amazon','Nile','Yangtze','Mississippi'], 0],
            ['Mount Kilimanjaro is in which country?', ['Kenya','Tanzania','Ethiopia','Uganda'], 1],
            ['Which ocean is the largest?', ['Atlantic','Indian','Pacific','Arctic'], 2],
        ]],
        ['Science Essentials', 'A quick mix of biology, physics, chemistry, and space.', 'Science', [
            ['What gas do plants absorb from the atmosphere?', ['Oxygen','Nitrogen','Carbon dioxide','Hydrogen'], 2],
            ['What is the chemical symbol for gold?', ['Ag','Au','Gd','Go'], 1],
            ['How many bones are in the adult human body?', ['106','206','306','406'], 1],
            ['What force keeps planets in orbit?', ['Magnetism','Friction','Gravity','Pressure'], 2],
        ]],
        ['Tech & Digital Life', 'Explore the basics of the technology that powers the modern world.', 'Technology', [
            ['What does CPU stand for?', ['Central Processing Unit','Computer Personal Utility','Core Program Upload','Central Power User'], 0],
            ['Which language is used to style web pages?', ['HTML','CSS','SQL','Python'], 1],
            ['What does URL stand for?', ['Universal Reading Link','Uniform Resource Locator','User Route List','Unified Response Language'], 1],
            ['Which one is a version control system?', ['Git','Figma','Chrome','MySQL'], 0],
        ]],
    ];
    $quizStmt = $db->prepare('INSERT INTO quizzes (title,description,category,created_by) VALUES (?,?,?,?)');
    $qStmt = $db->prepare('INSERT INTO questions (quiz_id,question,options,answer) VALUES (?,?,?,?)');
    foreach ($quizzes as $quiz) {
        $quizStmt->execute([$quiz[0], $quiz[1], $quiz[2], $adminId]);
        $quizId = (int)$db->lastInsertId();
        foreach ($quiz[3] as $question) $qStmt->execute([$quizId, $question[0], json_encode($question[1]), $question[2]]);
    }
    seedAssignments($db);
    seedAttempts($db);
}
function seedAssignments(PDO $db): void {
    if ((int)$db->query('SELECT COUNT(*) FROM quiz_assignments')->fetchColumn() > 0) return;
    $deadlineStmt = $db->prepare("UPDATE quizzes SET deadline=? WHERE deadline IS NULL");
    $deadlineStmt->execute([date('Y-m-d H:i:s', time() + 14 * 86400)]);
    $users = $db->query("SELECT id FROM users WHERE role='user'")->fetchAll(PDO::FETCH_COLUMN);
    $quizzes = $db->query('SELECT id FROM quizzes')->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $db->prepare('INSERT OR IGNORE INTO quiz_assignments (quiz_id,user_id) VALUES (?,?)');
    foreach ($quizzes as $quizId) foreach ($users as $userId) $stmt->execute([(int)$quizId,(int)$userId]);
}
function seedAttempts(PDO $db): void {
    if ((int)$db->query('SELECT COUNT(*) FROM attempts')->fetchColumn() > 0) return;
    $users = $db->query("SELECT email,id FROM users WHERE email IN ('aarav@example.com','maya@example.com','noah@example.com')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $quizzes = $db->query('SELECT id,title FROM quizzes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($users) < 3 || count($quizzes) < 3) return;
    $stmt = $db->prepare('INSERT INTO attempts (user_id,quiz_id,score,total) VALUES (?,?,?,?)');
    $firstQuizId = (int)array_key_first($quizzes);
    $lastQuizId = (int)array_key_last($quizzes);
    $scores = [
        [$users['aarav@example.com'], $firstQuizId, 4, 4],
        [$users['aarav@example.com'], $lastQuizId, 3, 4],
        [$users['maya@example.com'], $firstQuizId, 3, 4],
        [$users['maya@example.com'], $lastQuizId, 4, 4],
        [$users['noah@example.com'], $firstQuizId, 2, 4],
        [$users['noah@example.com'], $lastQuizId, 3, 4],
    ];
    foreach ($scores as $score) $stmt->execute($score);
}
seed($db);

$page = $_GET['page'] ?? 'home';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $password = $_POST['password'] ?? '';
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) { flash('error', 'Enter a name, valid email, and password of at least 6 characters.'); redirect('?page=register'); }
        try { $stmt = $db->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)'); $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]); flash('success','Account created. Welcome to Quizly!'); redirect('?page=login'); }
        catch (PDOException $e) { flash('error','That email is already registered.'); redirect('?page=register'); }
    }
    if ($action === 'login' || $action === 'admin_login') {
        $email = strtolower(trim($_POST['email'] ?? '')); $password = $_POST['password'] ?? '';
        $stmt = $db->prepare('SELECT * FROM users WHERE email=?'); $stmt->execute([$email]); $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found && password_verify($password, $found['password']) && ($action === 'login' || $found['role'] === 'admin')) { $_SESSION['user'] = ['id'=>(int)$found['id'],'name'=>$found['name'],'email'=>$found['email'],'role'=>$found['role']]; redirect($found['role'] === 'admin' ? '?page=admin' : '?page=home'); }
        flash('error', $action === 'admin_login' ? 'Invalid admin credentials.' : 'Invalid email or password.'); redirect('?page=' . ($action === 'admin_login' ? 'admin-login' : 'login'));
    }
    if ($action === 'logout') { session_destroy(); redirect('?page=home'); }
    if ($action === 'submit_quiz') {
        requireUser(); $quizId = (int)$_POST['quiz_id']; $quizCheck=$db->prepare('SELECT * FROM quizzes WHERE id=?'); $quizCheck->execute([$quizId]); $quizToSubmit=$quizCheck->fetch(PDO::FETCH_ASSOC);
        if (!$quizToSubmit || (user()['role'] !== 'admin' && !quizIsAssigned($db,$quizId,(int)user()['id']))) { flash('error','This quiz is not assigned to your account.'); redirect('?page=home'); }
        if (quizIsExpired($quizToSubmit['deadline'])) { flash('error','The deadline for this quiz has passed.'); redirect('?page=home'); }
        $stmt = $db->prepare('SELECT * FROM questions WHERE quiz_id=? ORDER BY id'); $stmt->execute([$quizId]); $questions = $stmt->fetchAll(PDO::FETCH_ASSOC); $score = 0;
        foreach ($questions as $q) if ((int)($_POST['answer'][$q['id']] ?? -1) === (int)$q['answer']) $score++;
        $stmt = $db->prepare('INSERT INTO attempts (user_id,quiz_id,score,total) VALUES (?,?,?,?)'); $stmt->execute([user()['id'],$quizId,$score,count($questions)]); $_SESSION['result'] = [$score,count($questions)]; redirect('?page=result&id=' . $quizId);
    }
    if ($action === 'create_quiz') {
        requireAdmin(); $title=trim($_POST['title']??''); $description=trim($_POST['description']??''); $category=trim($_POST['category']??''); $deadline=trim($_POST['deadline']??''); $assignedUsers=array_map('intval',$_POST['assigned_users']??[]);
        $questions = json_decode($_POST['questions_json'] ?? '[]', true);
        if (!$title || !$description || !$category || !$deadline || !$assignedUsers || !is_array($questions) || count($questions) < 1) { flash('error','Complete quiz details, deadline, at least one user, and one question.'); redirect('?page=admin'); }
        if (strtotime($deadline) <= time()) { flash('error','Deadline must be in the future.'); redirect('?page=admin'); }
        $stmt=$db->prepare('INSERT INTO quizzes (title,description,category,deadline,created_by) VALUES (?,?,?,?,?)'); $stmt->execute([$title,$description,$category,$deadline,user()['id']]); $quizId=(int)$db->lastInsertId(); $qStmt=$db->prepare('INSERT INTO questions (quiz_id,question,options,answer) VALUES (?,?,?,?)');
        foreach ($questions as $q) $qStmt->execute([ $quizId, trim($q['question']), json_encode(array_values($q['options'])), (int)$q['answer'] ]);
        $assign=$db->prepare('INSERT OR IGNORE INTO quiz_assignments (quiz_id,user_id) VALUES (?,?)'); foreach ($assignedUsers as $assignedUser) $assign->execute([$quizId,$assignedUser]);
        flash('success','Quiz published successfully.'); redirect('?page=admin');
    }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$quizListSql = user() && user()['role'] === 'user' ? 'SELECT q.*, COUNT(questions.id) question_count FROM quizzes q JOIN quiz_assignments qa ON qa.quiz_id=q.id LEFT JOIN questions ON questions.quiz_id=q.id WHERE qa.user_id='.(int)user()['id'].' GROUP BY q.id ORDER BY q.id DESC' : 'SELECT q.*, COUNT(questions.id) question_count FROM quizzes q LEFT JOIN questions ON questions.quiz_id=q.id GROUP BY q.id ORDER BY q.id DESC';
$quizzes = $db->query($quizListSql)->fetchAll(PDO::FETCH_ASSOC);
$pending = [];
if (user() && user()['role'] === 'user') { $stmt=$db->prepare("SELECT q.*, COUNT(questions.id) question_count FROM quizzes q JOIN quiz_assignments qa ON qa.quiz_id=q.id LEFT JOIN questions ON questions.quiz_id=q.id LEFT JOIN attempts a ON a.quiz_id=q.id AND a.user_id=? WHERE qa.user_id=? AND a.id IS NULL GROUP BY q.id ORDER BY CASE WHEN q.deadline IS NULL THEN 1 ELSE 0 END, q.deadline ASC"); $stmt->execute([user()['id'],user()['id']]); $pending=$stmt->fetchAll(PDO::FETCH_ASSOC); }
$allUsers = $db->query("SELECT id,name,email FROM users WHERE role='user' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$currentQuiz = null; $questions = [];
if ($id) { $stmt=$db->prepare('SELECT * FROM quizzes WHERE id=?'); $stmt->execute([$id]); $currentQuiz=$stmt->fetch(PDO::FETCH_ASSOC); if ($currentQuiz) { $stmt=$db->prepare('SELECT * FROM questions WHERE quiz_id=? ORDER BY id'); $stmt->execute([$id]); $questions=$stmt->fetchAll(PDO::FETCH_ASSOC); } }
$leaders = $db->query("SELECT u.name, SUM(a.score) points, COUNT(a.id) attempts FROM attempts a JOIN users u ON u.id=a.user_id GROUP BY a.user_id ORDER BY points DESC, attempts ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
function renderHeader(string $title): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.e($title).' · Quizly</title><link rel="stylesheet" href="style.css"></head><body><nav><a class="brand" href="?page=home"><span>✦</span> Quizly</a><div class="navlinks"><a href="?page=home">Quizzes</a><a href="?page=leaderboard">Leaderboard</a>';
    if (user() && user()['role'] === 'admin') echo '<a href="?page=admin">Admin</a>';
    if (!user()) echo '<a href="?page=admin-login">Admin login</a>';
    if (user()) echo '<span class="hello">Hi, '.e(user()['name']).'</span><form method="post"><input type="hidden" name="action" value="logout"><button class="link-button">Log out</button></form>';
    else echo '<a href="?page=login">Log in</a><a class="nav-cta" href="?page=register">Get started</a>';
    echo '</div></nav><main>';
    if (!empty($GLOBALS['flash'])) echo '<div class="alert '.e($GLOBALS['flash'][0]).'">'.e($GLOBALS['flash'][1]).'</div>';
}
function renderFooter(): void { ?></main><footer>Quizly · Learn something new every day</footer></body></html><?php }

if ($page === 'home') { renderHeader('Play & learn'); ?><section class="hero"><div><p class="eyebrow">A little curiosity goes a long way</p><h1>Make your next<br><em>five minutes</em> count.</h1><p class="lead">Pick a topic, challenge yourself, and see how you stack up against the community.</p><a class="button" href="#quizzes">Explore quizzes <span>→</span></a></div><div class="hero-art"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="floating-card card-one">✓ <b>10/10</b><small>Perfect score</small></div><div class="floating-card card-two">♛ <b>Top 10%</b><small>This week</small></div><div class="planet">✦</div></div></section><?php if (user() && user()['role'] === 'user'): ?><section class="section pending-section"><div class="section-heading"><div><p class="eyebrow">Your assignments</p><h2>Pending quizzes</h2></div><span class="pending-count"><?=count($pending)?> pending</span></div><?php if ($pending): ?><div class="pending-list"><?php foreach($pending as $quiz): ?><a class="pending-row" href="?page=quiz&id=<?=$quiz['id']?>"><div><b><?=e($quiz['title'])?></b><small><?=e($quiz['category'])?> · <?=e((string)$quiz['question_count'])?> questions</small></div><span class="deadline">Due <?=e(deadlineText($quiz['deadline']))?> <b>↗</b></span></a><?php endforeach; ?></div><?php else: ?><p class="empty">You are all caught up. New assigned quizzes will appear here.</p><?php endif; ?></section><?php endif; ?><section id="quizzes" class="section"><div class="section-heading"><div><p class="eyebrow"><?=user() && user()['role'] === 'user' ? 'Assigned to you' : 'Choose your challenge'?></p><h2><?=user() && user()['role'] === 'user' ? 'Your quizzes' : 'Popular quizzes'?></h2></div><a href="?page=leaderboard" class="text-link">View leaderboard →</a></div><div class="quiz-grid"><?php foreach($quizzes as $quiz): ?><article class="quiz-card"><div class="quiz-icon <?=strtolower(e($quiz['category']))?>">✦</div><span class="tag"><?=e($quiz['category'])?></span><h3><?=e($quiz['title'])?></h3><p><?=e($quiz['description'])?></p><div class="card-meta"><span>◷ <?=e((string)$quiz['question_count'])?> questions<?php if ($quiz['deadline']): ?> · Due <?=e(date('d M',strtotime($quiz['deadline'])))?><?php endif; ?></span><a href="?page=quiz&id=<?=$quiz['id']?>">Play now <b>↗</b></a></div></article><?php endforeach; ?></div></section><?php renderFooter(); exit; }
if ($page === 'leaderboard') { renderHeader('Leaderboard'); ?><section class="page-intro"><p class="eyebrow">The community scoreboard</p><h1>Leaderboard</h1><p>Earn points by playing quizzes and climb the ranks.</p></section><section class="leaderboard"><div class="podium"><?php foreach(array_slice($leaders,0,3) as $i=>$leader): ?><div class="podium-item rank-<?=$i+1?>"><div class="avatar"><?=e(strtoupper(substr($leader['name'],0,1)))?></div><strong><?=$i+1?></strong><h3><?=e($leader['name'])?></h3><span><?=e((string)$leader['points'])?> pts</span></div><?php endforeach; if (!$leaders): ?><p class="empty">Be the first to play a quiz!</p><?php endif; ?></div><div class="table-wrap"><table><thead><tr><th>Rank</th><th>Player</th><th>Quizzes played</th><th>Points</th></tr></thead><tbody><?php foreach($leaders as $i=>$leader): ?><tr><td>#<?=$i+1?></td><td><span class="mini-avatar"><?=e(strtoupper(substr($leader['name'],0,1)))?></span><?=e($leader['name'])?></td><td><?=e((string)$leader['attempts'])?></td><td><b><?=e((string)$leader['points'])?></b></td></tr><?php endforeach; ?></tbody></table></div></section><?php renderFooter(); exit; }
if ($page === 'quiz' && $currentQuiz) { requireUser(); if (user()['role'] !== 'admin' && !quizIsAssigned($db,$id,(int)user()['id'])) { flash('error','This quiz is not assigned to your account.'); redirect('?page=home'); } if (quizIsExpired($currentQuiz['deadline'])) { flash('error','The deadline for this quiz has passed.'); redirect('?page=home'); } renderHeader($currentQuiz['title']); ?><section class="quiz-head"><span class="tag"><?=e($currentQuiz['category'])?></span><h1><?=e($currentQuiz['title'])?></h1><p><?=e($currentQuiz['description'])?></p><?php if ($currentQuiz['deadline']): ?><p class="deadline">Submit by <?=e(deadlineText($currentQuiz['deadline']))?></p><?php endif; ?></section><form class="quiz-form" method="post"><input type="hidden" name="action" value="submit_quiz"><input type="hidden" name="quiz_id" value="<?=$id?>"><?php foreach($questions as $i=>$q): ?><fieldset><legend><span><?=str_pad((string)($i+1),2,'0',STR_PAD_LEFT)?></span><?=e($q['question'])?></legend><div class="options"><?php foreach(json_decode($q['options'],true) as $j=>$option): ?><label><input type="radio" name="answer[<?=$q['id']?>]" value="<?=$j?>" required><span><?=e($option)?></span></label><?php endforeach; ?></div></fieldset><?php endforeach; ?><button class="button" type="submit">Submit answers <span>→</span></button></form><?php renderFooter(); exit; }
if ($page === 'result') { requireUser(); $result=$_SESSION['result']??[0,0]; unset($_SESSION['result']); renderHeader('Your result'); ?><section class="result-card"><div class="result-badge">✦</div><p class="eyebrow">Quiz complete</p><h1>You scored <em><?=e((string)$result[0])?>/<?=e((string)$result[1])?></em></h1><p><?= $result[1] && $result[0]===$result[1] ? 'Perfect score. You really know your stuff!' : 'Nice work! Keep playing to improve your score.' ?></p><div><a class="button" href="?page=home">Play another quiz <span>→</span></a><a class="button secondary" href="?page=leaderboard">See leaderboard</a></div></section><?php renderFooter(); exit; }
if ($page === 'admin-login') { renderHeader('Admin login'); ?><section class="auth-card"><p class="eyebrow">Quizly staff</p><h1>Admin login</h1><p>Manage the quiz library and publish new challenges.</p><form method="post"><input type="hidden" name="action" value="admin_login"><label>Email<input type="email" name="email" required placeholder="admin@example.com"></label><label>Password<input type="password" name="password" required placeholder="••••••••"></label><button class="button" type="submit">Sign in <span>→</span></button></form><p class="hint">Demo: admin@example.com / admin123</p></section><?php renderFooter(); exit; }
if ($page === 'login' || $page === 'register') { $isLogin=$page==='login'; renderHeader($isLogin?'Log in':'Create account'); ?><section class="auth-card"><p class="eyebrow">Welcome to Quizly</p><h1><?=$isLogin?'Welcome back.':'Create your account.'?></h1><p><?=$isLogin?'Ready for another round?':'Track your scores and join the leaderboard.'?></p><form method="post"><input type="hidden" name="action" value="<?=$isLogin?'login':'register'?>"><?php if(!$isLogin): ?><label>Name<input name="name" required placeholder="Alex Johnson"></label><?php endif; ?><label>Email<input type="email" name="email" required placeholder="you@example.com"></label><label>Password<input type="password" name="password" required placeholder="At least 6 characters"></label><button class="button" type="submit"><?=$isLogin?'Log in':'Create account'?> <span>→</span></button></form><p class="switch"><?=$isLogin?'New here?':'Already have an account?'?> <a href="?page=<?=$isLogin?'register':'login'?>"><?=$isLogin?'Create an account':'Log in'?></a></p><?php if($isLogin): ?><p class="hint">Demo: user@example.com / password</p><?php endif; ?></section><?php renderFooter(); exit; }
if ($page === 'admin') { requireAdmin(); renderHeader('Admin dashboard'); ?><section class="admin-head"><div><p class="eyebrow">Content studio</p><h1>Admin dashboard</h1><p>Create a new challenge, choose who sees it, and set a submission deadline.</p></div><span class="admin-pill">● Admin mode</span></section><section class="admin-layout"><div class="panel"><h2>New quiz</h2><form method="post" id="create-form"><input type="hidden" name="action" value="create_quiz"><input type="hidden" name="questions_json" id="questions_json"><label>Title<input name="title" required placeholder="e.g. Film & Cinema"></label><label>Category<input name="category" required placeholder="e.g. Culture"></label><label>Description<textarea name="description" required placeholder="What will players learn?"></textarea></label><label>Submission deadline<input type="datetime-local" name="deadline" required></label><div class="assignment-box"><b>Show this quiz to</b><small>Select one or more user accounts.</small><?php foreach($allUsers as $account): ?><label class="user-check"><input type="checkbox" name="assigned_users[]" value="<?=$account['id']?>"><span><?=e($account['name'])?> <small><?=e($account['email'])?></small></span></label><?php endforeach; ?></div><div id="question-builder"></div><button type="button" class="button secondary" id="add-question">+ Add question</button><button class="button" type="submit">Publish quiz <span>→</span></button></form></div><div class="panel library"><h2>Quiz library <span><?=count($quizzes)?></span></h2><?php foreach($quizzes as $quiz): ?><div class="library-row"><div class="quiz-icon small">✦</div><div><b><?=e($quiz['title'])?></b><small><?=e($quiz['category'])?> · <?=e((string)$quiz['question_count'])?> questions</small><?php if($quiz['deadline']): ?><small>Due <?=e(deadlineText($quiz['deadline']))?></small><?php endif; ?></div></div><?php endforeach; ?></div></section><script>const builder=document.getElementById('question-builder'); let count=0; function addQuestion(){count++;const el=document.createElement('div');el.className='builder-question';el.innerHTML='<div class="builder-title">Question '+count+'</div><input class="q-text" required placeholder="Ask something interesting..."><div class="answer-grid">'+[0,1,2,3].map((i)=>'<input class="q-option" required placeholder="Option '+(i+1)+'">').join('')+'</div><select class="q-answer"><option value="0">Correct answer: Option 1</option><option value="1">Correct answer: Option 2</option><option value="2">Correct answer: Option 3</option><option value="3">Correct answer: Option 4</option></select>';builder.appendChild(el);} document.getElementById('add-question').onclick=addQuestion; addQuestion(); document.getElementById('create-form').onsubmit=()=>{const data=[...document.querySelectorAll('.builder-question')].map(x=>({question:x.querySelector('.q-text').value,options:[...x.querySelectorAll('.q-option')].map(i=>i.value),answer:Number(x.querySelector('.q-answer').value)}));document.getElementById('questions_json').value=JSON.stringify(data);};</script><?php renderFooter(); exit; }
renderHeader('Not found'); ?><section class="page-intro"><h1>Page not found</h1><a class="button" href="?page=home">Back home</a></section><?php renderFooter();
