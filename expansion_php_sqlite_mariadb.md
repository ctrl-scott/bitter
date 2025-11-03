````markdown
# Bitter Expansion Plan #2  
## PHP + HTML Front End with SQLite or MariaDB Backend

This document describes how to evolve **Bitter** from a front-end-only mockup into a small **PHP-based web app** with either **SQLite** or **MariaDB** as the database. It is written in ASCII-friendly Markdown for class notes or GitHub docs.

-------------------------------------------------------------------------------
1. OVERVIEW: FROM STATIC HTML TO PHP APP
-------------------------------------------------------------------------------

Current situation:

    browser -> bitter.html (static)
             -> all data lives in JavaScript arrays

Target situation (PHP-based):

    browser -> Apache / Nginx / Caddy
             -> bitter.php (front controller)
             -> PHP scripts use PDO to talk to SQLite OR MariaDB
             -> DB stores users, posts, sessions, highlights

Key technology points:

- **HTML/CSS/JS** stay nearly the same, but `.html` becomes `.php`.
- PHP uses **PDO** (PHP Data Objects) so we can switch between SQLite and MariaDB with minimal code changes (PHP Group, 2024).
- Database choice is controlled by a single configuration file.

-------------------------------------------------------------------------------
2. RECOMMENDED DIRECTORY LAYOUT
-------------------------------------------------------------------------------

Example project tree:

    bitter/
    ├── public/
    │   ├── index.php        # main UI (formerly bitter.html)
    │   ├── assets/
    │   │   ├── css/
    │   │   │   └── style.css
    │   │   └── js/
    │   │       └── app.js   # optional, or inline in index.php
    ├── config/
    │   └── config.php       # DB configuration + helper
    ├── src/
    │   ├── db.php           # PDO connection factory
    │   ├── PostRepository.php
    │   ├── UserRepository.php
    │   └── api/
    │       ├── login.php
    │       ├── logout.php
    │       ├── posts_list.php
    │       └── posts_create.php
    └── sql/
        ├── schema_sqlite.sql
        └── schema_mariadb.sql

This structure separates **public assets** from **PHP logic** and **SQL scripts**, which matches common PHP application organization used in tutorials and small frameworks (Lerdorf et al., 2013).

-------------------------------------------------------------------------------
3. DATABASE CONFIGURATION VIA PDO
-------------------------------------------------------------------------------

### 3.1 `config/config.php`

```php
<?php
// config/config.php

// Choose "sqlite" or "mariadb" for the demo:
define('BITTER_DB_DRIVER', 'sqlite');  // or 'mariadb'

// SQLite configuration (single file)
define('BITTER_SQLITE_PATH', __DIR__ . '/../bitter.db');

// MariaDB configuration (TCP connection)
define('BITTER_MARIADB_DSN',  'mysql:host=localhost;dbname=bitter;charset=utf8mb4');
define('BITTER_MARIADB_USER', 'bitter_user');
define('BITTER_MARIADB_PASS', 'change_me');
````

### 3.2 PDO Connection Factory (`src/db.php`)

```php
<?php
// src/db.php
require_once __DIR__ . '/../config/config.php';

function bitter_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (BITTER_DB_DRIVER === 'sqlite') {
        $dsn = 'sqlite:' . BITTER_SQLITE_PATH;
        $pdo = new PDO($dsn);
        // Enable foreign keys in SQLite:
        $pdo->exec('PRAGMA foreign_keys = ON');
    } elseif (BITTER_DB_DRIVER === 'mariadb') {
        $dsn  = BITTER_MARIADB_DSN;
        $user = BITTER_MARIADB_USER;
        $pass = BITTER_MARIADB_PASS;
        $pdo = new PDO($dsn, $user, $pass);
    } else {
        throw new RuntimeException('Unsupported DB driver: ' . BITTER_DB_DRIVER);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}
