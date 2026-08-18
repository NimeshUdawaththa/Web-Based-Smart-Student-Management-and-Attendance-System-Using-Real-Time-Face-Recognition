# Development test users

This folder is **not** part of the production web application.

`create_dev_test_users.php` inserts or updates one `ACTIVE` login for each role. Passwords are stored with PHP `password_hash()` only. They are never written to `schema.sql`.

## Create the four test accounts

From the project root, with XAMPP MySQL running:

```powershell
C:\xampp\php\php.exe database/scripts/create_dev_test_users.php
```

The script prints the usernames, emails, and development passwords once in the terminal.

It will refuse to run:

- over HTTP (CLI only)
- when `APP_ENV=production`
- when `APP_DEBUG` is false

## Remove / disable later

1. Delete `database/scripts/create_dev_test_users.php`.
2. Delete this README if it is no longer needed.
3. Remove the accounts from MySQL:

```sql
DELETE FROM users
WHERE username IN ('admin', 'academic.staff', 'lecturer', 'student');
```
