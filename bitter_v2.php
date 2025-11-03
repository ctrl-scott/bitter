<?php
declare(strict_types=1);

/*

This version focuses on PHP and HTML/CSS/JS and is not going in the direction of being Nodey

  bitter_v2.php
  Web-first educational social application "Bitter"

  This version replaces the static in-memory feed with a very small PHP + PDO
  layer that can talk to either SQLite or MariaDB. The front end still uses
  HTML, CSS, and JavaScript and communicates with this same file through
  simple query string based endpoints:

    ?api=posts-list   -> JSON list of posts
    ?api=posts-create -> JSON creation of a post (POST body)

  Non-commercial educational use only.
*/

/* -------------------------------------------------------------------------
   1. CONFIGURATION
   ------------------------------------------------------------------------- */

// Choose "sqlite" or "mariadb"
const BITTER_DB_DRIVER = 'sqlite'; // change to 'mariadb' if desired

// SQLite configuration
const BITTER_SQLITE_PATH = __DIR__ . '/bitter.db';

// MariaDB configuration
const BITTER_MARIADB_DSN  = 'mysql:host=localhost;dbname=bitter;charset=utf8mb4';
const BITTER_MARIADB_USER = 'bitter_user';
const BITTER_MARIADB_PASS = 'change_me';

/* -------------------------------------------------------------------------
   2. DATABASE CONNECTION AND BOOTSTRAP
   ------------------------------------------------------------------------- */

function bitter_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (BITTER_DB_DRIVER === 'sqlite') {
        $dsn = 'sqlite:' . BITTER_SQLITE_PATH;
        $pdo = new PDO($dsn);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } elseif (BITTER_DB_DRIVER === 'mariadb') {
        $pdo = new PDO(
            BITTER_MARIADB_DSN,
            BITTER_MARIADB_USER,
            BITTER_MARIADB_PASS
        );
    } else {
        throw new RuntimeException('Unsupported DB driver: ' . BITTER_DB_DRIVER);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    bitter_bootstrap_schema($pdo);

    return $pdo;
}

/**
 * Create tables if they do not exist and seed a default user.
 */
function bitter_bootstrap_schema(PDO $pdo): void
{
    if (BITTER_DB_DRIVER === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                handle       TEXT    NOT NULL UNIQUE,
                display_name TEXT    NOT NULL,
                bio          TEXT,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL,
                body         TEXT    NOT NULL,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_highlight INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id)
             )'
        );
    } else {
        // MariaDB or MySQL compatible
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handle       VARCHAR(32)  NOT NULL UNIQUE,
                display_name VARCHAR(80)  NOT NULL,
                bio          TEXT,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS posts (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id      INT UNSIGNED NOT NULL,
                body         VARCHAR(280) NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_highlight TINYINT(1)   NOT NULL DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id)
             ) ENGINE=InnoDB'
        );
    }

    // Seed default user if table is empty
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (handle, display_name, bio)
             VALUES (:handle, :display_name, :bio)'
        );
        $stmt->execute([
            ':handle'       => '@demo_learner',
            ':display_name' => 'Demo Learner',
            ':bio'          => 'Practicing front end and database concepts with Bitter.'
        ]);

        // Also seed a few posts
        $userId = (int)$pdo->lastInsertId();
        $seedPosts = [
            'Built this Bitter layout using HTML5 semantics and CSS Flexbox. It is surprisingly readable.',
            'Flexbox makes responsive columns much easier than older float based layouts.',
            'Practicing event handling by wiring the Post button to a PDO backed feed.'
        ];
        $stmtPost = $pdo->prepare(
            'INSERT INTO posts (user_id, body, is_highlight) VALUES (:uid, :body, :hi)'
        );
        foreach ($seedPosts as $index => $text) {
            $stmtPost->execute([
                ':uid' => $userId,
                ':body' => $text,
                ':hi'   => $index === 0 ? 1 : 0
            ]);
        }
    }
}

/* -------------------------------------------------------------------------
   3. SIMPLE JSON API
   ------------------------------------------------------------------------- */

function bitter_api_list_posts(): void
{
    $pdo = bitter_get_pdo();

    $stmt = $pdo->query(
        'SELECT p.id,
                p.body,
                p.created_at,
                p.is_highlight,
                u.handle,
                u.display_name
         FROM posts p
         JOIN users u ON p.user_id = u.id
         ORDER BY p.created_at ASC
         LIMIT 100'
    );

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($posts);
    exit;
}