```

Using PDO allows the same API (`prepare`, `execute`, `fetch`) for both SQLite and MariaDB, which is recommended in official PHP documentation (PHP Group, 2024).

---

4. SQL SCHEMAS FOR SQLITE AND MARIADB

---

### 4.1 SQLite schema (`sql/schema_sqlite.sql`)

```sql
CREATE TABLE users (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  handle       TEXT    NOT NULL UNIQUE,
  display_name TEXT    NOT NULL,
  bio          TEXT,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE posts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL,
  body         TEXT    NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_highlight INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE sessions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  token      TEXT    NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Run with:

```bash
sqlite3 bitter.db < sql/schema_sqlite.sql
```

(SQLite Consortium, 2024)

### 4.2 MariaDB schema (`sql/schema_mariadb.sql`)

```sql
CREATE TABLE users (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  handle       VARCHAR(32)  NOT NULL UNIQUE,
  display_name VARCHAR(80)  NOT NULL,
  bio          TEXT,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE posts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  body         VARCHAR(280) NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_highlight TINYINT(1)   NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sessions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token      CHAR(64)     NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
```

Run with:

```bash
mysql -u bitter_user -p bitter_db < sql/schema_mariadb.sql
```

(MariaDB Foundation, 2024)

---

5. SIMPLE PHP APIs

---

### 5.1 List posts – `src/api/posts_list.php`

```php
<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = bitter_get_pdo();

$stmt = $pdo->query(
  'SELECT p.id, p.body, p.created_at, u.handle, u.display_name
   FROM posts p
   JOIN users u ON p.user_id = u.id
   ORDER BY p.created_at ASC
   LIMIT 50'
);

$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($posts);
```

### 5.2 Create post – `src/api/posts_create.php`

This example assumes a simple token-based session stored in the `sessions` table.

```php
<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = bitter_get_pdo();

// Very small helper to read JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$token = $data['token'] ?? '';
$body  = trim($data['body'] ?? '');

if ($token === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token or body']);
    exit;
}

// Look up session
$stmt = $pdo->prepare(
  'SELECT user_id FROM sessions
   WHERE token = :token AND expires_at > CURRENT_TIMESTAMP'
);
$stmt->execute([':token' => $token]);
$userId = $stmt->fetchColumn();

if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid session']);
    exit;
}

// Insert post
$stmt = $pdo->prepare(
  'INSERT INTO posts (user_id, body) VALUES (:uid, :body)'
);
$stmt->execute([
  ':uid'  => $userId,
  ':body' => $body,
]);

echo json_encode([
  'id'         => $pdo->lastInsertId(),
  'user_id'    => $userId,
  'body'       => $body,
  'created_at' => date('Y-m-d H:i:s'),
]);
```

The pattern of **validate input → query with prepared statements → return JSON** is standard practice for secure PHP database APIs (Stallings, 2019; PHP Group, 2024).

---

6. LINKING FRONT-END (index.php) TO PHP APIs

---

`public/index.php` can embed the existing HTML structure for Bitter, but update JavaScript to call the PHP endpoints.

Example JavaScript snippet that replaces in-memory posts:

```html
<script>
async function loadPosts() {
  const res = await fetch('/src/api/posts_list.php');
  if (!res.ok) {
    console.error('Failed to load posts');
    return;
  }
  const posts = await res.json();
  renderFeed(posts);  // reuse your existing DOM rendering logic
}

async function createPost(body, token) {
  const res = await fetch('/src/api/posts_create.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ body, token })
  });
  if (!res.ok) {
    alert('Error creating post');
    return;
  }
  const created = await res.json();
  // Option 1: reload full list
  // Option 2: append to feed in the DOM
  loadPosts();
}
</script>
```

This preserves the **web-first** idea: the browser still controls the interface, but now data is fetched from PHP APIs instead of a static array.

---

7. SWITCHING BETWEEN SQLITE AND MARIADB

---

To flip backends:

1. Change `BITTER_DB_DRIVER` in `config/config.php`:

   * `'sqlite'` → use `bitter.db`.
   * `'mariadb'` → use the configured MariaDB server.
2. Ensure you have run the appropriate schema script in `sql/`.
3. Restart the web server if necessary.

Because all queries go through **PDO** and the schema is nearly identical, most PHP code does not need to change when switching databases, which is precisely why PDO was introduced (PHP Group, 2024).

---

8. BACKUP AND RESET WORKFLOWS

---

SQLite:

```bash
# backup
sqlite3 bitter.db ".backup 'bitter_backup.db'"

# reset from schema + seed
rm bitter.db
sqlite3 bitter.db < sql/schema_sqlite.sql
```

MariaDB:

```bash
# backup
mysqldump -u bitter_user -p bitter_db > bitter_backup.sql

# reset
mysql -u bitter_user -p -e "DROP DATABASE bitter_db;
                             CREATE DATABASE bitter_db;"
mysql -u bitter_user -p bitter_db < sql/schema_mariadb.sql
```

These commands expose students to real-world backup/restore practices in a controlled lab environment (MariaDB Foundation, 2024; SQLite Consortium, 2024).

---

9. REFERENCES (APA, INCLUDING THIS CHAT)

---

Lerdorf, R., Tatroe, K., & Bake, P. (2013). *Programming PHP* (3rd ed.). O’Reilly Media.

MariaDB Foundation. (2024). *MariaDB server documentation*. [https://mariadb.org](https://mariadb.org)

PHP Group. (2024). *PHP manual: PDO* and related documentation. [https://www.php.net/manual/en/book.pdo.php](https://www.php.net/manual/en/book.pdo.php)

SQLite Consortium. (2024). *SQLite documentation*. [https://sqlite.org/docs.html](https://sqlite.org/docs.html)

Stallings, W. (2019). *Effective cybersecurity: A guide to using best practices and standards*. Addison-Wesley.

OpenAI. (2025). *Bitter: Web-first educational social app design conversation* (ChatGPT conversation with Scott Owen, November 3, 2025).

```
:
```
