# Milestone 02 — Data Pipelines: Change Data Capture (CDC)

| Field | Value |
|---|---|
| **Month** | M1 |
| **Weeks** | W1–W2 |
| **Priority** | P1 — Critical |
| **Domain** | Data Pipelines |
| **Objective** | Implement Change Data Capture (CDC) with PostgreSQL, Debezium, and Kafka/NATS for real-time streaming |
| **Key Deliverable** | Working local CDC pipeline syncing DB writes to consumer |

---

## Why This Matters for FDEs

Client data lives in transactional databases. FDEs are frequently asked to build real-time analytics, sync data to ML pipelines, or replicate data between systems — without modifying the source application. CDC is the industry-standard answer. You need to be able to set this up in a client environment (on-prem or cloud) from scratch.

---

## Core Concepts

### What is CDC?
Change Data Capture captures row-level changes (INSERT, UPDATE, DELETE) from a database's write-ahead log (WAL) and streams them to downstream consumers — without polling or modifying the source schema.

**Three CDC approaches:**
| Approach | How | Pros | Cons |
|----------|-----|------|------|
| Timestamp-based | Poll `updated_at` column | Simple | Misses deletes, requires column |
| Trigger-based | DB triggers write to audit table | DB-native | High write amplification |
| Log-based (WAL) | Read transaction log | Zero source impact, captures deletes | Requires log access |

FDEs use **log-based CDC** via Debezium.

---

## Architecture

```
PostgreSQL (WAL)
      │
      ▼
  Debezium Connector
  (Kafka Connect)
      │
      ▼
   Kafka Topic
   (per-table)
      │
      ├──▶ Consumer A (Analytics DB)
      ├──▶ Consumer B (Search Index)
      └──▶ Consumer C (ML Feature Store)
```

---

## Step-by-Step Setup

### 1. Configure PostgreSQL for Logical Replication

```sql
-- postgresql.conf changes needed:
-- wal_level = logical
-- max_replication_slots = 4
-- max_wal_senders = 4

-- Apply via SQL (requires superuser):
ALTER SYSTEM SET wal_level = 'logical';
ALTER SYSTEM SET max_replication_slots = 4;
SELECT pg_reload_conf();

-- Create a replication slot for Debezium
SELECT pg_create_logical_replication_slot('debezium_slot', 'pgoutput');

-- Create publication for target tables
CREATE PUBLICATION dbz_publication FOR TABLE orders, customers, products;
```

### 2. Docker Compose Setup

```yaml
# docker-compose.yml
version: '3.8'
services:
  postgres:
    image: postgres:15
    environment:
      POSTGRES_DB: shopdb
      POSTGRES_USER: admin
      POSTGRES_PASSWORD: secret
    command: >
      postgres
        -c wal_level=logical
        -c max_replication_slots=4
        -c max_wal_senders=4
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./init.sql:/docker-entrypoint-initdb.d/init.sql

  zookeeper:
    image: confluentinc/cp-zookeeper:7.5.0
    environment:
      ZOOKEEPER_CLIENT_PORT: 2181

  kafka:
    image: confluentinc/cp-kafka:7.5.0
    depends_on: [zookeeper]
    ports:
      - "9092:9092"
    environment:
      KAFKA_BROKER_ID: 1
      KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka:29092,PLAINTEXT_HOST://localhost:9092
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,PLAINTEXT_HOST:PLAINTEXT
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1

  kafka-connect:
    image: debezium/connect:2.4
    depends_on: [kafka, postgres]
    ports:
      - "8083:8083"
    environment:
      BOOTSTRAP_SERVERS: kafka:29092
      GROUP_ID: 1
      CONFIG_STORAGE_TOPIC: connect_configs
      OFFSET_STORAGE_TOPIC: connect_offsets
      STATUS_STORAGE_TOPIC: connect_statuses

volumes:
  pgdata:
```

### 3. Register Debezium PostgreSQL Connector

```bash
curl -X POST http://localhost:8083/connectors \
  -H "Content-Type: application/json" \
  -d '{
    "name": "postgres-cdc-connector",
    "config": {
      "connector.class": "io.debezium.connector.postgresql.PostgresConnector",
      "database.hostname": "postgres",
      "database.port": "5432",
      "database.user": "admin",
      "database.password": "secret",
      "database.dbname": "shopdb",
      "database.server.name": "shopdb",
      "plugin.name": "pgoutput",
      "publication.name": "dbz_publication",
      "slot.name": "debezium_slot",
      "table.include.list": "public.orders,public.customers",
      "topic.prefix": "cdc",
      "transforms": "unwrap",
      "transforms.unwrap.type": "io.debezium.transforms.ExtractNewRecordState",
      "transforms.unwrap.drop.tombstones": "false"
    }
  }'
```

### 4. Consumer (Python)

