# Milestone 06 — Infra as Code: Terraform

| Field | Value |
|---|---|
| **Month** | M2 |
| **Weeks** | W5–W6 |
| **Priority** | P2 — High |
| **Domain** | Infra as Code |
| **Objective** | Write Terraform scripts to provision cloud resources (VPCs, private subnets, IAM policies, RDS) |
| **Key Deliverable** | Declarative IaC repo with modular Terraform templates |

**📅 Day-by-day plan:** [Week 6 Schedule](../weeks/week-06-infra-as-code-terraform.md) (Days 1–7)

---

## Why This Matters for FDEs

FDEs need to spin up client environments quickly and repeatably. Clicking around in the AWS console is not repeatable and doesn't work in client air-gapped environments. Terraform lets you declare infrastructure once, version-control it, and reproduce it across dev/staging/prod with confidence.

---

## Core Concepts

### Terraform Workflow
```
terraform init      # Download providers & modules
terraform plan      # Diff: what will change
terraform apply     # Apply changes
terraform destroy   # Tear everything down
```

### Provider and State Configuration

```hcl
# versions.tf
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
    key            = "prod/terraform.tfstate"
    region         = "us-east-1"
    dynamodb_table = "tf-state-lock"  # Prevent concurrent applies
    encrypt        = true
  }
}

provider "aws" {
  region = var.aws_region
  default_tags {
    tags = {
      Project     = var.project_name
      Environment = var.environment
      ManagedBy   = "terraform"
    }
  }
}
```

---

## Module: VPC with Public/Private Subnets

```hcl
# modules/vpc/main.tf
resource "aws_vpc" "main" {
  cidr_block           = var.vpc_cidr
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = { Name = "${var.name}-vpc" }
}

resource "aws_subnet" "public" {
  count                   = length(var.public_subnet_cidrs)
  vpc_id                  = aws_vpc.main.id
  cidr_block              = var.public_subnet_cidrs[count.index]
  availability_zone       = var.availability_zones[count.index]
  map_public_ip_on_launch = true

  tags = { Name = "${var.name}-public-${count.index + 1}" }
}

resource "aws_subnet" "private" {
  count             = length(var.private_subnet_cidrs)
  vpc_id            = aws_vpc.main.id
  cidr_block        = var.private_subnet_cidrs[count.index]
  availability_zone = var.availability_zones[count.index]

  tags = { Name = "${var.name}-private-${count.index + 1}" }
}

resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id
  tags   = { Name = "${var.name}-igw" }
}

resource "aws_eip" "nat" {
  count  = length(var.public_subnet_cidrs)
  domain = "vpc"
}

resource "aws_nat_gateway" "main" {
  count         = length(var.public_subnet_cidrs)
  allocation_id = aws_eip.nat[count.index].id
  subnet_id     = aws_subnet.public[count.index].id
  depends_on    = [aws_internet_gateway.main]

  tags = { Name = "${var.name}-nat-${count.index + 1}" }
}

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id
  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main.id
  }
}

resource "aws_route_table" "private" {
  count  = length(var.private_subnet_cidrs)
  vpc_id = aws_vpc.main.id
  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.main[count.index].id
  }
}

resource "aws_route_table_association" "public" {
  count          = length(aws_subnet.public)
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

resource "aws_route_table_association" "private" {
  count          = length(aws_subnet.private)
  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private[count.index].id
}
```

---

## Module: RDS PostgreSQL

```hcl
# modules/rds/main.tf
resource "aws_db_subnet_group" "main" {
  name       = "${var.identifier}-subnet-group"
  subnet_ids = var.subnet_ids
}

resource "aws_security_group" "rds" {
  name_prefix = "${var.identifier}-rds-"
  vpc_id      = var.vpc_id

  ingress {
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = var.allowed_security_group_ids
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }
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
  password = var.db_password  # Use secrets manager in prod

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  multi_az               = var.multi_az
  publicly_accessible    = false
  skip_final_snapshot    = var.skip_final_snapshot
  deletion_protection    = var.deletion_protection

  backup_retention_period = 7
  backup_window           = "03:00-04:00"
  maintenance_window      = "sun:04:00-sun:05:00"

  performance_insights_enabled = true

  tags = { Name = var.identifier }
}
```

---

## Module: IAM Roles and Policies

```hcl
# modules/iam/main.tf

# Service role (e.g., for ECS tasks or Lambda)
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

# Least-privilege policy
resource "aws_iam_policy" "app" {
  name = "${var.name}-app-policy"
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "S3ReadWrite"
        Effect = "Allow"
        Action = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
        Resource = ["arn:aws:s3:::${var.s3_bucket_name}/*"]
      },
      {
        Sid    = "SecretsManagerRead"
        Effect = "Allow"
        Action = ["secretsmanager:GetSecretValue"]
        Resource = [var.secret_arn]
      },
      {
        Sid    = "SQSSend"
        Effect = "Allow"
        Action = ["sqs:SendMessage", "sqs:ReceiveMessage", "sqs:DeleteMessage"]
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

---

## Root Module: Compose Everything

```hcl
# environments/staging/main.tf
module "vpc" {
  source = "../../modules/vpc"

