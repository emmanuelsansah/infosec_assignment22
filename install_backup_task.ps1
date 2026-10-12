$ErrorActionPreference = 'Stop'

$projectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpExecutable = if ($env:XAMPP_HOME) {
    Join-Path $env:XAMPP_HOME 'php\php.exe'
} else {
    'C:\xampp\php\php.exe'
}
$runner = Join-Path $projectDirectory 'backup_run.php'
$taskName = 'INFOSEC Google Drive Backup'

if (-not (Test-Path $phpExecutable)) {
    throw "PHP was not found at $phpExecutable. Set XAMPP_HOME or update this script."
}

& $phpExecutable $runner '--check'
if ($LASTEXITCODE -ne 0) {
    throw 'Google Drive preflight failed. Connect Drive and fix the reported error before installing the schedule.'
}

$taskUser = "$env:USERDOMAIN\$env:USERNAME"
$credential = Get-Credential -UserName $taskUser -Message 'Enter the Windows account password for the daily INFOSEC backup task.'
if ($null -eq $credential) {
    throw 'Task registration was cancelled.'
}

$action = New-ScheduledTaskAction -Execute $phpExecutable -Argument "`"$runner`" --run" -WorkingDirectory $projectDirectory
$trigger = New-ScheduledTaskTrigger -Daily -At '2:00AM'
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 3) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 15)
$password = $credential.GetNetworkCredential().Password

try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -User $credential.UserName `
        -Password $password `
        -RunLevel Limited `
        -Description 'Daily database and project-file backup to the Project Backups folder in Google Drive.' `
        -Force | Out-Null
} finally {
    $password = $null
    $credential = $null
}

Write-Output "Registered '$taskName' to run daily at 2:00 AM local time. Run it once from Task Scheduler and confirm a successful entry in the admin dashboard before relying on automatic backups."