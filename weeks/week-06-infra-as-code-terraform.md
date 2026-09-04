# Week 6 — Infra as Code: Terraform — Day-by-Day Plan

> **Milestone:** [06 — Infra as Code: Terraform](../milestones/06-infra-as-code-terraform.md)
> **Month:** M2 · **Weeks:** W5–W6 (this plan covers W6, Days 1–7)
> **Pacing note:** The milestone spans W5–W6. This document covers W6. W5 is covered by [Milestone 05 — Containerization/K8s: Docker to Helm](../milestones/05-containerization-k8s-helm.md).
> **Deliverable:** A modular Terraform repo (VPC + RDS + IAM) that `terraform validate`s and `plan`s cleanly with zero undeclared references, with remote state and a documented cost/offline strategy.

> **⚠️ Cost warning up front.** The milestone's design provisions 2 NAT Gateways (~$32/mo each) and an RDS instance — real money if applied against a live AWS account. **Day 1 picks a no-spend validation path** (dummy-credentials offline plan, or LocalStack) and every later day builds on that, not on `terraform apply` against a billed account. Only apply for real if you have a sandbox account you're prepared to `terraform destroy` immediately after.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Terraform fundamentals + no-cost validation strategy | Working `terraform validate`/offline `plan` loop |
| 2 | Remote state — the chicken-and-egg bootstrap problem | Local→S3 state migration, done correctly |
| 3 | VPC module — public/private subnets, NAT, routing | `modules/vpc/` fully parameterized, planning cleanly |
| 4 | RDS module — private, encrypted, secret-managed | `modules/rds/` with real security boundaries |
| 5 | IAM module — least privilege, no wildcard actions | `modules/iam/` scoped policies |
| 6 | Root module composition — wiring modules together correctly | `environments/staging/` plans with zero undeclared references |
| 7 | CI plan-on-PR, drift check, and teardown discipline | GitHub Actions workflow + full validation pass |

---

## Day 1 — Terraform Fundamentals + a No-Cost Validation Strategy

**Goal:** Learn the core Terraform workflow, and — critically — set up a way to validate your HCL *without* spending money on real AWS resources.

### The Workflow

```
terraform init      # Download providers & modules
terraform plan       # Diff: what will change
terraform apply      # Apply changes
terraform destroy    # Tear everything down
```

### Why You Can't Just `terraform apply` This Milestone

The milestone's design provisions:
- 2× `aws_nat_gateway` (~$32/mo **each**, plus data processing charges)
- 1× RDS `db.t3.medium` (~$50-60/mo)
- Several `aws_eip` (free while attached, billed if released improperly)

None of that should hit your card while you're *learning* the syntax. Pick one of two no-cost paths:

### Option A — Offline `plan` with Dummy Credentials (Recommended, Zero Setup)

Terraform can `validate` and even `plan` against the real AWS provider without ever calling AWS, using fake credentials plus three provider flags that skip every AWS handshake:

```hcl
# environments/local-test/main.tf — validation-only, never `apply`
provider "aws" {
  region                      = "us-east-1"
  access_key                  = "test"
  secret_key                  = "test"
  skip_credentials_validation = true
  skip_requesting_account_id  = true
  skip_metadata_api_check     = true
}
```

```bash
export AWS_ACCESS_KEY_ID=test
export AWS_SECRET_ACCESS_KEY=test
terraform init
terraform validate
terraform plan     # shows the full resource graph — no AWS calls made
```

**Verify this actually works before building Days 2-7 on top of it** — run it now with a trivial `aws_vpc` resource and confirm `terraform plan` completes without a credentials or network error.

### Option B — LocalStack (Real API Emulation, More Setup)

```bash
docker run -d -p 4566:4566 localstack/localstack
```

