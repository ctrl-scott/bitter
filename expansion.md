````markdown
# Bitter Expansion Plan (ASCII Overview with SQLite or MariaDB)

This document sketches how the **Bitter** educational app can grow from a front-end mockup into a small, database-backed web application. It uses plain ASCII diagrams so it can be read in terminals, wikis, or simple text editors.

-------------------------------------------------------------------------------
1. HIGH-LEVEL ARCHITECTURE
-------------------------------------------------------------------------------

Current state (front-end only):

    +-------------------+
    |   Web Browser     |
    |-------------------|
    | bitter.html       |
    | (HTML/CSS/JS)     |
    +-------------------+

Target state (with backend + DB):

    +-------------------+
    |   Web Browser     |
    |-------------------|
    | HTML / CSS / JS   |
    |  - fetch() calls  |
    +---------+---------+
              |
              |  HTTP / JSON
              v
    +---------------------------+
    |   Application Server      |
    |---------------------------|
    |  /api/login               |
    |  /api/posts               |
    |  /api/users               |
    |  (Node, PHP, or Python)   |
    +---------------------------+
              |
              |  SQL over TCP / file I/O
              v
    +---------------------------+
    |  SQLite or MariaDB        |
    |---------------------------|
    |  tables: users, posts,    |
    |  sessions, highlights     |
    +---------------------------+

Key idea: keep the browser as a **thin client**, move persistence and rules into a server that talks to **SQLite** (single-file, embedded DB) or **MariaDB** (networked SQL server compatible with MySQL). (SQLite Consortium, 2024; MariaDB Foundation, 2024)

-------------------------------------------------------------------------------
2. DATABASE CHOICE: SQLITE VS MARIADB
-------------------------------------------------------------------------------

ASCII comparison for classroom discussion:

    +----------------------+---------------------+----------------------+
    | Feature              | SQLite              | MariaDB              |
    +----------------------+---------------------+----------------------+
    | Storage model        | Single .db file     | Server process with  |
    |                      | on local disk       | data directories     |
    +----------------------+---------------------+----------------------+
    | Typical use          | Desktop / small     | Multi-user web apps, |
    |                      | web apps, protos    | larger deployments   |
    +----------------------+---------------------+----------------------+
    | Concurrency          | File-level locking  | Many concurrent      |
    |                      | (good for modest    | clients with row/    |
    |                      | classroom traffic)  | table-level locking  |
    +----------------------+---------------------+----------------------+
    | Setup                | Zero-config,        | Requires server      |
    |                      | library + file      | install and user     |
    +----------------------+---------------------+----------------------+

**Educational recommendation**

* For a **single-machine demo** or small LAN: use **SQLite** first (less moving parts, easy to copy a single file for backup). (SQLite Consortium, 2024)  
* For a **multi-user lab server** that many students hit at once: use **MariaDB** or another MySQL-compatible server. (MariaDB Foundation, 2024)

-------------------------------------------------------------------------------
3. BASIC SCHEMA (WORKS IN SQLITE OR MARIADB)
-------------------------------------------------------------------------------

Users table:

```sql
CREATE TABLE users (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  handle       VARCHAR(32)  NOT NULL UNIQUE,
  display_name VARCHAR(80)  NOT NULL,
  bio          TEXT,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
````

Posts table:

```sql
CREATE TABLE posts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER      NOT NULL,
  body         VARCHAR(280) NOT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_highlight BOOLEAN      NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Sessions (simple login token example):

```sql
CREATE TABLE sessions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER      NOT NULL,
  token      CHAR(64)     NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
);
```

These schemas use widely supported SQL types and are compatible with both SQLite and MariaDB with only minor adjustments (for example, `AUTOINCREMENT` versus `AUTO_INCREMENT`). (Owens, 2018; MariaDB Foundation, 2024; SQLite Consortium, 2024)

---

4. SIMPLE REQUEST FLOWS

---

4.1. Login flow (email/handle + password conceptually):

