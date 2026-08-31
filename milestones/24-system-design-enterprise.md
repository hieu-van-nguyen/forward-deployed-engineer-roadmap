# Milestone 24 — System Design: Enterprise Architectures

| Field | Value |
|---|---|
| **Month** | M6 |
| **Weeks** | W23–W24 |
| **Priority** | P2 — High |
| **Domain** | System Design |
| **Objective** | Review Enterprise System Design (Hybrid cloud, data sync, air-gapped deployments, rate limiting) |
| **Key Deliverable** | System design cheat sheet for enterprise architectures |

---

## Why This Matters for FDEs

FDE system design questions aren't "design Twitter." They're "design a data sync pipeline between our on-prem Oracle database and our new cloud ML platform, considering that IT won't give you direct database access." Real constraints, real tradeoffs. This cheat sheet covers the most common enterprise patterns.

---

## Pattern 1: Hybrid Cloud Architecture

**Problem:** Client has some workloads they'll never move to the cloud (compliance, latency), but wants cloud-scale analytics and AI.

```
┌─────────────────────────────────────────────────────────────────┐
│                    ON-PREMISES (Client DC)                        │
│                                                                   │
│  ┌─────────────────┐    ┌──────────────────────────────────────┐ │
│  │  Core Systems   │    │  Edge Processing                      │ │
│  │  - Oracle ERP   │    │  - Local Kafka broker                 │ │
│  │  - MSSQL CRM    │───▶│  - Debezium CDC                      │ │
│  │  - COBOL batch  │    │  - Lightweight ML inference (ONNX)   │ │
│  └─────────────────┘    └──────────────────┬───────────────────┘ │
│                                            │ (sanitized events)   │
└────────────────────────────────────────────┼────────────────────┘
                                             │
                                    Secure tunnel
                                  (VPN / AWS DX)
                                             │
┌────────────────────────────────────────────┼────────────────────┐
│                        AWS (Cloud)         │                      │
│                                            ▼                      │
│  ┌──────────────┐  ┌──────────────────────────────────────────┐  │
│  │  Control     │  │  Data Platform                            │  │
│  │  Plane       │  │  - Kafka MSK (mirror from on-prem)        │  │
│  │  - EKS       │  │  - S3 data lake (Parquet)                 │  │
│  │  - ECS       │  │  - Redshift / BigQuery (analytics)        │  │
│  └──────────────┘  │  - SageMaker (model training)            │  │
│                    └──────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
```

**Key design decisions:**
- Which data can leave the premises? (PII stripping before sync)
- Sync latency: real-time (Kafka MirrorMaker) vs. batch (nightly S3 export)
- Failure mode: what happens when the VPN goes down?

---

## Pattern 2: Data Sync at Scale

**Problem:** Sync 50M+ records from source to destination, with ongoing updates.

### Strategy Decision Tree

```
Is source schema stable?
├── Yes → Can you enable CDC on the source?
│         ├── Yes → Debezium + Kafka (real-time CDC)
│         └── No  → Polling with cursor (updated_at column)
└── No  → Bulk export (daily snapshot) + schema evolution handling
```

### Bulk Load + Incremental Sync Pattern

```python
# sync/bulk_load.py
class DataSyncOrchestrator:
    def __init__(self, source_db, destination_db):
        self.source = source_db
        self.dest = destination_db

    def initial_bulk_load(self, table: str, batch_size: int = 10_000):
        """Load all records in batches. Resumable."""
        last_id = self.get_checkpoint(table)
        total = 0
        while True:
            batch = self.source.query(
                f"SELECT * FROM {table} WHERE id > %s ORDER BY id LIMIT %s",
                (last_id, batch_size)
            )
            if not batch:
                break
            self.dest.bulk_insert(table, batch)
            last_id = batch[-1]["id"]
            self.save_checkpoint(table, last_id)
            total += len(batch)
            print(f"Loaded {total} rows (last_id={last_id})")

    def incremental_sync(self, table: str):
        """Sync changes since last run using updated_at cursor."""
        last_sync = self.get_last_sync_time(table)
        changes = self.source.query(
            f"SELECT * FROM {table} WHERE updated_at > %s ORDER BY updated_at",
            (last_sync,)
        )
        for record in changes:
            self.dest.upsert(table, record, conflict_key="id")
        self.save_last_sync_time(table)
```

### Exactly-Once Delivery
```python
# Use idempotent upserts (ON CONFLICT DO UPDATE in Postgres)
UPSERT_SQL = """
INSERT INTO {table} ({cols})
VALUES ({placeholders})
ON CONFLICT (id) DO UPDATE
SET {updates},
    updated_at = EXCLUDED.updated_at
"""
```