```hcl
provider "aws" {
  region                      = "us-east-1"
  access_key                  = "test"
  secret_key                  = "test"
  skip_credentials_validation = true
  skip_requesting_account_id  = true

  endpoints {
    ec2 = "http://localhost:4566"
    iam = "http://localhost:4566"
    s3  = "http://localhost:4566"
    sts = "http://localhost:4566"
    # rds is a Pro-tier LocalStack feature — VPC/IAM/S3 only on the free tier
  }
}
```

> **Know this limitation cold:** free-tier LocalStack does **not** emulate RDS. If you go this route, Day 4's RDS module can be `plan`-validated (HCL syntax) but not `apply`-tested against LocalStack — same constraint as Option A.

### Minimal First Resource

```hcl
resource "aws_vpc" "test" {
  cidr_block = "10.0.0.0/16"
  tags       = { Name = "day1-test" }
}
```

```bash
terraform init
terraform plan
# Plan: 1 to add, 0 to change, 0 to destroy.
```

### Done when
- [ ] Explain `init` / `plan` / `apply` / `destroy` from memory
- [ ] Chosen a no-cost validation path (A or B) and documented which, and why
- [ ] `terraform plan` runs clean against a trivial resource with zero AWS billing risk

---

## Day 2 — Remote State: Solving the Chicken-and-Egg Bootstrap Problem

**Goal:** Understand why the milestone's `backend "s3"` block can't be the *first* thing you run, and do the bootstrap correctly.

### The Problem

```hcl
# versions.tf — this is what the milestone shows as if it "just works"
terraform {
  backend "s3" {
    bucket         = "my-tf-state-bucket"
    key            = "prod/terraform.tfstate"
    region         = "us-east-1"
    dynamodb_table = "tf-state-lock"
    encrypt        = true
  }
}
```

`terraform init` with this backend configured **fails immediately** if `my-tf-state-bucket` and the `tf-state-lock` DynamoDB table don't already exist — Terraform has nowhere to write state, and nothing has created the bucket yet. This is the classic bootstrapping paradox: you need Terraform-managed infrastructure to exist before Terraform can manage state remotely.

### The Fix — Two-Phase Bootstrap

**Phase 1: create the backend infra with a *local* backend**

```hcl
# bootstrap/main.tf — uses default local backend, no backend block at all
resource "aws_s3_bucket" "tf_state" {
  bucket = "my-tf-state-bucket"

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_versioning" "tf_state" {
  bucket = aws_s3_bucket.tf_state.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "tf_state" {
  bucket = aws_s3_bucket.tf_state.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_dynamodb_table" "tf_lock" {
  name         = "tf-state-lock"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "LockID"

  attribute {
    name = "LockID"
    type = "S"
  }
}
```

```bash
cd bootstrap
terraform init      # local backend — no chicken-and-egg here
terraform apply     # creates the bucket + lock table (this is the ONE thing worth a tiny real spend, or run against Option A dry-run only)
```

**Phase 2: migrate your main config to use that backend**

```hcl
# environments/staging/versions.tf — added only AFTER bootstrap exists
terraform {
  required_version = ">= 1.6"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
  backend "s3" {
    bucket         = "my-tf-state-bucket"
    key            = "staging/terraform.tfstate"
    region         = "us-east-1"
    dynamodb_table = "tf-state-lock"
    encrypt        = true
  }
}
```

```bash
cd environments/staging
terraform init -migrate-state   # prompts to copy any existing local state into S3
```

### Done when
- [ ] Can explain why `backend "s3"` as the *first* config fails
- [ ] Bootstrap module created (S3 bucket + DynamoDB lock table) using a local backend
- [ ] `terraform init -migrate-state` used to move to the remote backend
- [ ] Understand `prevent_destroy` and why it belongs on the state bucket

---

## Day 3 — VPC Module: Public/Private Subnets, NAT, Routing

**Goal:** Build the VPC module with correct, fully-parameterized variables — and fix the milestone's NAT indexing bug, which breaks the moment subnet counts differ.

### The Bug in the Milestone's NAT Gateway Indexing

