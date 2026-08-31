# Milestone 22 — Portfolio Project 2: Real-time Data Integration & Transformation Engine

| Field | Value |
|---|---|
| **Month** | M6 |
| **Weeks** | W21–W22 |
| **Priority** | P1 — Critical |
| **Domain** | Portfolio Project 2 |
| **Objective** | Build Flagship Project 2: Real-time Data Integration & Transformation Engine with CDC & Tracing |
| **Key Deliverable** | GitHub Repo featuring end-to-end architecture & tests |

---

## Why This Matters for FDEs

The FDE interview question "Tell me about a complex data integration you built" needs a real answer. This project is that answer. It covers CDC, stream processing, data transformation, and full observability — the "integration glue" that FDEs build constantly in the field.

---

## Project Overview

**What you're building:** A real-time data integration platform that:
- Captures database changes via CDC (Debezium + Kafka)
- Transforms and routes events to multiple consumers
- Provides a REST API for querying transformed data
- Has full distributed tracing (OpenTelemetry)
- Includes comprehensive tests and CI/CD

**Business use case:** A client wants real-time sync between their OLTP database (PostgreSQL) and:
1. An analytics warehouse (ClickHouse)
2. A search index (Elasticsearch)
3. An ML feature store

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Data Sources                                  │
│  PostgreSQL (orders, customers, products)                            │
└────────────────────────┬────────────────────────────────────────────┘
                         │ WAL (Write-Ahead Log)
                         ▼
┌────────────────────────────────────────────────────────────────────┐
│                    CDC Layer (Debezium)                              │
│  Captures: INSERT / UPDATE / DELETE per table                        │
└────────────────────────┬───────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────────────────┐
│                    Kafka Topics                                      │
│  cdc.orders | cdc.customers | cdc.products                          │
└───────┬──────────────────────┬──────────────────────┬──────────────┘
        │                      │                      │
        ▼                      ▼                      ▼
┌──────────────┐  ┌─────────────────────┐  ┌────────────────────────┐
│  ClickHouse  │  │   Elasticsearch     │  │   Feature Store API    │
│  (Analytics) │  │   (Search Index)    │  │   (ML pipeline)        │
└──────────────┘  └─────────────────────┘  └────────────────────────┘
        │                      │
        └──────────┬───────────┘
                   │
         ┌─────────▼──────────┐
         │   Query API        │
         │   (FastAPI)        │
         │   + OTel traces    │
         └────────────────────┘
```

---

## Repository Structure

```
realtime-data-engine/
├── cdc/
│   ├── docker-compose.yml      # Postgres, Kafka, Debezium
│   ├── connectors/
│   │   ├── postgres-source.json  # Debezium connector config
│   │   └── register.sh           # Script to register connectors
│   └── init.sql                  # Sample schema + test data
│
├── consumers/
│   ├── base.py                 # Abstract consumer with backoff
│   ├── clickhouse_consumer.py  # Orders → ClickHouse
│   ├── elastic_consumer.py     # Products → Elasticsearch
│   └── feature_store_consumer.py  # Customer events → Feature store
│
├── transformers/
│   ├── order_transformer.py    # Enrich CDC event with computed fields
│   ├── customer_transformer.py
│   └── schemas/
│       ├── order_event.py      # Pydantic schemas for CDC events
│       └── customer_event.py
│
├── api/
│   ├── main.py                 # FastAPI query interface
│   ├── routes/
│   │   ├── analytics.py        # Query ClickHouse
│   │   ├── search.py           # Query Elasticsearch
│   │   └── health.py
│   └── telemetry.py            # OTel setup
│
├── tests/
│   ├── unit/
│   │   ├── test_transformers.py
│   │   └── test_consumers.py
│   ├── integration/
│   │   ├── test_cdc_pipeline.py    # End-to-end: insert → consume
│   │   └── test_api_endpoints.py
│   └── conftest.py
│
├── monitoring/
│   ├── grafana/
│   │   └── dashboards/
│   │       └── pipeline-metrics.json
│   └── prometheus.yml
│
├── .github/workflows/ci.yml
├── docker-compose.yml          # Full local stack
└── README.md
```

---

## Key Implementation: Order Transformer

```python
# transformers/order_transformer.py
from pydantic import BaseModel, Field, model_validator
from typing import Optional, Literal
from datetime import datetime
from decimal import Decimal
import hashlib

