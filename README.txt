Setup checklist (quick)
Create a new MySQL/MariaDB database and import studentregisterationsystem.sql (you uploaded earlier). 
Example: mysql -u root -p studentregisterationsystem < studentregisterationsystem.sql

Edit config.php with your DB credentials.

Make sure style.css and the img/ folder remain in the same paths as the HTML pages (I used the same references).  

Ensure PHP sessions and mail (if you later enable mail) are configured. The contact form currently writes messages to messages.txt (no mail dependency).

Open index.php in browser. Register a user and then, if you want an admin, manually set Role='Admin' for that user's row in the user table via phpMyAdmin or a SQL query.

Security notes & decisions
Login & registration use prepared statements and password_hash/password_verify to prevent SQL injection (login protection requested). See login.php and registration.php. 

The admin area checks $_SESSION['user']['Role']=='Admin' — only Admins can access.

Input is validated minimally server-side; for production you should apply stricter validation, CSRF protection, HTTPS, rate limiting, and optional reCAPTCHA for registration/login.

I did not change your front-end design — all page layouts and CSS are preserved. Example: the original login.html and registration.html were used as the base.  

What I couldn't/shouldn't do automatically
I did not create or seed additional DB tables (e.g., messages table) — contact messages are stored in messages.txt to avoid changing your schema automatically. If you prefer messages in the DB I can add a migration and table.

I kept the timetable image-lookup behavior unchanged (JS selecting img/SemX_CODEY.jpg like original). 

If you want I can:

add CSRF tokens to forms,

move messages from messages.txt into a DB table (and update the SQL dump),

create a prettier admin UI (e.g., pagination, search),

or provide the full zip for download.

Tell me which of those (or anything else) you'd like — and I’ll modify the code right away.