```hcl
# milestones/06 version — breaks if private subnets != public subnets
resource "aws_route_table" "private" {
  count  = length(var.private_subnet_cidrs)
  vpc_id = aws_vpc.main.id
  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.main[count.index].id  # ← indexes into the NAT array by private-subnet count
  }
}
```

`aws_nat_gateway.main` has one entry **per public subnet** (`count = length(var.public_subnet_cidrs)`). If you have 3 private subnets but only 2 public subnets (a common real design — fewer NAT gateways than private AZs to save cost), `aws_nat_gateway.main[2]` doesn't exist and `plan` fails with an index-out-of-range error.

### The Fix — Clamp the Index, or Use One-NAT-Per-AZ Explicitly

```hcl
# modules/vpc/main.tf
resource "aws_nat_gateway" "main" {
  count         = length(var.public_subnet_cidrs)
  allocation_id = aws_eip.nat[count.index].id
  subnet_id     = aws_subnet.public[count.index].id
  depends_on    = [aws_internet_gateway.main]

  tags = { Name = "${var.name}-nat-${count.index + 1}" }
}

resource "aws_route_table" "private" {
  count  = length(var.private_subnet_cidrs)
  vpc_id = aws_vpc.main.id
  route {
    cidr_block = "0.0.0.0/0"
    # clamp to the smaller NAT count — multiple private subnets can share one NAT
    nat_gateway_id = aws_nat_gateway.main[min(count.index, length(aws_nat_gateway.main) - 1)].id
  }
}
```

### `modules/vpc/variables.tf` — Missing From the Milestone, Required for `plan` to Work at All

```hcl
variable "name" {
  type        = string
  description = "Prefix applied to all resource names"
}

variable "vpc_cidr" {
  type = string
}

variable "public_subnet_cidrs" {
  type = list(string)
}

variable "private_subnet_cidrs" {
  type = list(string)
}

variable "availability_zones" {
  type = list(string)
}
```

### `modules/vpc/outputs.tf` — Also Missing, Required by the Root Module Later

```hcl
output "vpc_id" {
  value = aws_vpc.main.id
}

output "public_subnet_ids" {
  value = aws_subnet.public[*].id
}

output "private_subnet_ids" {
  value = aws_subnet.private[*].id
}
```

### Validate Against Day 1's No-Cost Setup

```bash
cd modules/vpc
terraform init
terraform validate
terraform plan -var="name=test" -var="vpc_cidr=10.0.0.0/16" \
  -var='public_subnet_cidrs=["10.0.1.0/24","10.0.2.0/24"]' \
  -var='private_subnet_cidrs=["10.0.10.0/24","10.0.11.0/24","10.0.12.0/24"]' \
  -var='availability_zones=["us-east-1a","us-east-1b","us-east-1c"]'
```

### Done when
- [ ] Understand exactly why the original NAT indexing breaks with mismatched subnet counts
- [ ] `variables.tf` and `outputs.tf` written for the VPC module
- [ ] `terraform plan` with 3 private / 2 public subnets succeeds (proves the clamp fix works)
- [ ] Can explain the cost trade-off of 1 NAT-per-AZ vs. shared NAT

---

## Day 4 — RDS Module: Private, Encrypted, Secret-Managed

**Goal:** Fix the plaintext-password problem in the milestone's RDS module — `var.db_password` lands directly in Terraform state as plaintext, which is a real security finding in any client engagement.

### The Problem

```hcl
# milestones/06 version
resource "aws_db_instance" "main" {
  ...
  username = var.db_username
  password = var.db_password  # Use secrets manager in prod  ← comment admits the gap, doesn't fix it
  ...
}
```

Terraform state files are **plaintext by default**. Anyone with read access to the state file (S3 bucket, CI logs if `TF_LOG` is on, a teammate with `terraform show`) sees the raw password. This is exactly the kind of gap a client security review will flag on day one of a real engagement.

