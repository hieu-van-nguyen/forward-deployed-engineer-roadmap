# SWE → Forward Deployed Engineer (FDE): 6-Month Roadmap

> **Duration:** 6 Months (24 Weeks) · **Milestones:** 24 · **Source:** `SWE_to_FDE_6_Month_Roadmap.pdf`

This roadmap provides a prioritized, week-by-week execution plan for a Software Engineer transitioning to a Forward Deployed Engineer (FDE) role. Each milestone links to a detailed guide with code examples, deliverables, and checklists.

---

## 1. Core Paradigm Shift: SWE vs. FDE

Before executing the plan, internalize the fundamental difference in mindset:

| Dimension | Traditional SWE Focus | Forward Deployed Engineer (FDE) Focus |
|-----------|----------------------|---------------------------------------|
| **Environment** | Controlled internal cloud & standardized CI/CD pipelines | Heterogeneous client stacks (On-prem, VPCs, legacy databases, air-gapped systems) |
| **Requirements** | Well-defined specs & JIRA epics from Product Managers | Raw discovery calls with business leaders & highly ambiguous problem statements |
| **Coding Scope** | Core platform feature scalability, abstraction, modularity | Integration glue, real-time data pipelines, enterprise auth, fast MVP builds |
| **Key Success Metric** | Code coverage, system uptime SLA, feature velocity | Customer adoption, Time-to-Value (TTV), client business ROI |

**The core shift:** `Code → Field Value`

---

## 2. Month-by-Month Execution Plan (Sorted by Priority)

Click any milestone to open its detailed guide.

