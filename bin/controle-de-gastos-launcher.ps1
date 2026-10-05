$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php -ErrorAction Stop).Source

try {
    $process = Start-Process -FilePath $php -ArgumentList @('artisan', 'app:launch') -WorkingDirectory $project -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        throw "O iniciador terminou com código $($process.ExitCode)."
    }
} catch {
    Add-Content -Path (Join-Path $project 'storage/logs/launcher.log') -Value "$(Get-Date -Format o) $($_.Exception.Message)"
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show('Não foi possível iniciar o Controle de Gastos. Consulte storage/logs/launcher.log.', 'Controle de Gastos')
}