### The Fix — AWS-Managed Master Password (No Plaintext, Ever)

```hcl
# modules/rds/main.tf
resource "aws_db_subnet_group" "main" {
  name       = "${var.identifier}-subnet-group"
  subnet_ids = var.subnet_ids
}

# Standalone security group rules avoid a resource-level circular reference
# between the app's SG and the RDS SG (see the note below)
resource "aws_security_group" "rds" {
  name_prefix = "${var.identifier}-rds-"
  vpc_id      = var.vpc_id
}

resource "aws_vpc_security_group_ingress_rule" "rds_from_app" {
  security_group_id            = aws_security_group.rds.id
  referenced_security_group_id = var.app_security_group_id
  from_port                    = 5432
  to_port                      = 5432
  ip_protocol                  = "tcp"
}

resource "aws_vpc_security_group_egress_rule" "rds_all" {
  security_group_id = aws_security_group.rds.id
  cidr_ipv4          = "0.0.0.0/0"
  ip_protocol        = "-1"
}

resource "aws_db_instance" "main" {
  identifier        = var.identifier
  engine            = "postgres"
  engine_version    = "15.4"
  instance_class    = var.instance_class
  allocated_storage = var.allocated_storage
  storage_encrypted = true

  db_name  = var.db_name
  username = var.db_username
  # AWS generates and stores the password in Secrets Manager directly —
  # it never appears in your tfvars, HCL, or (in plaintext) in state
  manage_master_user_password = true

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  multi_az               = var.multi_az
  publicly_accessible    = false
  skip_final_snapshot    = var.skip_final_snapshot
  deletion_protection    = var.deletion_protection

  backup_retention_period = 7
  backup_window           = "03:00-04:00"
  maintenance_window      = "sun:04:00-sun:05:00"

  # NOTE: Performance Insights requires instance classes larger than db.t3.micro/small.
  # If you downsize instance_class for cost reasons, set this false or plan will fail
  # with an unsupported-configuration error.
  performance_insights_enabled = var.instance_class != "db.t3.micro"

  tags = { Name = var.identifier }
}
```

### Why the Standalone Ingress/Egress Rules Matter

The milestone's inline `ingress { security_groups = var.allowed_security_group_ids }` block, combined with an app module that references `module.rds.security_group_id` right back, creates a circular dependency Terraform can't resolve in a single apply. `aws_vpc_security_group_ingress_rule` as a standalone resource breaks the cycle — the RDS SG and the app SG can each be created without waiting on the other's inline rule block.

### `.gitignore` — Never Commit These

```gitignore
*.tfstate
*.tfstate.*
*.tfvars
.terraform/
.terraform.lock.hcl
```

> `*.tfvars` is excluded because real environments put `db_username`, account IDs, and other environment-specific values there — even without a plaintext password, these shouldn't be in git history.

### Done when
- [ ] Explain why `password = var.db_password` is a real finding, not a style nit
- [ ] `manage_master_user_password = true` used instead
- [ ] Standalone `aws_vpc_security_group_ingress_rule`/`egress_rule` used, not inline blocks
- [ ] `.gitignore` committed before any `terraform.tfvars` file is created

---

## Day 5 — IAM Module: Least Privilege, No Wildcards

