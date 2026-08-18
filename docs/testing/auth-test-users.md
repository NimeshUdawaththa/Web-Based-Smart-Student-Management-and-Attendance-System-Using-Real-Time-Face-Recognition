# Development authentication test users

These accounts are created by the **development-only** CLI script:

`database/scripts/create_dev_test_users.php`

They exist only for local testing on the `dev` branch. Do not use them in production.

## Create the four test accounts

From the project root, with XAMPP MySQL running:

```powershell
C:\xampp\php\php.exe database/scripts/create_dev_test_users.php
```

The script:

- runs only from the command line (not via the browser)
- refuses to run when `APP_ENV=production` or `APP_DEBUG` is false
- hashes passwords with `password_hash()` before inserting into `users`
- updates the same accounts if you run it again

## Test credentials

| Role | Username | Email | Password |
|---|---|---|---|
| ADMIN | `admin` | `admin@localhost.test` | `Admin123!` |
| ACADEMIC_STAFF | `academic_staff` | `academic_staff@localhost.test` | `Academic123!` |
| LECTURER | `lecturer` | `lecturer@localhost.test` | `Lecturer123!` |
| STUDENT | `student` | `student@localhost.test` | `Student123!` |

Login also works with the email address instead of the username.

## Disable or remove before production

1. Delete `database/scripts/create_dev_test_users.php`.
2. Delete `database/scripts/README.md` and this file if they are no longer needed.
3. Remove the accounts from MySQL:

```sql
DELETE FROM users
WHERE username IN ('admin', 'academic_staff', 'lecturer', 'student');
```

No plain-text passwords are stored in `schema.sql`.