| # | Month | Weeks | Priority | Domain | Action Item & Learning Objective | Key Target Deliverable |
|---|-------|-------|----------|--------|----------------------------------|------------------------|
| 01 | M1 | W1–W2 | 🔴 P1 — Critical | Data Engineering | Master Advanced SQL (Window functions, recursive CTEs, query plan optimization via EXPLAIN ANALYZE) | [Optimized benchmark queries on 10M+ row dataset](./milestones/01-data-engineering-advanced-sql.md) |
| 02 | M1 | W1–W2 | 🔴 P1 — Critical | Data Pipelines | Implement Change Data Capture (CDC) with PostgreSQL, Debezium, and Kafka/NATS for real-time streaming | [Working local CDC pipeline syncing DB writes to consumer](./milestones/02-data-pipelines-cdc.md) |
| 03 | M1 | W3–W4 | 🟠 P2 — High | DB Internals | Study OLTP vs. OLAP data modeling (Star Schema, OBT) & indexing strategies | [Schema design doc comparing OLTP vs OLAP performance](./milestones/03-db-internals-oltp-olap.md) |
| 04 | M1 | W3–W4 | 🟠 P2 — High | Glue Architecture | Build robust error-handling & retry wrappers with backoff for unreliable 3rd-party REST/GraphQL APIs | [Reusable Python/Go resilient HTTP client package](./milestones/04-glue-architecture-resilient-http.md) |
| 05 | M2 | W5–W6 | 🔴 P1 — Critical | Containerization / K8s | Containerize multi-service apps with Docker Compose and migrate to local Kubernetes (Kind/Helm) | [Local Helm chart deploying API, DB, and caching layer](./milestones/05-containerization-k8s-helm.md) |
| 06 | M2 | W5–W6 | 🟠 P2 — High | Infra as Code | Write Terraform scripts to provision cloud resources (VPCs, private subnets, IAM policies, RDS) | [Declarative IaC repo with modular Terraform templates](./milestones/06-infra-as-code-terraform.md) |
| 07 | M2 | W7–W8 | 🟠 P2 — High | Enterprise Security | Implement OAuth2, OIDC, and SAML 2.0 single sign-on flows; set up RBAC permission schemas | [Working Auth service integration with Okta/Keycloak](./milestones/07-enterprise-security-oauth2-oidc-saml.md) |
| 08 | M2 | W7–W8 | 🟡 P3 — Medium | Network Security | Learn enterprise network traversal: reverse proxies (Nginx), TLS termination, and VPC peering | [Architecture diagram & setup guide for secure VPC access](./milestones/08-network-security-vpc-tls.md) |
| 09 | M3 | W9–W10 | 🔴 P1 — Critical | Vector Databases | Master vector indexing (HNSW, IVFFlat) and implement pgvector / Pinecone / Qdrant search engines | [Benchmark report comparing vector search latency & recall](./milestones/09-vector-databases.md) |
| 10 | M3 | W9–W10 | 🔴 P1 — Critical | RAG Systems | Build end-to-end Hybrid RAG (Dense vector search + Sparse BM25 keyword search + Reranking model) | [Hybrid search engine with re-ranking delivering top accuracy](./milestones/10-rag-systems-hybrid-search.md) |
| 11 | M3 | W11–W12 | 🔴 P1 — Critical | Agentic Workflows | Construct stateful multi-agent workflows using LangGraph / AutoGen for tool calling and parsing | [Multi-agent assistant executing SQL queries & API calls](./milestones/11-agentic-workflows-langgraph.md) |
| 12 | M3 | W11–W12 | 🟠 P2 — High | LLM Guardrails | Integrate structured data extraction (Pydantic, Instructor) and safety guardrails (Guardrails AI) | [Production API enforcing strict JSON output schemas](./milestones/12-llm-guardrails-structured-output.md) |
| 13 | M4 | W13–W14 | 🔴 P1 — Critical | LLM Evaluation | Build automated evaluation pipelines (Ragas/DeepEval) measuring Groundedness, Faithfulness, Recall | [Automated test harness scoring LLM responses against golden dataset](./milestones/13-llm-evaluation-ragas-deepeval.md) |
| 14 | M4 | W13–W14 | 🔴 P1 — Critical | Golden Datasets | Curate a 100-sample domain-specific golden evaluation dataset with ground truth annotations | [Structured evaluation benchmark dataset (JSON/CSV)](./milestones/14-golden-datasets.md) |
| 15 | M4 | W15–W16 | 🟠 P2 — High | Telemetry & Tracing | Implement OpenTelemetry / LangSmith / Phoenix for LLM trace logging, token counting, & cost tracking | [Dashboard tracking latency, token usage, and cost per call](./milestones/15-telemetry-tracing-opentelemetry.md) |
| 16 | M4 | W15–W16 | 🟡 P3 — Medium | Fine-Tuning Basics | Learn LoRA/QLoRA fine-tuning concepts for open-source models (Llama 3 / Mistral) for domain tuning | [Fine-tuned small model benchmarked against base model](./milestones/16-fine-tuning-lora-qlora.md) |
| 17 | M5 | W17–W18 | 🔴 P1 — Critical | Problem Scoping | Practice live requirements decomposition: convert vague executive business requests into technical RFCs | [3 comprehensive Technical Requirement & Specs](./milestones/17-problem-scoping-requirements.md) |
| 18 | M5 | W17–W18 | 🟠 P2 — High | Executive Pitching | Master Pyramid Principle communication (Lead with conclusion, group arguments, summarize) | [Executive presentation deck for complex tech integration](./milestones/18-executive-pitching-pyramid-principle.md) |
| 19 | M5 | W19–W20 | 🟠 P2 — High | Rapid Prototyping | Execute 48-hour sprints to build full-stack vertical slices (Streamlit/Next.js + FastAPI) | [2 end-to-end working MVP prototypes built under tight deadlines](./milestones/19-rapid-prototyping-48hr-sprints.md) |
| 20 | M5 | W19–W20 | 🟡 P3 — Medium | Client Objections | Study common enterprise sales/technical objections (Security, Lock-in, On-Prem constraints) | [Playbook on resolving enterprise technical friction points](./milestones/20-client-objections-playbook.md) |
| 21 | M6 | W21–W22 | 🔴 P1 — Critical | Portfolio Project 1 | Build Flagship Project 1: Enterprise Hybrid RAG + Eval Pipeline with complete CI/CD & Terraform | [GitHub Repo with live demo, docs, and benchmark report](./milestones/21-portfolio-project-1-rag-pipeline.md) |
| 22 | M6 | W21–W22 | 🔴 P1 — Critical | Portfolio Project 2 | Build Flagship Project 2: Real-time Data Integration & Transformation Engine with CDC & Tracing | [GitHub Repo featuring end-to-end architecture & tests](./milestones/22-portfolio-project-2-cdc-pipeline.md) |
| 23 | M6 | W23–W24 | 🔴 P1 — Critical | FDE Interview Prep | Practice 5 Live Scoping/Decomposition sessions & 5 Unfamiliar Codebase Live Bug Investigations | [Interview feedback log with documented improvements](./milestones/23-fde-interview-prep.md) |
| 24 | M6 | W23–W24 | 🟠 P2 — High | System Design | Review Enterprise System Design (Hybrid cloud, data sync, air-gapped deployments, rate limiting) | [System design cheat sheet for enterprise architectures](./milestones/24-system-design-enterprise.md) |