**Goal:** Build the IAM module as-designed in the milestone (it's mostly correct), and stress-test it by trying to justify every single permission.

### `modules/iam/main.tf` (as designed, annotated)

```hcl
resource "aws_iam_role" "service" {
  name = "${var.name}-service-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = var.trusted_service }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_policy" "app" {
  name = "${var.name}-app-policy"
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "S3ReadWrite"
        Effect   = "Allow"
        Action   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
        Resource = ["arn:aws:s3:::${var.s3_bucket_name}/*"]
      },
      {
        Sid      = "SecretsManagerRead"
        Effect   = "Allow"
        Action   = ["secretsmanager:GetSecretValue"]
        Resource = [var.secret_arn]
      },
      {
        Sid      = "SQSSend"
        Effect   = "Allow"
        Action   = ["sqs:SendMessage", "sqs:ReceiveMessage", "sqs:DeleteMessage"]
        Resource = [var.sqs_queue_arn]
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "app" {
  role       = aws_iam_role.service.name
  policy_arn = aws_iam_policy.app.arn
}
```

```hcl
# modules/iam/variables.tf
variable "name" {
  type = string
}
variable "trusted_service" {
  type = string
}
variable "s3_bucket_name" {
  type = string
}
variable "secret_arn" {
  type = string
}
variable "sqs_queue_arn" {
  type = string
}
```

```hcl
# modules/iam/outputs.tf
output "role_arn" {
  value = aws_iam_role.service.arn
}
output "role_name" {
  value = aws_iam_role.service.name
}
```

### The Least-Privilege Audit

For every `Statement` block, ask: *does this action list have exactly what's needed, and no more?* Run this checklist against the policy above:

| Statement | Does it use `*` for Action? | Does it use `*` for Resource? | Verdict |
|-----------|:---:|:---:|---|
| S3ReadWrite | No — 3 named actions | No — scoped to one bucket ARN | ✅ Passes |
| SecretsManagerRead | No — read-only | No — one secret ARN | ✅ Passes |
| SQSSend | No — 3 named actions | No — one queue ARN | ✅ Passes |

```bash
cd modules/iam
terraform init && terraform validate
terraform plan -var="name=test" -var="trusted_service=ecs-tasks.amazonaws.com" \
  -var="s3_bucket_name=test-bucket" \
  -var="secret_arn=arn:aws:secretsmanager:us-east-1:123456789012:secret:test" \
  -var="sqs_queue_arn=arn:aws:sqs:us-east-1:123456789012:test-queue"
```

### Done when
- [ ] `variables.tf`/`outputs.tf` written for the IAM module
- [ ] Every `Statement` in the policy audited against the least-privilege table above
- [ ] `terraform plan` succeeds with dummy ARNs
- [ ] Can explain why `Action = "*"` or `Resource = "*"` would fail a client security review

---

## Day 6 — Root Module Composition: Wiring Modules Together Correctly

**Goal:** Fix the milestone's root module, which references three things that don't exist anywhere in the repo (`module.app`, `aws_secretsmanager_secret.app`, `aws_sqs_queue.jobs`) — a broken composition is the single biggest blocker to a real `terraform plan`.

### The Problem

```hcl
# milestones/06 version — references undeclared resources
module "rds" {
  source = "../../modules/rds"
  ...
  allowed_security_group_ids = [module.app.security_group_id]  # ← no "app" module exists anywhere
}

module "iam" {
  source = "../../modules/iam"
  ...
  secret_arn    = aws_secretsmanager_secret.app.arn   # ← never declared
  sqs_queue_arn = aws_sqs_queue.jobs.arn              # ← never declared
}
```

`terraform plan` on this fails immediately with `Error: Reference to undeclared resource`. Either declare every dependency in the same root module, or remove the reference and pass the value in as a variable. For this exercise, declare them — that's the realistic pattern for a root module that owns the full environment.

### The Fix — `environments/staging/main.tf`

```hcl
module "vpc" {
  source = "../../modules/vpc"

  name                  = "myapp-staging"
  vpc_cidr              = "10.1.0.0/16"
  public_subnet_cidrs   = ["10.1.1.0/24", "10.1.2.0/24"]
  private_subnet_cidrs  = ["10.1.10.0/24", "10.1.11.0/24"]
  availability_zones    = ["us-east-1a", "us-east-1b"]
}

# Minimal app security group — stands in for an ECS/EC2 app tier
resource "aws_security_group" "app" {
  name_prefix = "myapp-staging-app-"
  vpc_id      = module.vpc.vpc_id
}

resource "aws_secretsmanager_secret" "app" {
  name = "myapp-staging-app-secret"
}

resource "aws_sqs_queue" "jobs" {
  name = "myapp-staging-jobs"
}

resource "aws_s3_bucket" "assets" {
  bucket = "myapp-staging-assets"
}

module "rds" {
  source = "../../modules/rds"

  identifier            = "myapp-staging-db"
  vpc_id                = module.vpc.vpc_id
  subnet_ids            = module.vpc.private_subnet_ids
  instance_class        = "db.t3.micro"   # downsized for cost; Performance Insights auto-disabled by the module's conditional
  db_name               = "appdb"
  db_username           = "app"
  multi_az              = false
  skip_final_snapshot   = true
  deletion_protection   = false
  app_security_group_id = aws_security_group.app.id
}

module "iam" {
  source = "../../modules/iam"

  name            = "myapp-staging"
  trusted_service = "ecs-tasks.amazonaws.com"
  s3_bucket_name  = aws_s3_bucket.assets.bucket
  secret_arn      = aws_secretsmanager_secret.app.arn
  sqs_queue_arn   = aws_sqs_queue.jobs.arn
}
```

### `environments/staging/outputs.tf` — Required by the Checklist, Missing From the Milestone

```hcl
output "vpc_id" {
  value = module.vpc.vpc_id
}

output "private_subnet_ids" {
  value = module.vpc.private_subnet_ids
}

output "rds_endpoint" {
  value     = module.rds.db_instance_endpoint
  sensitive = true
}

output "iam_role_arn" {
  value = module.iam.role_arn
}
```

> Add a matching `output "db_instance_endpoint"` to `modules/rds/outputs.tf` (pointing at `aws_db_instance.main.endpoint`) — it's referenced above but not yet created in this plan.

### Validate the Full Composition

```bash
cd environments/staging
terraform init
terraform validate
terraform plan   # should now complete with ZERO "undeclared resource" errors
```

### Done when
- [ ] Every `module.X.field` and top-level resource reference in the root module resolves to something actually declared
- [ ] `terraform plan` on the full composition completes with no undeclared-reference errors
- [ ] `outputs.tf` exports VPC ID, subnet IDs, RDS endpoint (marked `sensitive`), and IAM role ARN
- [ ] Understand why declaring dependencies in the root module (vs. assuming them) is the correct pattern here

---

## Day 7 — CI Plan-on-PR, Drift Detection, and Teardown Discipline

**Goal:** Wire Terraform into CI the way a real client repo would — plan visible on every PR, apply gated to merge — and practice the full destroy cycle so nothing lingers and bills you.

### `.github/workflows/terraform.yml`

```yaml
name: Terraform
on:
  pull_request:
    paths: ["environments/**", "modules/**"]
  push:
    branches: [main]
    paths: ["environments/**", "modules/**"]

jobs:
  plan:
    if: github.event_name == 'pull_request'
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: environments/staging
    steps:
      - uses: actions/checkout@v4
      - uses: hashicorp/setup-terraform@v3
        with:
          terraform_version: "1.6.6"
      - run: terraform init
      - run: terraform validate
      - run: terraform plan -no-color
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.AWS_ACCESS_KEY_ID }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.AWS_SECRET_ACCESS_KEY }}

  apply:
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: environments/staging
    steps:
      - uses: actions/checkout@v4
      - uses: hashicorp/setup-terraform@v3
        with:
          terraform_version: "1.6.6"
      - run: terraform init
      - run: terraform apply -auto-approve
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.AWS_ACCESS_KEY_ID }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
```

> For this exercise, don't wire real AWS credentials into GitHub Secrets unless you have a disposable sandbox account. Validate the workflow structure and the `plan` step logic; treat the `apply` job as documentation of the pattern, not something you'll trigger against a live account today.

### Drift Detection

```bash
# Run on a schedule (e.g., nightly cron) to catch manual console changes
terraform plan -detailed-exitcode
# exit code 0 = no changes (no drift)
# exit code 2 = changes detected (drift!) — alert the team
# exit code 1 = error
```

### Static Analysis Before Any Real Apply

```bash
# Checkov — catches security misconfigurations (e.g., publicly_accessible RDS, open SGs)
pip install checkov
checkov -d environments/staging

# Infracost — cost estimate diff, so a PR reviewer sees "+$64/mo" before approving
infracost breakdown --path environments/staging
```

### Full Teardown Discipline (If You Did Apply Anything for Real)

```bash
terraform plan -destroy    # ALWAYS review before destroying
terraform destroy
# then confirm nothing lingers:
aws ec2 describe-nat-gateways --filter "Name=state,Values=available"
aws rds describe-db-instances
aws ec2 describe-addresses    # unattached EIPs still bill
```

### Final Repo Structure Check

```
terraform-infra/
├── bootstrap/                  # Day 2 — local backend, creates S3 + DynamoDB
├── modules/
│   ├── vpc/     (main.tf, variables.tf, outputs.tf)
│   ├── rds/     (main.tf, variables.tf, outputs.tf)
│   └── iam/     (main.tf, variables.tf, outputs.tf)
├── environments/
│   └── staging/ (main.tf, versions.tf, variables.tf, outputs.tf, terraform.tfvars — gitignored)
├── .github/workflows/terraform.yml
└── .gitignore
```

### Deliverable Checklist Review

- [ ] Remote S3 backend with DynamoDB locking, bootstrapped via the two-phase pattern (not chicken-and-egg)
- [ ] VPC module with public/private subnets across 2+ AZs, NAT indexing fixed for mismatched subnet counts
- [ ] RDS PostgreSQL in private subnet, no public access, `manage_master_user_password = true` (no plaintext password in state)
- [ ] Security groups using standalone ingress/egress rule resources (no circular reference)
- [ ] IAM role with scoped policy audited against the least-privilege table
- [ ] `terraform plan` shows zero undeclared-reference errors on the full root module
- [ ] Every module has `variables.tf` and `outputs.tf` — no undeclared `var.*`
- [ ] Root module `outputs.tf` exports VPC ID, subnet IDs, RDS endpoint (sensitive), IAM role ARN
- [ ] CI workflow: plan visible on PR, apply gated to merge
- [ ] Checkov run with zero HIGH/CRITICAL findings on the final config

### Self-Debrief Questions

1. A client's AWS account has an existing VPC created by hand in the console years ago, and you need to bring it under Terraform management without recreating it. What command do you reach for first?
2. Your `terraform plan` in CI shows a change to a resource nobody touched. What are the top 2 causes, and how do you tell them apart?
3. `manage_master_user_password = true` moves the password out of your state file — but where does it actually live now, and how would your application read it at runtime?

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Terraform — AWS Provider: skip_* arguments](https://registry.terraform.io/providers/hashicorp/aws/latest/docs#authentication) |
| 2 | [Terraform — S3 Backend & State Migration](https://developer.hashicorp.com/terraform/language/backend/s3) |
| 3 | [Terraform Registry — VPC Module Reference](https://registry.terraform.io/modules/terraform-aws-modules/vpc/aws/latest) |
| 4 | [AWS RDS — Password Management in Secrets Manager](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/rds-secrets-manager.html) |
| 5 | [AWS IAM — Least Privilege Best Practices](https://docs.aws.amazon.com/IAM/latest/UserGuide/best-practices.html) |
| 6 | [Terraform — Module Composition](https://developer.hashicorp.com/terraform/language/modules) |
| 7 | [Checkov](https://www.checkov.io) · [Infracost](https://www.infracost.io) |

---

*→ Next: [Milestone 07 — Enterprise Security: OAuth2/OIDC/SAML](../milestones/07-enterprise-security-oauth2-oidc-saml.md)*
