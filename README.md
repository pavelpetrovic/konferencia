# Minikonferencia z fyziky

PHP 8.1+ / MySQL 8+ / PHPMailer application. Public Apache document root: **web/**.

## Install

1. Create an empty MySQL database and database user (UTF-8).
2. Import schema **once**: `mysql -u konferencia -p konferencia < database/schema.sql`
3. `cp config.example.php config.php` and edit DB and SMTP credentials (can reuse the Gmail SMTP settings from the tablet app).
4. `composer install --no-dev --optimize-autoloader`
5. Configure Apache HTTPS virtual host DocumentRoot to `<repository>/web`. Enable `AllowOverride All` or disable directory listing in the vhost.
6. Import CSVs from a private location (do **not** commit students' emails to a public repository):
   `php scripts/import.php students 3AA /private/ziaci_3AA.csv`
   `php scripts/import.php topics 3AA /private/temy_3AA.csv`
7. Add lessons in phpMyAdmin or mysql:
   `INSERT INTO lessons(class_id,starts_at,note) SELECT id,'2026-12-03 09:00:00','' FROM classes WHERE name='3AA';`
   Each lesson automatically has 8 five-minute slots, starting at the configured time.

## CSV format

UTF-8 with headers, comma or semicolon separated, with CSV quoting:
- Students: `name,email`
- Topics: `number,title`

Imports update matching records and preserve existing assignments; they do not delete absent records. Clear the DB manually between conference cycles if desired. Classes are rows in the `classes` table, without inactive flags. Add, rename or delete classes in MySQL. Deleting a class cascades to its students, topics, lessons and selections.

## Rules

- Students select their class and name and request an access code by email.
- Requesting a new code invalidates the previous code. Requests have a two-minute cooldown.
- Students sign in using their name and emailed code. Session login remains valid until logout or session expiry.
- Each student can choose one topic and one slot independently, and change or release either.
- Each topic and each lesson slot can belong to at most one student.
- The schedule and unassigned topics are public for the selected class; changes require student authentication.
- Editing `lessons.starts_at` moves all 8 assigned slots together. Two lessons can share a date, but not the same start time in one class.
- Email is sent **only** for access-code requests. No reminder or assignment emails.
- Server timezone defaults to Europe/Bratislava.

## Security / operations

Store `config.php` outside the public document root and never commit it. Keep `vendor/` outside `web/`.
Use HTTPS. PHP sessions use secure, HTTP-only cookies and CSRF tokens.
The email request endpoint has a two-minute per-student cooldown. For a public-facing deployment, add Apache-level rate limiting to protect against automated login attempts and mass requests.
The system does not include a web administration page; use mysql/phpMyAdmin and the CSV importer.