---

## Pattern 3: Air-Gapped Deployment

**Problem:** Client won't allow any internet traffic from production systems.

### Checklist for Air-Gapped Deployments

```
□ All Docker images pulled and transferred via approved media
□ LLM: Ollama + Llama 3 running locally (no API calls)
□ Embeddings: sentence-transformers running locally (no HuggingFace Hub)
□ Vector DB: pgvector or Qdrant (self-hosted, no cloud)
□ Observability: local Prometheus + Grafana (no cloud SaaS)
□ Auth: client's existing AD/LDAP (no Okta/Auth0 cloud)
□ Package registry: internal Artifactory or Nexus (no PyPI)
□ CI/CD: internal GitLab (no GitHub Actions)
```

```bash
# Package all Python dependencies for offline install
pip download -r requirements.txt -d ./wheels/
# Transfer wheels/ to air-gapped machine
pip install --no-index --find-links=./wheels/ -r requirements.txt

# Pull and export Docker images
docker pull ollama/ollama:latest
docker save ollama/ollama:latest | gzip > ollama.tar.gz
# Transfer tar.gz, then:
docker load < ollama.tar.gz
```

---

## Pattern 4: Rate Limiting

**Problem:** Protect your API from abuse; manage LLM API cost under load.

### Token Bucket Algorithm

```python
# rate_limit/token_bucket.py
import time
import threading

class TokenBucket:
    """Thread-safe token bucket rate limiter."""
    def __init__(self, capacity: int, refill_rate: float):
        self.capacity = capacity
        self.tokens = capacity
        self.refill_rate = refill_rate  # tokens per second
        self.last_refill = time.monotonic()
        self._lock = threading.Lock()

    def consume(self, tokens: int = 1) -> bool:
        with self._lock:
            self._refill()
            if self.tokens >= tokens:
                self.tokens -= tokens
                return True
            return False

    def _refill(self):
        now = time.monotonic()
        elapsed = now - self.last_refill
        self.tokens = min(
            self.capacity,
            self.tokens + elapsed * self.refill_rate
        )
        self.last_refill = now

# Distributed rate limiting with Redis
import redis
from datetime import timedelta

class RedisRateLimiter:
    def __init__(self, redis_client: redis.Redis, limit: int, window: timedelta):
        self.redis = redis_client
        self.limit = limit
        self.window = int(window.total_seconds())

    def is_allowed(self, key: str) -> tuple[bool, int]:
        pipe = self.redis.pipeline()
        pipe.incr(key)
        pipe.expire(key, self.window)
        results = pipe.execute()
        count = results[0]
        remaining = max(0, self.limit - count)
        return count <= self.limit, remaining
```

### API Rate Limiting Middleware (FastAPI)

```python
# middleware/rate_limit.py
from fastapi import Request, HTTPException
from starlette.middleware.base import BaseHTTPMiddleware

class RateLimitMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, limiter: RedisRateLimiter, key_func=None):
        super().__init__(app)
        self.limiter = limiter
        self.key_func = key_func or (lambda req: req.client.host)

    async def dispatch(self, request: Request, call_next):
        key = f"ratelimit:{self.key_func(request)}"
        allowed, remaining = self.limiter.is_allowed(key)

        if not allowed:
            raise HTTPException(
                status_code=429,
                detail="Rate limit exceeded",
                headers={"Retry-After": str(self.limiter.window)},
            )

        response = await call_next(request)
        response.headers["X-RateLimit-Remaining"] = str(remaining)
        response.headers["X-RateLimit-Limit"] = str(self.limiter.limit)
        return response
```

---

## Pattern 5: Schema Evolution & Backward Compatibility

```python
# Avro schema evolution rules:
# ✅ BACKWARD compatible: add optional fields with defaults
# ✅ FORWARD compatible: remove fields consumers don't use
# ❌ BREAKING: rename fields, change types, remove required fields

# Apache Avro schema in Kafka
SCHEMA_V1 = {
    "type": "record",
    "name": "Order",
    "fields": [
        {"name": "id", "type": "int"},
        {"name": "amount", "type": "float"},
    ]
}

SCHEMA_V2 = {  # Backward compatible: added optional field
    "type": "record",
    "name": "Order",
    "fields": [
        {"name": "id", "type": "int"},
        {"name": "amount", "type": "float"},
        {"name": "currency", "type": "string", "default": "USD"},  # default required!
    ]
}
```

---

## System Design Cheat Sheet

