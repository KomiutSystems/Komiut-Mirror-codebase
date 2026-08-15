<#
.SYNOPSIS
  Opens an SSM port-forward from localhost:15432 to the production RDS instance.

.DESCRIPTION
  komiut-prod-db is PubliclyAccessible=False and its security group only admits
  the app instances, so it cannot be reached from a laptop directly. This
  forwards through a running, SSM-managed app instance instead:

      laptop:15432  ->  SSM (AWS-managed, no inbound ports)  ->  app EC2  ->  RDS:5432

  Nothing is exposed to the internet, no SSH key is involved, and every session
  is recorded in CloudTrail against your IAM identity.

  Leave this running in its own terminal. Ctrl-C closes the tunnel.

.NOTES
  While the tunnel is up and the prod DB block in .env is uncommented, artisan
  talks to PRODUCTION. `migrate:fresh`, `db:wipe` and `migrate --force` will
  destroy real data with no prompt. Prefer read-only work.

  Requires: aws CLI v2, session-manager-plugin, and ssm:StartSession on the
  target instance.
#>
[CmdletBinding()]
param(
    [string]$Region     = 'eu-central-1',
    # 5432 is usually taken by Docker Desktop's WSL relay on this machine, so the
    # tunnel lands on 15432 instead. Match DB_PORT in .env to whatever you use.
    [string]$LocalPort  = '15432',
    # Any RUNNING, SSM-managed app instance works â€” it is only the network hop.
    # Discovered automatically when left blank.
    [string]$InstanceId = ''
)

$ErrorActionPreference = 'Stop'

if (-not $InstanceId) {
    Write-Host 'Finding a running SSM-managed app instance...' -ForegroundColor Cyan
    $InstanceId = (aws ssm describe-instance-information --region $Region `
        --query "InstanceInformationList[?PingStatus=='Online']|[0].InstanceId" --output text)
    if (-not $InstanceId -or $InstanceId -eq 'None') {
        throw "No SSM-managed instance is online in $Region. Is the app fleet running?"
    }
}

# Read the endpoint from RDS rather than hardcoding it, so a failover or a
# rebuilt instance does not silently point this at the wrong database.
$RdsHost = (aws rds describe-db-instances --region $Region `
    --db-instance-identifier komiut-prod-db `
    --query 'DBInstances[0].Endpoint.Address' --output text)

Write-Host ''
Write-Host "  via instance : $InstanceId" -ForegroundColor Green
Write-Host "  to RDS       : $RdsHost" -ForegroundColor Green
Write-Host "  listening on : 127.0.0.1:$LocalPort" -ForegroundColor Green
Write-Host ''
Write-Host '  This is PRODUCTION data. Ctrl-C to close.' -ForegroundColor Yellow
Write-Host ''

aws ssm start-session `
    --region $Region `
    --target $InstanceId `
    --document-name AWS-StartPortForwardingSessionToRemoteHost `
    --parameters "host=$RdsHost,portNumber=5432,localPortNumber=$LocalPort"



