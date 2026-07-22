# AWS setup runbook (one-time)

A step-by-step to stand up the hosting + wire GitHub Actions. Do it once. Cost
target ~$55–70/mo (single EC2 + RDS + ElastiCache, single-AZ, Graviton).

> **Model recap:** ONE shared database. Passengers are global; a SACCO and its
> fleet belong to a brand (a `brand` column). ONE app fleet serves both
> `komiut.co.ke` and `2safiri.co.ke` — brand is resolved per request.

---

## 0. What I need from you to finish the automation

Fill these in and hand them back; then I can generate exact commands / Terraform:

| Need | Why | Example |
|---|---|---|
| **AWS account ID** (12 digits) | ARNs in the IAM role/trust | `123456789012` |
| **Region** | everything lives here | `eu-west-1` (Ireland) |
| **Greenfield or existing?** | you're already on AWS (prod DB at `172.31.x.x`) — new VPC, or deploy beside the existing one? | "fresh" / "existing VPC vpc-abc" |
| **GitHub repo for CI** | scopes the OIDC trust | `KomiutSystems/Komiut-Mirror-codebase` |
| **Where DNS is managed** | to point the two domains | Route53 / Namecheap / etc. |
| **Existing RDS to reuse?** | avoid a second DB bill | endpoint or "no, create one" |

---

## 1. Prerequisites
- AWS account with **MFA on root**; create an **IAM admin user** and use that (never root).
- Install the **AWS CLI v2** and `aws configure` with the admin user.
- **Set a budget alert first** (guardrail): Billing → Budgets → $80/mo, email alert at 80%.
- Pick your region and export it: `export AWS_REGION=eu-west-1`.

## 2. ECR repository (holds the app image)
```bash
aws ecr create-repository --repository-name komiut \
  --image-scanning-configuration scanOnPush=true --region "$AWS_REGION"
# note the repositoryUri in the output.
```

## 3. GitHub OIDC + deploy IAM role (no stored AWS keys)
```bash
# 3a. One OIDC provider for GitHub (skip if it already exists).
aws iam create-open-id-connect-provider \
  --url https://token.actions.githubusercontent.com \
  --client-id-list sts.amazonaws.com \
  --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1

# 3b. Trust policy — REPLACE <ACCOUNT_ID> and <ORG/REPO>.
cat > trust.json <<'JSON'
{ "Version":"2012-10-17","Statement":[{
  "Effect":"Allow",
  "Principal":{"Federated":"arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com"},
  "Action":"sts:AssumeRoleWithWebIdentity",
  "Condition":{
    "StringEquals":{"token.actions.githubusercontent.com:aud":"sts.amazonaws.com"},
    "StringLike":{"token.actions.githubusercontent.com:sub":"repo:<ORG/REPO>:*"}
  }}]}
JSON
aws iam create-role --role-name komiut-github-deploy --assume-role-policy-document file://trust.json

# 3c. Permissions: push to ECR + run SSM commands on the EC2.
aws iam attach-role-policy --role-name komiut-github-deploy \
  --policy-arn arn:aws:iam::aws:policy/AmazonEC2ContainerRegistryPowerUser
aws iam attach-role-policy --role-name komiut-github-deploy \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMFullAccess
# note the role ARN -> this is AWS_ROLE_ARN for GitHub.
```

## 4. App secrets in SSM Parameter Store
Store each production value as a SecureString (this is also where you **rotate**
the leaked Firebase key / DB password / JWT secret — never back into git):
```bash
aws ssm put-parameter --name /komiut/prod/APP_KEY --type SecureString --value "base64:..."
aws ssm put-parameter --name /komiut/prod/DB_PASSWORD --type SecureString --value "..."
# ...one per secret. The EC2 renders these into /opt/komiut/.env at deploy.
```

## 5. RDS Postgres (single instance, one database)
Console → RDS → Create database → PostgreSQL 16, **db.t4g.micro**, **Single-AZ**,
**20 GB gp3**, database name `komiut`, **not publicly accessible**, in the same VPC
as the EC2, security group allowing 5432 **only from the EC2's security group**.
Put the endpoint + creds in SSM (step 4).

## 6. ElastiCache Redis
Console → ElastiCache → Redis OSS → **cache.t4g.micro**, 1 node, same VPC, security
group allowing 6379 from the EC2 SG. Endpoint → `REDIS_HOST` in SSM.
*(To save ~$11/mo early on, you can skip this and run a `redis:7-alpine` container
in compose instead — fine for low traffic.)*

## 7. EC2 host
- Launch **t4g.small** (Graviton/ARM), Amazon Linux 2023, in a **public subnet**
  (avoids a ~$32/mo NAT Gateway), 20 GB gp3.
- **Instance role** with: ECR pull (`AmazonEC2ContainerRegistryReadOnly`), SSM
  (`AmazonSSMManagedInstanceCore`), and SSM Parameter read.
- Security group: inbound 80/443 from anywhere, 22 only from your IP (or none — use
  SSM Session Manager instead of SSH).
- Bootstrap:
  ```bash
  sudo dnf install -y docker && sudo systemctl enable --now docker
  sudo curl -SL https://github.com/docker/compose/releases/latest/download/docker-compose-linux-aarch64 \
     -o /usr/libexec/docker/cli-plugins/docker-compose && sudo chmod +x /usr/libexec/docker/cli-plugins/docker-compose
  sudo mkdir -p /opt/komiut && cd /opt/komiut
  # place: docker-compose.prod.yml, Docker/prod/{nginx.conf,deploy.sh}, and a script
  # that renders .env from SSM (aws ssm get-parameters-by-path --path /komiut/prod --with-decryption).
  ```

## 8. DNS + TLS
- Point `komiut.co.ke` and `2safiri.co.ke` (A records) at the EC2's Elastic IP
  (or, if you add an ALB, its DNS name).
- TLS: simplest is **certbot** on the box (`certbot --nginx`) writing to `./certs`,
  or terminate TLS at an **ALB** with free **ACM** certs (then nginx serves plain 80).

## 9. GitHub repo variables (Settings → Secrets and variables → Actions → Variables)
```
AWS_REGION       = eu-west-1
AWS_ROLE_ARN     = arn:aws:iam::<ACCOUNT_ID>:role/komiut-github-deploy
ECR_REPOSITORY   = komiut
EC2_INSTANCE_ID  = i-0abc123...
```

## 10. First deploy
- Merge to `main` → `deploy.yml` runs: test → build+push → SSM triggers `deploy.sh`.
- On the box the first time, seed data and backfill brands:
  ```bash
  docker compose -f docker-compose.prod.yml run --rm --entrypoint "php artisan migrate --force" app
  # assign existing saccos to their brand (see below)
  docker compose -f docker-compose.prod.yml run --rm --entrypoint "php artisan brand:backfill komiut" app
  ```

## 11. Cost + teardown
- Steady state ~$55–70/mo. Buy a **Compute Savings Plan** after ~a month of real
  usage (~40% off) — never before right-sizing.
- To pause costs: `docker compose down` on the EC2, stop the EC2, stop RDS (up to 7
  days). Delete ElastiCache/RDS to zero the DB bill (snapshot first).