class CDCEvent(BaseModel):
    op: Literal["c", "u", "d", "r"]  # create, update, delete, read
    before: Optional[dict] = None
    after: Optional[dict] = None
    source_table: str
    ts_ms: int

class OrderEnrichedEvent(BaseModel):
    event_id: str          # Deterministic ID for idempotency
    order_id: int
    customer_id: int
    operation: str
    total_amount: Decimal
    line_item_count: int
    order_date: datetime
    processing_tier: Literal["standard", "priority", "enterprise"]
    is_high_value: bool
    event_timestamp: datetime

    @model_validator(mode='before')
    @classmethod
    def classify_order(cls, values):
        amount = Decimal(str(values.get("total_amount", 0)))
        values["is_high_value"] = amount > Decimal("1000")
        if amount > Decimal("10000"):
            values["processing_tier"] = "enterprise"
        elif amount > Decimal("1000"):
            values["processing_tier"] = "priority"
        else:
            values["processing_tier"] = "standard"
        return values

def transform_order_event(event: CDCEvent) -> Optional[OrderEnrichedEvent]:
    if event.op == "d":
        return None  # Handle deletes separately

    data = event.after or {}
    event_hash = hashlib.md5(
        f"{event.source_table}:{data.get('id')}:{event.ts_ms}".encode()
    ).hexdigest()

    return OrderEnrichedEvent(
        event_id=event_hash,
        order_id=data["id"],
        customer_id=data["customer_id"],
        operation={"c": "created", "u": "updated", "r": "snapshot"}[event.op],
        total_amount=Decimal(str(data.get("total_amount", 0))),
        line_item_count=data.get("line_item_count", 0),
        order_date=datetime.fromisoformat(data["created_at"]),
        event_timestamp=datetime.fromtimestamp(event.ts_ms / 1000),
    )
```

---

## Integration Test: End-to-End CDC

```python
# tests/integration/test_cdc_pipeline.py
import pytest
import psycopg2
import time
from confluent_kafka import Consumer

@pytest.fixture(scope="module")
def db_conn():
    conn = psycopg2.connect("postgresql://admin:secret@localhost:5432/shopdb")
    yield conn
    conn.close()

@pytest.fixture(scope="module")
def kafka_consumer():
    consumer = Consumer({
        "bootstrap.servers": "localhost:9092",
        "group.id": "test-consumer",
        "auto.offset.reset": "latest",
    })
    consumer.subscribe(["cdc.public.orders"])
    yield consumer
    consumer.close()

def test_order_insert_propagates_to_kafka(db_conn, kafka_consumer):
    """Insert an order → verify it appears in Kafka within 5 seconds."""
    cur = db_conn.cursor()
    cur.execute(
        "INSERT INTO orders (customer_id, total_amount, status) VALUES (%s, %s, %s) RETURNING id",
        (42, 299.99, "pending"),
    )
    order_id = cur.fetchone()[0]
    db_conn.commit()

    # Poll Kafka for the event
    deadline = time.time() + 5
    found = False
    while time.time() < deadline:
        msg = kafka_consumer.poll(0.5)
        if msg and not msg.error():
            import json
            event = json.loads(msg.value())
            if event.get("after", {}).get("id") == order_id:
                found = True
                assert event["op"] == "c"
                assert float(event["after"]["total_amount"]) == 299.99
                break

    assert found, f"Order {order_id} not found in Kafka within 5 seconds"
```

---

## Telemetry: Distributed Tracing

```python
# consumers/base.py
from opentelemetry import trace
from opentelemetry.propagate import extract
from opentelemetry.trace import Status, StatusCode
import time

tracer = trace.get_tracer("cdc-consumer")

class BaseConsumer:
    def process_with_tracing(self, topic: str, message: dict):
        with tracer.start_as_current_span(
            f"consume.{topic}",
            attributes={
                "messaging.system": "kafka",
                "messaging.destination": topic,
                "messaging.operation": "process",
            }
        ) as span:
            try:
                t0 = time.perf_counter()
                self.process(message)
                latency = (time.perf_counter() - t0) * 1000

                span.set_attribute("consumer.latency_ms", round(latency, 2))
                span.set_attribute("consumer.event_op", message.get("op", "unknown"))
                span.set_status(Status(StatusCode.OK))

            except Exception as e:
                span.record_exception(e)
                span.set_status(Status(StatusCode.ERROR, str(e)))
                raise
