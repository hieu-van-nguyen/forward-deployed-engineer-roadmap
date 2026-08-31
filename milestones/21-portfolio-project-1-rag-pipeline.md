# Milestone 21 — Portfolio Project 1: Enterprise Hybrid RAG + Eval Pipeline

| Field | Value |
|---|---|
| **Month** | M6 |
| **Weeks** | W21–W22 |
| **Priority** | P1 — Critical |
| **Domain** | Portfolio Project 1 |
| **Objective** | Build Flagship Project 1: Enterprise Hybrid RAG + Eval Pipeline with complete CI/CD & Terraform |
| **Key Deliverable** | GitHub Repo with live demo, docs, and benchmark report |

---

## Why This Matters for FDEs

This is your primary interview artifact. When a hiring manager asks "show me something you've built," this repo is the answer. It demonstrates every core FDE skill: infrastructure, AI engineering, evaluation, and production readiness. Build it to the standard you'd hand to a Fortune 500 client.

---

## Project Overview

**What you're building:** A production-grade enterprise document Q&A system with:
- Hybrid retrieval (dense + BM25 + reranking)
- Evaluation pipeline with automated quality gates
- Full CI/CD (GitHub Actions)
- Infrastructure as Code (Terraform on AWS)
- Observability (OpenTelemetry + Grafana)

---

## Repository Structure

```
enterprise-rag-system/
├── infra/
│   ├── terraform/
│   │   ├── modules/
│   │   │   ├── vpc/
│   │   │   ├── rds/         # pgvector PostgreSQL
│   │   │   ├── ecs/         # API containers
│   │   │   └── s3/          # Document storage
│   │   └── environments/
│   │       ├── dev/
│   │       └── prod/
│   └── k8s/
│       ├── helm/
│       │   └── rag-system/  # Helm chart
│       └── kind-config.yaml
│
├── backend/
│   ├── api/
│   │   ├── main.py          # FastAPI app
│   │   ├── routes/
│   │   │   ├── query.py
│   │   │   ├── ingest.py
│   │   │   └── health.py
│   │   └── middleware/
│   │       ├── auth.py      # JWT validation
│   │       └── telemetry.py # OTel instrumentation
│   ├── rag/
│   │   ├── pipeline.py      # End-to-end RAG
│   │   ├── retrieval/
│   │   │   ├── dense.py     # Embedding + pgvector
│   │   │   ├── sparse.py    # BM25
│   │   │   └── reranker.py  # Cross-encoder
│   │   └── generation.py    # LLM with Instructor
│   ├── ingest/
│   │   ├── loaders.py       # PDF, DOCX, TXT
│   │   ├── chunker.py       # Recursive text splitting
│   │   └── embedder.py      # Batch embedding
│   └── Dockerfile
│
├── frontend/
│   ├── streamlit/
│   │   └── app.py           # Streamlit UI
│   └── Dockerfile
│
├── eval/
│   ├── golden_dataset.jsonl  # 100+ Q&A pairs
│   ├── run_eval.py           # Ragas evaluation
│   ├── check_thresholds.py   # CI gate
│   └── reports/             # Historical eval results
│
├── .github/
│   └── workflows/
│       ├── ci.yml           # Test + lint + eval
│       ├── deploy-dev.yml   # Deploy to dev on push
│       └── deploy-prod.yml  # Deploy to prod on release
│
├── docs/
│   ├── architecture.md
│   ├── setup.md
│   ├── api-reference.md
│   └── benchmark-report.md
│
├── docker-compose.yml       # Local dev
├── pyproject.toml
└── README.md
```

---

## CI/CD Pipeline