function bitter_api_create_post(): void
{
    $pdo = bitter_get_pdo();

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $body = trim($data['body'] ?? '');
    if ($body === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Body is required']);
        exit;
    }

    // In this educational example all posts belong to the seeded demo user
    $stmtUser = $pdo->query(
        'SELECT id, handle, display_name FROM users ORDER BY id ASC LIMIT 1'
    );
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No user found']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO posts (user_id, body, is_highlight)
         VALUES (:uid, :body, 0)'
    );
    $stmt->execute([
        ':uid'  => $user['id'],
        ':body' => $body
    ]);

    $id = $pdo->lastInsertId();
    $createdAt = date('Y-m-d H:i:s');

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'id'           => (int)$id,
        'body'         => $body,
        'created_at'   => $createdAt,
        'is_highlight' => 0,
        'handle'       => $user['handle'],
        'display_name' => $user['display_name']
    ]);
    exit;
}

/* -------------------------------------------------------------------------
   4. API ROUTING
   ------------------------------------------------------------------------- */

if (isset($_GET['api'])) {
    $api = $_GET['api'];
    if ($api === 'posts-list') {
        bitter_api_list_posts();
    } elseif ($api === 'posts-create') {
        bitter_api_create_post();
    } else {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unknown API endpoint']);
        exit;
    }
}

