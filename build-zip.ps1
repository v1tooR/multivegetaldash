# Empacota o plugin para instalação no WordPress.
#
# NÃO use Compress-Archive aqui. No Windows PowerShell 5.1 ele grava os
# nomes das entradas com barra invertida ("mv-analytics\includes\data.php").
# A especificação ZIP exige barra normal, e o extrator do PHP no Linux não
# lê "\" como separador de pasta: ele cria arquivos com esse nome literal,
# todos soltos na raiz. O plugin então "ativa" reclamando de arquivo faltando.
#
# Este script monta as entradas à mão, com barra normal, e confere o
# resultado antes de terminar.
#
# Uso:  powershell -ExecutionPolicy Bypass -File build-zip.ps1

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$src  = Join-Path $root 'mv-analytics'
$out  = Join-Path $root 'mv-analytics.zip'

if (-not (Test-Path $src)) { throw "Pasta do plugin nao encontrada: $src" }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

if (Test-Path $out) { Remove-Item $out -Force }

$zip = [System.IO.Compression.ZipFile]::Open($out, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -Path $src -Recurse -File | Sort-Object FullName | ForEach-Object {
        $rel   = $_.FullName.Substring($src.Length).TrimStart('\')
        $entry = 'mv-analytics/' + ($rel -replace '\\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
        Write-Output "  + $entry"
    }
} finally {
    $zip.Dispose()
}

# Conferência: nenhuma barra invertida, uma única pasta de topo.
$check = [System.IO.Compression.ZipFile]::OpenRead($out)
$names = @($check.Entries | ForEach-Object { $_.FullName })
$check.Dispose()

$bad = @($names | Where-Object { $_ -like '*\*' })
if ($bad.Count -gt 0) { throw "Entradas com barra invertida: $($bad -join ', ')" }

$top = @($names | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
if ($top.Count -ne 1 -or $top[0] -ne 'mv-analytics') {
    throw "Esperava uma unica pasta de topo 'mv-analytics', encontrei: $($top -join ', ')"
}

Write-Output ""
Write-Output "OK  $($names.Count) arquivos, $((Get-Item $out).Length) bytes"
Write-Output "    $out"