```yaml
# .github/workflows/ci.yml
name: CI — Test, Evaluate, Gate

on:
  push:
    branches: [main, dev]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: pgvector/pgvector:pg15
        env:
          POSTGRES_DB: test_rag
          POSTGRES_USER: test
          POSTGRES_PASSWORD: test
        ports: ["5432:5432"]
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with: {python-version: '3.11'}
      - run: pip install -e ".[dev]"
      - run: pytest tests/ -v --cov=backend --cov-report=xml
      - uses: codecov/codecov-action@v4

  evaluate:
    needs: test
    runs-on: ubuntu-latest
    if: github.event_name == 'push'
    steps:
      - uses: actions/checkout@v4
      - run: pip install ragas openai
      - name: Run eval
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
          DATABASE_URL: ${{ secrets.DEV_DATABASE_URL }}
        run: python eval/run_eval.py --dataset eval/golden_dataset.jsonl
      - name: Quality gate
        run: python eval/check_thresholds.py --results eval/reports/latest.csv
      - name: Upload report
        uses: actions/upload-artifact@v4
        with:
          name: eval-report-${{ github.sha }}
          path: eval/reports/

  deploy-dev:
    needs: evaluate
    runs-on: ubuntu-latest
    if: github.ref == 'refs/heads/dev'
    steps:
      - uses: aws-actions/configure-aws-credentials@v4
        with:
          aws-access-key-id: ${{ secrets.AWS_ACCESS_KEY_ID }}
          aws-secret-access-key: ${{ secrets.AWS_SECRET_ACCESS_KEY }}
          aws-region: us-east-1
      - run: |
          aws ecs update-service \
            --cluster rag-dev \
            --service rag-api \
            --force-new-deployment
```

---

## Terraform: Production Infrastructure

```hcl
# infra/terraform/environments/prod/main.tf
module "vpc" {
  source = "../../modules/vpc"
  name   = "rag-prod"
  vpc_cidr = "10.0.0.0/16"
  public_subnet_cidrs  = ["10.0.1.0/24", "10.0.2.0/24"]
  private_subnet_cidrs = ["10.0.10.0/24", "10.0.11.0/24"]
  availability_zones   = ["us-east-1a", "us-east-1b"]
}

module "rds" {
  source        = "../../modules/rds"
  identifier    = "rag-prod-db"
  vpc_id        = module.vpc.vpc_id
  subnet_ids    = module.vpc.private_subnet_ids
  instance_class = "db.r6g.large"  # Production: read replicas for scaling
  multi_az      = true
  deletion_protection = true
  allowed_security_group_ids = [module.ecs.security_group_id]
}

module "s3" {
  source      = "../../modules/s3"
  bucket_name = "rag-prod-documents"
  versioning  = true
  encryption  = true
}

module "ecs" {
  source      = "../../modules/ecs"
  cluster_name = "rag-prod"
  vpc_id      = module.vpc.vpc_id
  subnet_ids  = module.vpc.private_subnet_ids
  image       = "${data.aws_ecr_repository.api.repository_url}:${var.image_tag}"
  environment_vars = {
    DATABASE_URL = "postgresql://${module.rds.endpoint}/rag"
    S3_BUCKET    = module.s3.bucket_name
  }
  secrets = {
    OPENAI_API_KEY = aws_secretsmanager_secret.openai.arn
  }
  desired_count = 3
  cpu    = 1024
  memory = 2048
}
```

---

## Benchmark Report

Document these metrics in `docs/benchmark-report.md`:

```markdown
# Benchmark Report — Enterprise RAG System

## System Configuration
- Embedding model: BAAI/bge-small-en-v1.5 (384 dims)
- Retrieval: Hybrid (dense + BM25 + cross-encoder reranker)
- LLM: GPT-4o (generation), GPT-4o-mini (eval judge)
- Vector index: pgvector HNSW (m=16, ef_construction=64)
- Dataset: 1,200 enterprise policy documents (4.2M chunks)

## Evaluation Results (N=100 golden Q&A pairs)

| Metric | Score | Threshold | Status |
|--------|-------|-----------|--------|
| Faithfulness | 0.89 | 0.80 | ✅ |
| Answer Relevancy | 0.86 | 0.80 | ✅ |
| Context Precision | 0.78 | 0.70 | ✅ |
| Context Recall | 0.74 | 0.70 | ✅ |
| Answer Correctness | 0.81 | 0.75 | ✅ |

## Retrieval Latency (p50 / p95 / p99)
- Dense only: 42ms / 67ms / 89ms
- Hybrid (no rerank): 95ms / 142ms / 180ms
- Full hybrid + rerank: 210ms / 310ms / 420ms

## Cost Analysis
- Embedding: $0.0001 per document chunk
- Generation: $0.003 per query (avg 1,200 tokens)
- Monthly estimate (1,000 queries/day): ~$90/month

## Hybrid vs. Dense-Only
| Metric | Dense Only | Hybrid | Delta |
|--------|-----------|--------|-------|
| Context Recall | 0.65 | 0.74 | +13.8% |
| Answer Correctness | 0.71 | 0.81 | +14.1% |
| Latency p95 | 67ms | 310ms | +3.6x |
```

