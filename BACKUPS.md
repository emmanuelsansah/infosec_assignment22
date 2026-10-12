# Google Drive Backups

This project runs on PHP/XAMPP and MySQL. The backup runner exports the configured MySQL database with `mysqldump`, then archives the project files (including project-local upload directories if added later) as a `.tar.gz`. It excludes environment files, logs, temporary files, and token/credential JSON files. Backups are uploaded through the Google Drive REST API using OAuth 2.0 and stored in a Drive folder named `Project Backups`.

The app currently has no uploads directory. Files stored outside the project directory are not included automatically; add their path to the archive allowlist in `backup_lib.php` before relying on them being backed up.

OAuth credentials and refresh tokens are never sent to the browser. Existing app secrets were moved to `C:\xampp\private\INFOSEC\app-secrets.php`, outside `htdocs`; the existing password pepper is preserved there so current account hashes continue to verify. Restrict this directory to the Windows account running Apache/Task Scheduler and `SYSTEM`. Do not copy it into the project or commit it.

## Google Cloud Setup

1. In Google Cloud Console, select or create a project and enable **Google Drive API**.
2. Configure the OAuth consent screen. For an individual Google account, choose External, add your account as a test user while setting up, and complete the required app details.
3. Create an OAuth client ID of type **Web application**.
4. Add the exact callback URL used by this installation as an authorized redirect URI. For the usual XAMPP URL it is `http://localhost/INFOSEC/google_drive_callback.php`. If you use the PHP development server, use `http://127.0.0.1:8000/google_drive_callback.php` instead. The URI in Google Cloud and `INFOSEC_GOOGLE_REDIRECT_URI` must match exactly.
5. Add these **User** environment variables in Windows Environment Variables. Do not put their values in project files, source control, or chat:

   - `INFOSEC_GOOGLE_CLIENT_ID`: OAuth client ID.
   - `INFOSEC_GOOGLE_CLIENT_SECRET`: OAuth client secret.
   - `INFOSEC_GOOGLE_REDIRECT_URI`: the exact authorized callback URI above.
   - `INFOSEC_BACKUP_RETENTION_DAYS`: optional; defaults to `30`.
   - `INFOSEC_MYSQLDUMP_PATH`: optional; defaults to `C:\xampp\mysql\bin\mysqldump.exe`.
   - `INFOSEC_PRIVATE_DIR`: optional; defaults to `C:\xampp\private\INFOSEC`.

   Open **Edit environment variables for your account** from the Windows Start menu and add the values there, rather than putting the client secret in a command or script. Restart Apache and open a new terminal after changing environment variables.

6. Ensure an existing account has `role = 'admin'` using phpMyAdmin or an authorized database administrator. Public registration intentionally cannot grant administrator rights.
7. Sign in as that administrator, open **Google Drive Backups** on `admin.php`, choose **Connect Google Drive**, and approve access. The app requests only the `drive.file` scope, which lets it manage files it creates; it does not request broad access to your Drive.
8. Confirm the dashboard reports **Connected**, then run `php C:\xampp\htdocs\INFOSEC\backup_run.php --check` in a new terminal. This checks OAuth and creates/finds the dedicated folder without creating a backup.

The client secret is an OAuth application credential, not your Google password. Google sign-in happens on Google's page; this project never asks for or stores your Google password. The refresh token is stored server-side under the private directory.

## Test And Manual Operations

Run a local database export/archive test without contacting Google Drive:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\INFOSEC\backup_run.php --self-test
```

Run a manual cloud backup from the admin dashboard, or from a terminal after OAuth is connected:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\INFOSEC\backup_run.php --run
```

The CLI exits nonzero on failure. An entry is marked successful only after Drive confirms the uploaded file ID and size and, when returned by Drive, its MD5 checksum. A restore is available only for successful, non-expired backup entries. It verifies the downloaded SHA-256 against the local history, creates a fresh safety backup first, and imports the dump into the existing database without dropping the database itself. Confirmations are required in the admin interface.

History and operation errors are recorded in the separate `infosec_backup_history` table and PHP's server error log. The original project database is never deleted by upload or retention cleanup. Retention removes only expired backup files from Drive; history rows remain visible.

## Daily Scheduling On XAMPP

This workspace is running on a Windows XAMPP machine, not a managed hosting provider. Windows Task Scheduler is available. Once OAuth is connected and `--check` succeeds, open PowerShell as the same Windows account used by XAMPP, then run:

```powershell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\INFOSEC\install_backup_task.ps1
```

The installer preflights Drive authorization before registering a daily 2:00 AM local-time task. It prompts for the Windows account password through a secure credential dialog; Windows Task Scheduler stores the task credential, not this project. The task runs PHP CLI directly, so Apache does not need to be running at backup time. MySQL must be running, the machine must be awake and connected to the internet, and the scheduled-task account must be able to read the private directory. `StartWhenAvailable` runs a missed task after the machine wakes.

After registration, open **Task Scheduler Library**, run **INFOSEC Google Drive Backup** once, and check the admin dashboard for a successful backup. Until OAuth is connected, the task is registered, and that scheduled run succeeds, automatic backups are **not configured or verified**. For production hosting, use that provider's persistent cron/scheduler and invoke `php backup_run.php --run`; do not expose the CLI runner over HTTP.

To remove the task, run:

```powershell
Unregister-ScheduledTask -TaskName 'INFOSEC Google Drive Backup' -Confirm:$false
```

## Costs And Limitations

The Drive API itself is generally available without a per-upload charge within Google's quotas. Backup files consume the storage quota of the connected Google account, shared with its other Google services. A personal Google account currently has a limited free storage allowance; exceeding it may require a paid Google One/Workspace plan. No AWS account or AWS service is used. Review Google's current quota and storage terms before production use.

OAuth apps left in Google's External **Testing** publishing state can have refresh-token lifetime restrictions. Before depending on daily unattended runs, review the consent-screen publishing status and current Google verification requirements for the scopes in use. If the token is revoked or expires, the dashboard and scheduled-run history will report failures until an administrator reconnects Drive.