  name                = "myapp-staging"
  vpc_cidr            = "10.1.0.0/16"
  public_subnet_cidrs = ["10.1.1.0/24", "10.1.2.0/24"]
  private_subnet_cidrs = ["10.1.10.0/24", "10.1.11.0/24"]
  availability_zones  = ["us-east-1a", "us-east-1b"]
}

module "rds" {
  source = "../../modules/rds"

  identifier    = "myapp-staging-db"
  vpc_id        = module.vpc.vpc_id
  subnet_ids    = module.vpc.private_subnet_ids
  instance_class = "db.t3.medium"
  db_name       = "appdb"
  db_username   = "app"
  db_password   = var.db_password  # from tfvars or secrets
  multi_az      = false
  skip_final_snapshot = true
  deletion_protection = false

  allowed_security_group_ids = [module.app.security_group_id]
}

module "iam" {
  source = "../../modules/iam"

  name            = "myapp-staging"
  trusted_service = "ecs-tasks.amazonaws.com"
  s3_bucket_name  = "myapp-staging-assets"
  secret_arn      = aws_secretsmanager_secret.app.arn
  sqs_queue_arn   = aws_sqs_queue.jobs.arn
}
```

---

## Repository Structure

```
terraform-infra/
├── modules/
│   ├── vpc/
│   │   ├── main.tf
│   │   ├── variables.tf
│   │   └── outputs.tf
│   ├── rds/
│   ├── iam/
│   ├── ecs/
│   └── s3/
├── environments/
│   ├── dev/
│   │   ├── main.tf
│   │   ├── variables.tf
│   │   └── terraform.tfvars
│   ├── staging/
│   └── prod/
├── .github/
│   └── workflows/
│       └── terraform.yml    # Plan on PR, apply on merge
└── README.md
```

---

## Checklist

- [ ] Configure remote S3 backend with DynamoDB locking
- [ ] VPC module with public/private subnets across 2 AZs
- [ ] NAT Gateway for private subnet egress
- [ ] RDS PostgreSQL in private subnet, no public access
- [ ] Security groups with least-privilege rules
- [ ] IAM role with scoped policy (not `*` actions)
- [ ] `terraform plan` shows zero unexpected changes after `apply`
- [ ] Parameterize with `variables.tf` and `terraform.tfvars` (no hardcoded values)
- [ ] Add `outputs.tf` exporting VPC ID, subnet IDs, RDS endpoint

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Terraform: Up & Running* | Yevgeniy Brikman | The best practical Terraform book — covers modules, state management, workspaces, and testing with real AWS examples |
| *Infrastructure as Code* | Kief Morris | Broad principles behind IaC: idempotency, immutability, and change management across any tool |
| *Terraform Cookbook* | Mikael Krief | Problem-solution format covering common Terraform patterns including modules, backends, and CI/CD integration |
| *The Practice of Cloud System Administration* | Thomas A. Limoncelli et al. | Cloud infrastructure design principles applicable across AWS, GCP, and Azure |
| *AWS Well-Architected Framework* | AWS (free PDF) | The official AWS guide to building secure, resilient, and efficient cloud infrastructure |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Terraform Documentation | [developer.hashicorp.com/terraform/docs](https://developer.hashicorp.com/terraform/docs) | Primary reference for providers, resources, functions, and modules |
| Terraform Registry | [registry.terraform.io](https://registry.terraform.io) | Pre-built modules for VPC, EKS, RDS — never write from scratch when a vetted module exists |
| AWS Terraform Provider Docs | [registry.terraform.io/providers/hashicorp/aws](https://registry.terraform.io/providers/hashicorp/aws) | Full reference for every AWS resource available in Terraform |
| Gruntwork Blog | [blog.gruntwork.io](https://blog.gruntwork.io) | Deep Terraform patterns by the authors of Terraform Up & Running |
| Infracost | [infracost.io](https://www.infracost.io) | Show cloud cost estimates in pull requests — plug into CI/CD to catch expensive changes |
| Checkov | [checkov.io](https://www.checkov.io) | Static analysis for Terraform — catches security misconfigurations before apply |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *HashiCorp Certified: Terraform Associate* | HashiCorp / Udemy | Official certification; covers all Terraform fundamentals including state, modules, and workspaces |
| *Terraform for Beginners* | Udemy (KodeKloud) | Hands-on labs covering AWS resource provisioning with Terraform |
| *AWS Solutions Architect Associate* | A Cloud Guru / Udemy | Essential AWS context for understanding what you're provisioning with Terraform |