---

## 3. Priority Legend

| Symbol | Priority | When to tackle |
|--------|---------|----------------|
| 🔴 P1 — Critical | Foundational; blocks later milestones | Complete in the designated week |
| 🟠 P2 — High | Important; builds on P1 work | Complete in the same month |
| 🟡 P3 — Medium | Valuable; do if time allows | Compress or defer if behind schedule |

---

## 4. Monthly Summary

### Month 1 (W1–W4): Data Engineering Foundation
Build the data skills that underpin every client integration: SQL mastery, CDC streaming, schema design, and resilient HTTP clients.

### Month 2 (W5–W8): Infrastructure & Security
Learn to containerize, deploy on Kubernetes, provision cloud infrastructure with Terraform, and integrate enterprise authentication.

### Month 3 (W9–W12): AI/ML Core — RAG & Agents
Build the AI systems FDEs are hired for: vector databases, hybrid RAG, multi-agent workflows, and structured LLM output.

### Month 4 (W13–W16): Evaluation & Observability
Measure everything: automated LLM eval, golden datasets, telemetry dashboards, and fine-tuning fundamentals.

### Month 5 (W17–W20): Client-Facing Skills
Practice the non-technical skills that close deals: requirements decomposition, executive communication, rapid prototyping, and objection handling.

### Month 6 (W21–W24): Portfolio & Interview Readiness
Build two flagship portfolio projects, practice the FDE interview format, and drill enterprise system design patterns.

---

## 5. Quickstart

1. Read the [Core Paradigm Shift](#1-core-paradigm-shift-swe-vs-fde) section above
2. Open [Milestone 01](./milestones/01-data-engineering-advanced-sql.md) — start your first SQL benchmark
3. Track your progress using the checklist at the bottom of each milestone file
4. Build the deliverables; they become your portfolio

---

## 5a. Weekly Schedules (Day-by-Day Plans)

| Week | Milestone | Daily Plan |
|------|-----------|-----------|
| W1 | [01 — Advanced SQL](./milestones/01-data-engineering-advanced-sql.md) | [Week 1 Schedule](./weeks/week-01-advanced-sql.md) |
| W2 | [02 — CDC Pipelines](./milestones/02-data-pipelines-cdc.md) | [Week 2 Schedule](./weeks/week-02-cdc-pipelines.md) |
| W3 | [03 — DB Internals: OLTP vs OLAP](./milestones/03-db-internals-oltp-olap.md) | [Week 3 Schedule](./weeks/week-03-db-internals-oltp-olap.md) |
| W4 | [04 — Glue Architecture: Resilient HTTP](./milestones/04-glue-architecture-resilient-http.md) | [Week 4 Schedule](./weeks/week-04-glue-architecture-resilient-http.md) |
| W5 | [05 — Containerization/K8s: Docker to Helm](./milestones/05-containerization-k8s-helm.md) | [Week 5 Schedule](./weeks/week-05-containerization-k8s-helm.md) |
| W6 | [06 — Infra as Code: Terraform](./milestones/06-infra-as-code-terraform.md) | [Week 6 Schedule](./weeks/week-06-infra-as-code-terraform.md) |

---

## 6. Stack Reference

| Category | Technologies |
|----------|-------------|
| **Databases** | PostgreSQL + pgvector, ClickHouse, Redis |
| **Streaming** | Kafka, Debezium (CDC), NATS |
| **AI/ML** | OpenAI GPT-4o, Llama 3, Mistral, sentence-transformers |
| **RAG** | pgvector, Qdrant, Pinecone, BM25, cross-encoder rerankers |
| **Agents** | LangGraph, AutoGen, LangChain |
| **Eval** | Ragas, DeepEval, Pytest |
| **Observability** | OpenTelemetry, LangSmith, Arize Phoenix, Grafana, Prometheus |
| **Infrastructure** | Docker, Kubernetes (Kind/Helm), Terraform, AWS |
| **Auth** | OAuth2/OIDC, SAML 2.0, Okta, Keycloak |
| **API** | FastAPI, Pydantic, Instructor |
| **Frontend** | Streamlit, Next.js |

---

*Based on `SWE_to_FDE_6_Month_Roadmap.pdf`*