/* -------------------------------------------------------------------------
   5. HTML OUTPUT
   ------------------------------------------------------------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bitter v2 – PHP + SQLite / MariaDB Educational Demo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root {
      --sky: #66b9ff;
      --sky-light: #e8f4ff;
      --sky-dark: #2b7cbc;
      --bg: #f5f7fb;
      --text-main: #133143;
      --text-muted: #4f6b7f;
      --radius-xl: 26px;
      --radius-lg: 18px;
      --radius-md: 12px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: radial-gradient(circle at top left, #ffffff 0, #eef2f7 40%, #dee6f2 100%);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: stretch;
    }

    header.app-header {
      background: var(--sky);
      color: #ffffff;
      padding: 14px 24px;
      border-radius: 0 0 var(--radius-xl) var(--radius-xl);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
      text-align: center;
      font-size: 1.4rem;
      font-weight: 600;
      letter-spacing: 0.03em;
    }

    header.app-header small {
      display: block;
      font-size: 0.75rem;
      font-weight: 400;
      margin-top: 4px;
      opacity: 0.9;
    }

    .app-shell {
      flex: 1;
      display: flex;
      gap: 16px;
      padding: 16px;
      max-width: 1200px;
      width: 100%;
      margin: 0 auto 16px auto;
    }

    aside.sidebar {
      width: 230px;
      min-width: 210px;
      max-width: 260px;
      background: var(--sky);
      border-radius: var(--radius-xl);
      padding: 16px 10px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .sidebar-panel {
      background: #ffffff;
      color: var(--text-main);
      border-radius: var(--radius-xl);
      padding: 14px 12px;
      flex: 0 0 auto;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .sidebar-panel h2 {
      font-size: 0.95rem;
      margin-bottom: 6px;
      text-align: center;
    }

    .user-summary {
      font-size: 0.85rem;
      line-height: 1.4;
    }

    .user-summary .name {
      font-weight: 600;
      font-size: 1rem;
    }

    .user-summary .handle {
      color: var(--text-muted);
      font-size: 0.85rem;
    }

    .user-stats {
      display: flex;
      justify-content: space-between;
      font-size: 0.76rem;
      margin-top: 4px;
    }

    .other-users-list {
      list-style: none;
      font-size: 0.85rem;
      max-height: 220px;
      overflow-y: auto;
      padding-right: 4px;
    }

    .other-users-list li {
      padding: 6px 6px;
      border-radius: var(--radius-md);
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: background 0.15s ease, transform 0.05s ease;
    }

    .other-users-list li span.handle {
      color: var(--text-muted);
      font-size: 0.8rem;
    }

    .other-users-list li:hover {
      background: var(--sky-light);
      transform: translateY(-1px);
    }

    .menu-buttons {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 4px;
    }

    .menu-buttons button {
      padding: 6px 10px;
      border-radius: var(--radius-md);
      border: none;
      font-size: 0.85rem;
      cursor: pointer;
      background: var(--sky);
      color: #ffffff;
      transition: background 0.15s ease, transform 0.05s ease;
    }

    .menu-buttons button:hover {
      background: var(--sky-dark);
      transform: translateY(-1px);
    }

    main.main-feed {
      flex: 1;
      background: #ffffff;
      border-radius: var(--radius-xl);
      padding: 16px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
      display: flex;
      flex-direction: column;
      gap: 14px;
      position: relative;
    }

    .feed-inner-frame {
      border-radius: var(--radius-xl);
      padding: 16px;
      background: var(--bg);
      height: 100%;
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .highlighted {
      background: var(--sky);
      color: #ffffff;
      border-radius: var(--radius-xl);
      padding: 14px 18px;
      font-weight: 500;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.45);
    }

    .highlighted-title {
      font-size: 0.9rem;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      margin-bottom: 6px;
      opacity: 0.92;
    }

    .highlighted-body {
      font-size: 0.95rem;
    }

    .composer {
      background: #ffffff;
      border-radius: var(--radius-lg);
      padding: 10px 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }

    .composer label {
      font-size: 0.82rem;
      color: var(--text-muted);
    }

    .composer textarea {
      width: 100%;
      min-height: 60px;
      resize: vertical;
      border-radius: var(--radius-md);
      border: 1px solid #c3d3e6;
      padding: 6px 8px;
      font-family: inherit;
      font-size: 0.9rem;
    }

    .composer-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .composer-row small {
      font-size: 0.78rem;
      color: var(--text-muted);
    }

    .composer button {
      padding: 6px 14px;
      border-radius: 999px;
      border: none;
      background: var(--sky);
      color: #ffffff;
      font-size: 0.88rem;
      cursor: pointer;
      font-weight: 500;
      transition: background 0.15s ease, transform 0.05s ease;
    }

    .composer button:hover {
      background: var(--sky-dark);
      transform: translateY(-1px);
    }

    .feed-heading {
      text-align: center;
      font-size: 0.92rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-muted);
      margin-top: 6px;
    }

    .feed-scroll {
      margin-top: 4px;
      flex: 1;
      overflow-y: auto;
      padding-right: 6px;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .post {
      position: relative;
      background: var(--sky);
      border-radius: var(--radius-lg);
      padding: 10px 12px 10px 14px;
      max-width: 88%;
      color: #ffffff;
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.16);
      font-size: 0.9rem;
    }

    .post::after {
      content: "";
      position: absolute;
      right: -16px;
      top: 18px;
      border-width: 8px 0 8px 16px;
      border-style: solid;
      border-color: transparent transparent transparent var(--sky);
    }

    .post-meta {
      font-size: 0.75rem;
      opacity: 0.92;
      margin-bottom: 4px;
      display: flex;
      justify-content: space-between;
      gap: 8px;
      flex-wrap: wrap;
    }

    .post .handle {
      font-weight: 600;
    }

    .post-body {
      line-height: 1.35;
    }

    .post.alt {
      margin-left: auto;
      background: #ffffff;
      color: var(--text-main);
      border: 1px solid var(--sky);
    }

    .post.alt::after {
      left: -16px;
      right: auto;
      border-width: 8px 16px 8px 0;
      border-color: transparent var(--sky) transparent transparent;
    }

    .scroll-bar-hint {
      width: 18px;
      border-radius: var(--radius-xl);
      background: var(--sky);
      margin-left: 4px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 12px 0;
      color: #ffffff;
      font-size: 0.7rem;
      gap: 6px;
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.18);
    }

    .scroll-arrow {
      width: 0;
      height: 0;
      border-left: 5px solid transparent;
      border-right: 5px solid transparent;
    }

    .scroll-arrow.up {
      border-bottom: 8px solid #ffffff;
    }

    .scroll-arrow.down {
      border-top: 8px solid #ffffff;
    }

    footer {
      max-width: 1200px;
      margin: 0 auto 18px auto;
      padding: 0 16px;
      font-size: 0.8rem;
      color: var(--text-muted);
    }

    footer h2 {
      font-size: 0.9rem;
      margin-bottom: 4px;
    }

    footer ol {
      padding-left: 18px;
      line-height: 1.4;
    }

    footer li {
      margin-bottom: 2px;
    }

    @media (max-width: 900px) {
      .app-shell {
        flex-direction: column;
      }
      aside.sidebar {
        width: 100%;
        max-width: none;
        flex-direction: row;
        align-items: stretch;
        justify-content: space-between;
      }
      .sidebar-panel {
        flex: 1;
      }
    }

    @media (max-width: 640px) {
      header.app-header {
        border-radius: 0;
      }
      .app-shell {
        padding: 10px;
      }
      aside.sidebar {
        flex-direction: column;
      }
    }
  </style>
</head>

<body>
  <header class="app-header">
    Application Name: Bitter v2
    <small>PHP + SQLite or MariaDB backed social interface for non commercial educational use</small>
  </header>

  <div class="app-shell">
    <aside class="sidebar" aria-label="Sidebar">
      <section class="sidebar-panel" aria-label="User information">
        <h2>User Info</h2>
        <div class="user-summary">
          <div class="name" id="currentUserName">Demo Learner</div>
          <div class="handle" id="currentUserHandle">@demo_learner</div>
          <p id="currentUserBio">
            This user profile is stored in the database and loaded by PHP during bootstrap.
          </p>
          <div class="user-stats">
            <span><strong id="statPosts">0</strong> posts</span>
            <span><strong>12</strong> following</span>
            <span><strong>7</strong> followers</span>
          </div>
        </div>
      </section>

      <section class="sidebar-panel" aria-label="Other users">
        <h2>Other Users</h2>
        <ul class="other-users-list" id="otherUsersList">
          <li><span>Ada</span><span class="handle">@ada_codes</span></li>
          <li><span>Sam</span><span class="handle">@sam_student</span></li>
          <li><span>Lee</span><span class="handle">@lee_designs</span></li>
          <li><span>Ravi</span><span class="handle">@ravi_research</span></li>
          <li><span>Amira</span><span class="handle">@amira_history</span></li>
          <li><span>Jen</span><span class="handle">@jen_labs</span></li>
        </ul>
      </section>

      <section class="sidebar-panel" aria-label="Menu">
        <h2>Menu</h2>
        <div class="menu-buttons">
          <button type="button" id="btnLogin">Login</button>
          <button type="button" id="btnLogout" disabled>Logout</button>
          <button type="button" id="btnSettings">Settings</button>
        </div>
        <small style="margin-top:6px; display:block; color:var(--text-muted);">
          This mockup stores posts in a local SQLite or MariaDB database through PDO.
          It is intended for classroom demonstrations.
        </small>
      </section>
    </aside>

    <main class="main-feed" aria-label="Main content">
      <div class="feed-inner-frame">
        <section class="highlighted" aria-label="Highlighted content">
          <div class="highlighted-title">Highlighted Content</div>
          <div class="highlighted-body" id="highlightedBody">
            Welcome to Bitter v2. The feed below is populated from a database
            through simple PHP JSON endpoints.
          </div>
        </section>

        <section class="composer" aria-label="Create a new post">
          <label for="newPostText">Share a new “Bitter” (short thought or observation):</label>
          <textarea id="newPostText" maxlength="280"
            placeholder="Example: Today I connected a PHP front end to SQLite using PDO."></textarea>
          <div class="composer-row">
            <small id="charCount">0 / 280 characters</small>
            <button type="button" id="btnPost">Post to feed</button>
          </div>
        </section>

        <div class="feed-heading">Content Feed</div>

        <section class="feed-scroll" id="feedScroll" aria-label="Content feed">
          <!-- Posts are injected here -->
        </section>
      </div>
    </main>

    <div class="scroll-bar-hint" aria-hidden="true">
      <div class="scroll-arrow up"></div>
      <div>scroll</div>
      <div class="scroll-arrow down"></div>
    </div>
  </div>

  <footer>
    <h2>References (APA, for educational use)</h2>
    <ol>
      <li>MariaDB Foundation. (2024). <em>MariaDB server documentation</em>. https://mariadb.org</li>
      <li>PHP Group. (2024). <em>PHP manual: PDO</em>. https://www.php.net/manual/en/book.pdo.php</li>
      <li>SQLite Consortium. (2024). <em>SQLite documentation</em>. https://sqlite.org/docs.html</li>
      <li>Mozilla Developer Network. (2025). <em>HTML, CSS, and layout guides</em>. https://developer.mozilla.org/</li>
      <li>OpenAI. (2025). <em>Bitter: Web first educational social app design conversation</em> (ChatGPT conversation with Scott Owen, November 3, 2025).</li>
    </ol>
  </footer>

  <script>
    // Client side logic for Bitter v2.
    // This version retrieves posts from PHP JSON endpoints instead of
    // in-memory arrays so that learners can inspect the database.

    const textarea = document.getElementById("newPostText");
    const charCount = document.getElementById("charCount");
    const btnPost = document.getElementById("btnPost");
    const btnLogin = document.getElementById("btnLogin");
    const btnLogout = document.getElementById("btnLogout");
    const btnSettings = document.getElementById("btnSettings");
    const feedScroll = document.getElementById("feedScroll");
    const statPosts = document.getElementById("statPosts");

    let isLoggedIn = false;
    let postsCache = [];

    function updateCharCount() {
      const len = textarea.value.length;
      charCount.textContent = len + " / 280 characters";
    }

    function renderFeed(posts) {
      postsCache = posts;
      feedScroll.innerHTML = "";
      let highlightText = null;

      posts.forEach((post, index) => {
        const card = document.createElement("article");
        card.className = "post" + (index % 2 === 1 ? " alt" : "");
        card.setAttribute("aria-label", "Post by " + post.display_name);

        const meta = document.createElement("div");
        meta.className = "post-meta";

        const left = document.createElement("span");
        left.innerHTML =
          '<span class="handle">' + post.handle +
          "</span>&nbsp;&middot;&nbsp;" + post.display_name;

        const right = document.createElement("span");
        right.textContent = new Date(post.created_at).toLocaleString();

        meta.appendChild(left);
        meta.appendChild(right);

        const body = document.createElement("div");
        body.className = "post-body";
        body.textContent = post.body;

        card.appendChild(meta);
        card.appendChild(body);
        feedScroll.appendChild(card);

        if (String(post.is_highlight) === "1" && highlightText === null) {
          highlightText = post.body;
        }
      });

      if (highlightText !== null) {
        document.getElementById("highlightedBody").textContent = highlightText;
      }

      statPosts.textContent = String(posts.length);
      feedScroll.scrollTop = feedScroll.scrollHeight;
    }

    async function loadPosts() {
      try {
        const res = await fetch("?api=posts-list");
        if (!res.ok) {
          console.error("Failed to load posts");
          return;
        }
        const posts = await res.json();
        renderFeed(posts);
      } catch (err) {
        console.error("Error loading posts", err);
      }
    }

    async function createPost() {
      const text = textarea.value.trim();
      if (text === "") {
        alert("Please write a short message before posting.");
        return;
      }
      try {
        const res = await fetch("?api=posts-create", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ body: text })
        });
        if (!res.ok) {
          alert("Error creating post.");
          return;
        }
        textarea.value = "";
        updateCharCount();
        await loadPosts();
      } catch (err) {
        console.error("Error creating post", err);
      }
    }

    function toggleLogin(state) {
      isLoggedIn = state;
      btnLogin.disabled = isLoggedIn;
      btnLogout.disabled = !isLoggedIn;
      textarea.disabled = !isLoggedIn;
      btnPost.disabled = !isLoggedIn;

      const bio = document.getElementById("currentUserBio");
      if (isLoggedIn) {
        bio.textContent =
          "Logged in locally. Posts that you create will be written to the database for this demonstration.";
      } else {
        bio.textContent =
          "Viewing in guest mode. Use the Login button to enable posting for this educational demo.";
      }
    }

    // Event wiring
    textarea.addEventListener("input", updateCharCount);
    btnPost.addEventListener("click", createPost);

    btnLogin.addEventListener("click", () => {
      toggleLogin(true);
      alert("This is a simulated login for classroom use. No real accounts are involved.");
    });

    btnLogout.addEventListener("click", () => {
      toggleLogin(false);
      alert("You are now logged out of this demonstration.");
    });

    btnSettings.addEventListener("click", () => {
      alert(
        "Settings would normally control themes, accessibility, and privacy.\n" +
        "In this version the button presents this explanatory message."
      );
    });

    // Initial state
    toggleLogin(false);
    updateCharCount();
    loadPosts();
  </script>
</body>
</html>