```

---

## Grafana Dashboard Panels

| Panel | Metric |
|-------|--------|
| Events per second per topic | `rate(kafka_events_consumed_total[1m]) by (topic)` |
| Consumer lag | `kafka_consumer_lag by (consumer_group, topic)` |
| Processing latency p95 | `histogram_quantile(0.95, consumer_latency_ms_bucket)` |
| Failed events per hour | `increase(kafka_events_failed_total[1h])` |
| Events delivered to ClickHouse | `increase(clickhouse_inserts_total[5m])` |

---

## README Must-Haves

```markdown
# Real-time Data Integration Engine

## Architecture
[Include the architecture diagram above]

## Quick Start (5 minutes)
```bash
git clone https://github.com/you/realtime-data-engine
cd realtime-data-engine
docker compose up -d
./cdc/connectors/register.sh
# Insert test data
psql postgresql://admin:secret@localhost:5432/shopdb -f cdc/init.sql
# Watch events
docker logs -f consumer-clickhouse
```

## Running Tests
```bash
pip install -e ".[dev]"
pytest tests/unit/ -v
pytest tests/integration/ -v  # requires Docker Compose running
```

## Observability
- Grafana: http://localhost:3000
- Jaeger traces: http://localhost:16686
- Kafka UI: http://localhost:8080
```

---

## Checklist

- [ ] Docker Compose runs all services (Postgres, Kafka, Debezium, ClickHouse, ES)
- [ ] Debezium connector capturing all 3 tables
- [ ] 3 consumers implemented (ClickHouse, ES, feature store)
- [ ] `OrderEnrichedEvent` transformer with business logic
- [ ] Integration test: insert → Kafka event verified < 5s
- [ ] Idempotent consumers (re-processing the same event is safe)
- [ ] OpenTelemetry tracing on all consumer operations
- [ ] Grafana dashboard with lag, latency, throughput panels
- [ ] `README.md` with 5-minute quickstart and architecture diagram
- [ ] CI passing: unit tests + integration tests
- [ ] Repo is public on GitHub with a live demo GIF or recording

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Designing Data-Intensive Applications* | Martin Kleppmann | Definitive guide to CDC, log-based replication, Kafka, and stream processing — read before building this pipeline |
| *Kafka: The Definitive Guide* | Neha Narkhede, Gwen Shapira & Todd Palino | Complete Kafka reference — brokers, consumers, producers, partitioning, and exactly-once delivery |
| *Streaming Systems* | Tyler Akidau et al. | Watermarks, triggers, windowing, and exactly-once semantics — the theory behind Kafka stream processing |
| *The DevOps Handbook* | Gene Kim et al. | CI/CD, observability, and deployment automation for data pipeline infrastructure |
| *Building Event-Driven Microservices* | Adam Bellemare | Event-driven architecture patterns — exactly the paradigm used in CDC → Kafka → consumers |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Debezium Documentation | [debezium.io/documentation](https://debezium.io/documentation/) | Complete CDC reference — PostgreSQL connector configuration, WAL settings, and schema evolution |
| Confluent Developer | [developer.confluent.io](https://developer.confluent.io) | Kafka tutorials, schema registry, Kafka Connect, and stream processing patterns |
| Apache Kafka Documentation | [kafka.apache.org/documentation](https://kafka.apache.org/documentation/) | Official Kafka docs — topic configuration, retention, replication, and consumer group offsets |
| OpenTelemetry Collector | [opentelemetry.io/docs/collector](https://opentelemetry.io/docs/collector/) | Distributed tracing for pipeline instrumentation — add observability across all consumers |
| NATS JetStream Documentation | [docs.nats.io/nats-concepts/jetstream](https://docs.nats.io/nats-concepts/jetstream/) | Lightweight Kafka alternative — useful for demonstrating alternative messaging architectures |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Apache Kafka Series* | Udemy (Stephane Maarek) | Complete Kafka from zero — producers, consumers, connectors, and streams |
| *Event-Driven Microservices with Kafka* | Confluent Training | Kafka Connect, Debezium CDC, and schema registry in production architectures |
| *Data Engineering with Python* | DataCamp | ETL pipelines, streaming data, and building production data workflows |