```
[Browser] -- POST /api/login --> [Server]
   with JSON: {handle, password}
                       |
                       v
              Validate user in DB
                       |
     if ok, insert row into sessions table
                       |
                       v
[Browser] <-- JSON {token: "..."} -- [Server]
```

Subsequent requests include the token in an `Authorization` header or cookie:

```
Authorization: Bearer abc123token
```

4.2. Fetch posts:

```
[Browser] -- GET /api/posts?limit=20 --> [Server]
                       |
                       v
         SELECT posts + users FROM DB
                       |
                       v
[Browser] <-- JSON array of posts ----- [Server]
```

4.3. Add a post:

```
[Browser] -- POST /api/posts (body, token) --> [Server]
                       |
                       v
             Check session token -> user
                       |
                       v
        INSERT INTO posts (user_id, body) ...
                       |
                       v
[Browser] <-- JSON {id, created_at, ...} -- [Server]
```

These flows follow common REST patterns used in many introductory web-development courses. (Stoyanovich & Abiteboul, 2020)

---

5. ASCII ENTITY–RELATIONSHIP SKETCH

---

```
+-----------+          +-----------+
|  users    | 1      n |  posts    |
|-----------|----------|-----------|
| id        |<-------->| user_id   |
| handle    |          | body      |
| name      |          | created_at|
+-----------+          +-----------+
       |
       | 1
       |      n
+-----------+
| sessions  |
|-----------|
| id        |
| user_id   |
| token     |
| expires_at|
+-----------+
```

---

6. MIGRATION PATH FROM FRONT-END ONLY

---

Step 1 — **Extract demo data**

* Move hard-coded users and posts into SQL `INSERT` statements or a seed script.
* Create the database file / schema:

  * SQLite: run a `.sql` script with the `sqlite3` command-line shell. (SQLite Consortium, 2024)
  * MariaDB: connect with `mysql` client and run the same script. (MariaDB Foundation, 2024)

Step 2 — **Introduce a thin API server**

* Choose language familiar to your class (Node.js, PHP, or Python).
* Implement minimal endpoints:

  * `GET /api/posts`
  * `POST /api/posts`
  * `GET /api/users`
  * `POST /api/login`

Step 3 — **Update Bitter front-end**

* Replace in-memory arrays with `fetch("/api/...")` calls.
* Keep the UI logic (rendering, DOM updates) almost unchanged.

Step 4 — **Persistence and backup**

SQLite:

```
$ sqlite3 bitter.db ".backup 'bitter_backup.db'"
```

MariaDB (example):

```
$ mysqldump -u bitter_user -p bitter_db > bitter_backup.sql
```

Both approaches introduce students to **backup and restore** concepts in a controlled way. (MariaDB Foundation, 2024; SQLite Consortium, 2024)

---

7. SECURITY AND PRIVACY NOTES (EDUCATIONAL)

---

If you decide to use:

* Use **simple passwords and non-production keys**, never real credentials.
* Restrict DB access to the lab network or localhost.
* Emphasize this app is a **non-commercial demonstration**, not a production social network.

These practices align with introductory database and web-security guidance: keep educational systems isolated and low-risk. (Stallings, 2019)

---

8. REFERENCES (APA, INCLUDING THIS CHAT)

---

MariaDB Foundation. (2024). *MariaDB server documentation*. [https://mariadb.org](https://mariadb.org)

SQLite Consortium. (2024). *SQLite documentation*. [https://sqlite.org/docs.html](https://sqlite.org/docs.html)

Owens, M. (2018). *The definitive guide to SQLite (3rd ed.)*. Apress.

Stallings, W. (2019). *Effective cybersecurity: A guide to using best practices and standards*. Addison-Wesley.

Stoyanovich, J., & Abiteboul, S. (2020). *Data, responsibly: Fairness, neutrality and transparency in data management systems*. Morgan & Claypool.

OpenAI. (2025). *Bitter: Web-first educational social app design conversation* (ChatGPT conversation with Scott Owen, November 3, 2025).

```

:
```
