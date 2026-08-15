<#
.SYNOPSIS
  Copies the production database into the local docker postgres container.

.DESCRIPTION
  Gives you a prod-shaped local stack â€” real table sizes, real index selectivity,
  real query plans â€” without prod credentials in your day-to-day config and
  without any possibility of writing to prod.

  Runs pg_dump / pg_restore from inside the postgres:16 container, so no
  PostgreSQL client tools need to be installed on Windows, and the client major
  version always matches the server.

  Two sources, chosen with -Source:

    tunnel   (default) dump straight from prod through scripts/tunnel-prod-db.ps1.
             Fastest. Read-only, but it does add I/O load to the live db.t4g.small.

    snapshot restore the most recent automated snapshot to a temporary RDS
             instance, dump from that, then delete it. Zero load and zero risk on
             prod, but takes ~20 min and costs a few cents of instance time.

.EXAMPLE
  # terminal 1
  powershell -File scripts/tunnel-prod-db.ps1
  # terminal 2
  powershell -File scripts/clone-prod-to-local.ps1

.NOTES
  The local target is the `db` service in docker-compose.local.yml
  (127.0.0.1:54321, database komiut, user komiut, password secret). It is
  DROPPED and recreated. Nothing here can write to production: pg_dump only
  reads, and the restore targets the local container.
#>
[CmdletBinding()]
param(
    [ValidateSet('tunnel', 'snapshot')]
    [string]$Source     = 'tunnel',
    [string]$Region     = 'eu-central-1',
    [string]$TunnelPort = '15432',
    [switch]$SchemaOnly
)

$ErrorActionPreference = 'Stop'
$scratch = Join-Path ([System.IO.Path]::GetTempPath()) 'komiut-clone'
New-Item -ItemType Directory -Force -Path $scratch | Out-Null
$dumpFile = Join-Path $scratch 'prod.dump'

function Get-ProdPassword {
    aws ssm get-parameter --region $Region --name /komiut/prod/DB_PASSWORD `
        --with-decryption --query 'Parameter.Value' --output text
}

$tempInstance = $null
try {
    if ($Source -eq 'snapshot') {
        $snap = (aws rds describe-db-snapshots --region $Region `
            --db-instance-identifier komiut-prod-db `
            --query "reverse(sort_by(DBSnapshots[?Status=='available'],&SnapshotCreateTime))[0].DBSnapshotIdentifier" `
            --output text)
        $tempInstance = 'komiut-clone-tmp'
        Write-Host "Restoring $snap to $tempInstance (~15-20 min)..." -ForegroundColor Cyan

        # Public so we can reach it directly; it holds a copy, lives for minutes,
        # and is deleted in the finally block below.
        aws rds restore-db-instance-from-db-snapshot --region $Region `
            --db-instance-identifier $tempInstance `
            --db-snapshot-identifier $snap `
            --db-instance-class db.t4g.small --publicly-accessible --no-multi-az | Out-Null
        aws rds wait db-instance-available --region $Region --db-instance-identifier $tempInstance

        $dbHost = (aws rds describe-db-instances --region $Region `
            --db-instance-identifier $tempInstance `
            --query 'DBInstances[0].Endpoint.Address' --output text)
        $dbPort = '5432'
    }
    else {
        # The tunnel already terminates on localhost; from inside a container the
        # Windows host is host.docker.internal.
        $probe = Test-NetConnection -ComputerName 127.0.0.1 -Port $TunnelPort -WarningAction SilentlyContinue
        if (-not $probe.TcpTestSucceeded) {
            throw "Nothing is listening on 127.0.0.1:$TunnelPort. Run scripts/tunnel-prod-db.ps1 in another terminal first."
        }
        $dbHost = 'host.docker.internal'
        $dbPort = $TunnelPort
    }

    $pw = Get-ProdPassword
    $dumpArgs = @('-Fc', '--no-owner', '--no-privileges')
    if ($SchemaOnly) { $dumpArgs += '--schema-only' }

    Write-Host "Dumping from $dbHost`:$dbPort ..." -ForegroundColor Cyan
    docker run --rm -e PGPASSWORD=$pw -v "${scratch}:/out" postgres:16 `
        pg_dump -h $dbHost -p $dbPort -U komiut -d komiut @dumpArgs -f /out/prod.dump
    if ($LASTEXITCODE -ne 0) { throw "pg_dump failed ($LASTEXITCODE)" }
    Write-Host ("  dump: {0:N1} MB" -f ((Get-Item $dumpFile).Length / 1MB)) -ForegroundColor Green

    Write-Host 'Recreating the local database...' -ForegroundColor Cyan
    docker compose -f docker-compose.local.yml up -d db | Out-Null
    Start-Sleep -Seconds 3
    docker run --rm -e PGPASSWORD=secret postgres:16 `
        psql -h host.docker.internal -p 54321 -U komiut -d postgres `
        -c 'DROP DATABASE IF EXISTS komiut WITH (FORCE);' -c 'CREATE DATABASE komiut OWNER komiut;'
    if ($LASTEXITCODE -ne 0) { throw "could not recreate the local database ($LASTEXITCODE)" }

    Write-Host 'Restoring into local...' -ForegroundColor Cyan
    docker run --rm -e PGPASSWORD=secret -v "${scratch}:/out" postgres:16 `
        pg_restore -h host.docker.internal -p 54321 -U komiut -d komiut --no-owner --no-privileges /out/prod.dump
    # pg_restore exits 1 on benign "already exists" notices; surface but do not fail.
    if ($LASTEXITCODE -ne 0) { Write-Host "  pg_restore returned $LASTEXITCODE (usually harmless notices)" -ForegroundColor Yellow }

    Write-Host ''
    Write-Host 'Done. Local komiut now mirrors production.' -ForegroundColor Green
    Write-Host 'Point .env at the LOCAL block (DB_PORT=54321, DB_PASSWORD=secret).' -ForegroundColor Green
}
finally {
    if ($tempInstance) {
        Write-Host "Deleting $tempInstance ..." -ForegroundColor Cyan
        aws rds delete-db-instance --region $Region --db-instance-identifier $tempInstance `
            --skip-final-snapshot --delete-automated-backups | Out-Null
    }
    Remove-Item $dumpFile -ErrorAction SilentlyContinue
}