---

## Demo Script

```
1. Open the Streamlit UI (or live URL)
2. Ask: "What is the enterprise refund policy for purchases over $10,000?"
   → Shows retrieved chunks + cited answer
3. Ask: "Who approves expense reports above $5,000?"
   → Multi-hop retrieval across policy + org chart docs
4. Ask: "What is the weather in Paris?"
   → Graceful out-of-scope response: "I don't have information about that."
5. Show the Grafana dashboard: live latency, token usage, cost
6. Show GitHub Actions: green eval pipeline
```

---

## Checklist

- [ ] All components running in Docker Compose locally
- [ ] Helm chart deploying to Kind cluster
- [ ] Terraform provisioning AWS VPC + RDS + ECS in dev environment
- [ ] GitHub Actions CI running tests + eval + deploy
- [ ] Eval pipeline passing all thresholds (>0.80 faithfulness)
- [ ] Grafana dashboard showing live metrics
- [ ] 3 demo scenarios scripted and tested
- [ ] `docs/benchmark-report.md` with real numbers
- [ ] `README.md` with setup, architecture diagram, and live demo link
- [ ] Repo is public on GitHub

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *The DevOps Handbook* | Gene Kim et al. | CI/CD pipeline design, deployment automation, and the cultural patterns behind fast, reliable releases |
| *Infrastructure as Code* | Kief Morris | Terraform patterns, idempotency, and managing cloud infrastructure state — directly applicable to AWS ECS + RDS setup |
| *Building Microservices* | Sam Newman | Service decomposition, API design, and deployment patterns for production AI systems |
| *AI Engineering* | Chip Huyen | End-to-end production AI system design — from ingestion to serving with observability and eval |
| *Accelerate* | Nicole Forsgren et al. | DORA metrics for deployment frequency, lead time, and change failure rate — use to benchmark your portfolio CI/CD |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| GitHub Actions Documentation | [docs.github.com/en/actions](https://docs.github.com/en/actions) | Complete CI/CD pipeline reference — workflow triggers, matrix builds, secrets, and artifact management |
| AWS ECS Documentation | [docs.aws.amazon.com/ecs](https://docs.aws.amazon.com/ecs/) | Container orchestration on AWS — Fargate launch type, task definitions, and service auto-scaling |
| AWS CDK / Terraform ECS Examples | [github.com/aws-samples](https://github.com/aws-samples) | Reference architectures for ECS + RDS + S3 on AWS |
| Docker Multi-Stage Build Guide | [docs.docker.com/build/building/multi-stage](https://docs.docker.com/build/building/multi-stage/) | Build lean production images — critical for ECS deployment efficiency |
| Ragas CI/CD Integration | [docs.ragas.io/en/stable/getstarted/rag_evaluation](https://docs.ragas.io/en/stable/getstarted/rag_evaluation/) | Embedding eval gates in CI pipelines — the portfolio's key differentiator |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *MLOps Specialization* | Coursera / DeepLearning.AI | Production ML pipelines, CI/CD for models, and monitoring — directly applicable to RAG pipeline portfolio |
| *AWS Solutions Architect Associate* | AWS Training / A Cloud Guru | ECS, RDS, S3, IAM, and VPC — the exact services used in this portfolio project |
| *GitHub Actions — The Complete Guide* | Udemy | CI/CD pipelines, reusable workflows, and secrets management |