```
┌─────────────────────────────────────────────────────────────────┐
│                   ENTERPRISE SYSTEM DESIGN                        │
│                        CHEAT SHEET                                │
├─────────────────────┬───────────────────────────────────────────┤
│ REQUIREMENT         │ SOLUTION                                    │
├─────────────────────┼───────────────────────────────────────────┤
│ Real-time DB sync   │ Debezium CDC → Kafka                        │
│ Batch ETL           │ Airflow + dbt                               │
│ Air-gapped LLM      │ Ollama + Llama 3                            │
│ On-prem vector DB   │ pgvector or Qdrant (self-hosted)           │
│ Enterprise SSO      │ SAML 2.0 (Okta/Ping) or OIDC               │
│ Hybrid cloud sync   │ Kafka MirrorMaker or AWS DataSync          │
│ Rate limiting       │ Redis token bucket / sliding window        │
│ Schema evolution    │ Avro + Schema Registry / Pydantic versioning│
│ Idempotent writes   │ ON CONFLICT DO UPDATE (Postgres upsert)    │
│ Distributed tracing │ OpenTelemetry + Jaeger / Grafana Tempo     │
│ Secret management   │ HashiCorp Vault / AWS Secrets Manager      │
│ Config management   │ AWS SSM Parameter Store / K8s ConfigMap    │
│ Service mesh        │ Istio / Linkerd (for mTLS, observability)  │
│ Multi-region DR     │ Active-active with conflict resolution      │
│ Large file transfer │ AWS Transfer Family / presigned S3 URLs    │
├─────────────────────┼───────────────────────────────────────────┤
│ CONSTRAINT          │ WHAT IT FORCES                              │
├─────────────────────┼───────────────────────────────────────────┤
│ No cloud egress     │ Local LLM, self-hosted everything          │
│ HIPAA              │ Audit logs, encryption at rest + transit   │
│ SOC 2 Type II       │ Access controls, change management        │
│ GDPR                │ PII identification, right to deletion      │
│ FedRAMP             │ US-only regions, enhanced auth            │
└─────────────────────┴───────────────────────────────────────────┘
```

---

## Checklist

- [ ] Can explain hybrid cloud architecture tradeoffs in 5 minutes
- [ ] Designed a data sync solution for 50M+ records (bulk + incremental)
- [ ] Know the full air-gapped deployment stack by memory
- [ ] Token bucket rate limiter implemented and tested
- [ ] Avro schema evolution rules memorized (backward vs. forward)
- [ ] System design cheat sheet reviewed and annotated
- [ ] Can answer: "Design X for an air-gapped financial institution" without hesitation
- [ ] System design cheat sheet printed / bookmarked for interview reference

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *System Design Interview Vol. 1 & 2* | Alex Xu | Canonical system design patterns — rate limiters, notification systems, search — adapt each for enterprise AI |
| *Designing Data-Intensive Applications* | Martin Kleppmann | Distributed consistency, replication, CDC, and stream processing — the backbone of hybrid cloud AI architectures |
| *Site Reliability Engineering* | Google SRE Team (free) | Production reliability, SLOs, error budgets, and incident response — applicable to enterprise AI deployments |
| *Enterprise Integration Patterns* | Gregor Hohpe & Bobby Woolf | Message routing, transformation, and orchestration patterns used in enterprise AI data pipelines |
| *Release It!* | Michael T. Nygard | Circuit breakers, timeouts, bulkheads — production resilience patterns for distributed enterprise systems |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Google SRE Book | [sre.google/sre-book/table-of-contents](https://sre.google/sre-book/table-of-contents/) | Free online — SLOs, toil reduction, and production reliability for large-scale systems |
| AWS Architecture Center | [aws.amazon.com/architecture](https://aws.amazon.com/architecture/) | Reference architectures for hybrid cloud, data lake, and AI/ML workloads on AWS |
| Martin Fowler Architecture | [martinfowler.com](https://martinfowler.com) | Microservices, event sourcing, CQRS, and saga patterns — enterprise integration fundamentals |
| Avro Schema Evolution Guide | [avro.apache.org/docs/current/spec](https://avro.apache.org/docs/current/spec.html) | Schema compatibility rules — critical for enterprise Kafka pipelines with schema registry |
| Redis Rate Limiting Patterns | [redis.io/glossary/rate-limiting](https://redis.io/glossary/rate-limiting/) | Distributed rate limiter implementation patterns using Redis — token bucket, sliding window |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Grokking System Design* | Educative.io | 16 enterprise system design patterns with step-by-step walkthroughs and trade-off analysis |
| *AWS Solutions Architect Professional* | AWS Training / A Cloud Guru | Hybrid cloud architecture, enterprise networking, and large-scale AWS system design |
| *Distributed Systems* | MIT OpenCourseWare (free) | Consistency, availability, fault tolerance, and consensus algorithms — theoretical foundation for enterprise design |