```python
from confluent_kafka import Consumer, KafkaError
import json

consumer = Consumer({
    'bootstrap.servers': 'localhost:9092',
    'group.id': 'analytics-consumer',
    'auto.offset.reset': 'earliest'
})
consumer.subscribe(['cdc.public.orders', 'cdc.public.customers'])

while True:
    msg = consumer.poll(1.0)
    if msg is None:
        continue
    if msg.error():
        if msg.error().code() == KafkaError._PARTITION_EOF:
            continue
        raise Exception(msg.error())

    event = json.loads(msg.value())
    op = event.get('__op')  # 'c'=create, 'u'=update, 'd'=delete, 'r'=read/snapshot

    if op == 'c':
        print(f"INSERT: {event}")
    elif op == 'u':
        print(f"UPDATE: {event}")
    elif op == 'd':
        print(f"DELETE: order_id={event.get('id')}")
```

### 5. NATS Alternative (JetStream)

```python
import asyncio
import nats

async def main():
    nc = await nats.connect("nats://localhost:4222")
    js = nc.jetstream()

    # Create stream for CDC events
    await js.add_stream(name="CDC", subjects=["cdc.>"])

    async def handler(msg):
        event = json.loads(msg.data)
        print(f"CDC event: {event}")
        await msg.ack()

    await js.subscribe("cdc.orders", cb=handler, durable="analytics")
    await asyncio.sleep(60)
    await nc.drain()

asyncio.run(main())
```

---

## Debezium Event Schema

```json
{
  "before": null,
  "after": {
    "id": 1001,
    "customer_id": 42,
    "amount": 299.99,
    "status": "pending"
  },
  "source": {
    "version": "2.4.0.Final",
    "connector": "postgresql",
    "name": "shopdb",
    "ts_ms": 1710000000000,
    "snapshot": "false",
    "db": "shopdb",
    "schema": "public",
    "table": "orders",
    "txId": 12345,
    "lsn": 98765432,
    "xmin": null
  },
  "op": "c",
  "ts_ms": 1710000001000
}
```

---

## Common FDE Scenarios

| Client Ask | CDC Solution |
|------------|-------------|
| "Sync our Oracle DB to BigQuery in real time" | Debezium Oracle → Kafka → BigQuery connector |
| "Build a search index that updates as orders change" | CDC → Kafka → Elasticsearch connector |
| "Power an ML feature store with live data" | CDC → Kafka → Feast / Tecton feature store |
| "Keep two microservices in sync without coupling them" | CDC as event bus (outbox pattern) |

---

## Checklist

- [ ] Configure PostgreSQL with `wal_level = logical` and create a publication
- [ ] Stand up Kafka + Kafka Connect with Debezium using Docker Compose
- [ ] Register the PostgreSQL connector and verify it appears in `GET /connectors`
- [ ] Trigger INSERTs/UPDATEs in PostgreSQL and verify events appear in Kafka topic
- [ ] Write a Python consumer that handles `c`, `u`, and `d` operations
- [ ] Test delete handling (tombstone messages)
- [ ] Document the end-to-end latency (DB write → Kafka → consumer)

---

## Deliverable

A running local CDC pipeline with:
- `docker-compose.yml` launching PostgreSQL, Zookeeper, Kafka, and Kafka Connect
- `connector-config.json` for the Debezium PostgreSQL connector
- `consumer.py` that prints and routes CDC events
- `README.md` documenting setup steps and measured latency

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Designing Data-Intensive Applications* | Martin Kleppmann | Chapter 11 on stream processing is the best conceptual foundation for CDC; covers Kafka, log-based messaging, and exactly-once semantics |
| *Kafka: The Definitive Guide* | Neha Narkhede, Gwen Shapira, Todd Palino | End-to-end Kafka reference — producers, consumers, Connect, Streams, and operations |
| *Streaming Systems* | Tyler Akidau, Slava Chernyak, Reuven Lax | Deep theory behind event time, watermarks, and windowing in stream processing |
| *Event Streams in Action* | Alexander Dean & Valentin Crettaz | Practical patterns for building event-driven systems with Apache Kafka |
| *The Data Engineering Cookbook* | Andreas Kretz | Practical recipes for building data pipelines including CDC, ETL, and real-time streaming |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Debezium Official Docs | [debezium.io/documentation](https://debezium.io/documentation/) | Primary reference for all Debezium connectors, configuration, and deployment options |
| Confluent Developer Tutorials | [developer.confluent.io](https://developer.confluent.io) | Step-by-step Kafka tutorials, including CDC with Debezium and Kafka Connect |
| Debezium Blog | [debezium.io/blog](https://debezium.io/blog/) | Real-world CDC case studies and deep-dives on connector internals |
| Kafka Documentation | [kafka.apache.org/documentation](https://kafka.apache.org/documentation/) | Official Kafka docs covering configuration, security, and operations |
| NATS JetStream Docs | [docs.nats.io/nats-concepts/jetstream](https://docs.nats.io/nats-concepts/jetstream) | Official JetStream documentation — lightweight Kafka alternative |
| Martin Kleppmann's Blog | [martin.kleppmann.com](https://martin.kleppmann.com) | Deep articles on event sourcing, CDC, and distributed systems |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Apache Kafka Series* | Udemy (Stéphane Maarek) | Kafka fundamentals, Kafka Connect, and real-world stream processing |
| *Designing Data Systems* | DataTalks.Club (free) | Data engineering bootcamp covering CDC, pipelines, and warehousing |
| *Confluent Fundamentals Accreditation* | Confluent (free) | Official Kafka certification preparation |
