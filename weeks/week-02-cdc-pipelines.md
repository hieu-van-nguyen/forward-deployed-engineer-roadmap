# Week 2 — CDC Pipelines: Day-by-Day Plan

> **Milestone:** [02 — Data Pipelines: Change Data Capture (CDC)](../milestones/02-data-pipelines-cdc.md)
> **Month:** M1 · **Weeks:** W1–W2 (this plan covers W2, Days 1–7)
> **Pacing note:** The milestone header says W1–W2. This document covers W2. W1 is covered by [Week 1 — Advanced SQL](./week-01-advanced-sql.md).
> **Environment note:** This week uses a dedicated `shopdb` Docker environment (PostgreSQL + Kafka). It is separate from the local `orders_bench` database from Week 1 — do not conflate them.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | CDC theory — WAL, replication slots, why Debezium | Notes on CDC approaches, architecture diagram understood |
| 2 | Docker Compose — all 4 services running | `docker-compose.yml` with PostgreSQL, ZooKeeper, Kafka, Kafka Connect |
| 3 | PostgreSQL logical replication — WAL config, publications | `init.sql`, replication slot created, publication active |
| 4 | Register Debezium connector + verify events flow | Connector registered, INSERTs visible in Kafka topic |
| 5 | Python consumer — handle `c`, `u`, `d` operations | `consumer.py` printing all event types |
| 6 | Delete / tombstone handling + latency measurement | Delete flow working, end-to-end latency documented |
| 7 | Consolidation — deliverable assembled, checklist done | `cdc-pipeline/` repo committed, `README.md` with measured latency |

---

## Day 1 — CDC Theory & Architecture

**Goal:** Understand *why* CDC exists, the three approaches, and how the PostgreSQL WAL → Debezium → Kafka stack works before touching any code.

### Three CDC Approaches

| Approach | Mechanism | Use when |
|----------|-----------|----------|
| **Timestamp-based** | Poll `updated_at` column | Quick prototype; never in production (misses DELETEs, requires schema column) |
| **Trigger-based** | DB triggers write to audit table | DB-native but causes write amplification — avoid on high-throughput tables |
| **Log-based (WAL)** | Read PostgreSQL Write-Ahead Log | Production standard — zero source-app impact, captures all operation types |

FDEs always reach for **log-based CDC** via Debezium. The other two are useful only when WAL access is denied (rare SaaS databases).

### How PostgreSQL WAL CDC Works

```
1. App writes a row → PostgreSQL commits to WAL first (durability guarantee)
2. Debezium reads the WAL via a logical replication slot (pgoutput plugin)
3. Debezium decodes WAL entries into structured JSON change events
4. Events are published to a Kafka topic per table (e.g., cdc.public.orders)
5. Consumers read from the Kafka topic independently and at their own pace
```

**Key terms to internalize:**

| Term | What it is |
|------|-----------|
| **WAL** | Write-Ahead Log — PostgreSQL's durability journal; every change is written here before being applied |
| **Logical replication** | A PostgreSQL mode that exposes WAL entries as row-level logical changes (vs physical byte-level) |
| **Replication slot** | A named cursor that tracks how far Debezium has read the WAL — prevents PostgreSQL from deleting unread WAL segments |
| **Publication** | A PostgreSQL object declaring which tables Debezium is allowed to stream |
| **pgoutput** | The built-in PostgreSQL logical decoding plugin (no extra install needed; used by Debezium) |
| **Tombstone message** | A Kafka message with a `null` value, emitted after a DELETE event — signals consumers to remove the key |

### Architecture to Draw (by hand or in a doc)

```
┌─────────────────────────────────────┐
│  PostgreSQL 15 (WAL level: logical) │
│  ┌──────────────────────────────┐   │
│  │  Publication: dbz_publication│   │
│  │  Tables: orders, customers   │   │
│  └──────────────────────────────┘   │
│  Replication slot: debezium_slot    │
└────────────────┬────────────────────┘
                 │ pgoutput (logical replication protocol)
                 ▼
┌────────────────────────────────────┐
│       Debezium (Kafka Connect)     │
│  PostgresConnector reads WAL,      │
│  decodes to JSON events            │
│  ExtractNewRecordState → flatten   │
└────────────────┬───────────────────┘
                 │ JSON events
                 ▼
┌────────────────────────────────────┐
│              Kafka                 │
│  Topic: cdc.public.orders          │
│  Topic: cdc.public.customers       │
└──────┬─────────────────────────────┘
       │
       ├──▶ Consumer A: analytics DB writer
       ├──▶ Consumer B: search index updater
       └──▶ Consumer C: ML feature store
```

### Study Tasks

- Read: [Debezium PostgreSQL Connector docs](https://debezium.io/documentation/reference/stable/connectors/postgresql.html) — focus on "How the connector works" and "Logical decoding" sections
- Read: *Designing Data-Intensive Applications* Chapter 11 (if available) — or the summary at [martin.kleppmann.com](https://martin.kleppmann.com)
- Answer in writing (your notes):
  1. Why does a replication slot prevent WAL from being deleted?
  2. What happens if the Debezium consumer goes offline for 2 days — will it lose data?
  3. Why does Debezium emit a tombstone on DELETE (not just the DELETE event)?

### Done when
- [ ] Can explain WAL → Debezium → Kafka in your own words
- [ ] Know the difference between a replication slot and a publication
- [ ] Architecture diagram drawn or documented

---

## Day 2 — Docker Compose: All 4 Services Running

**Goal:** Stand up the full local stack — PostgreSQL (WAL-enabled), ZooKeeper, Kafka, and Kafka Connect — using Docker Compose.

### Project Structure to Create

```
cdc-pipeline/
├── docker-compose.yml
├── init.sql
├── connector-config.json
├── consumer.py
└── README.md
```

### `docker-compose.yml`

```yaml
version: '3.8'

services:
  postgres:
    image: postgres:15
    environment:
      POSTGRES_DB: shopdb
      POSTGRES_USER: admin
      POSTGRES_PASSWORD: secret
    # WAL level set via command flags — no need to edit postgresql.conf
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
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U admin -d shopdb"]
      interval: 10s
      timeout: 5s
      retries: 5

  zookeeper:
    image: confluentinc/cp-zookeeper:7.5.0
    environment:
      ZOOKEEPER_CLIENT_PORT: 2181
      ZOOKEEPER_TICK_TIME: 2000

  kafka:
    image: confluentinc/cp-kafka:7.5.0
    depends_on:
      - zookeeper
    ports:
      - "9092:9092"
    environment:
      KAFKA_BROKER_ID: 1
      KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka:29092,PLAINTEXT_HOST://localhost:9092
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,PLAINTEXT_HOST:PLAINTEXT
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_AUTO_CREATE_TOPICS_ENABLE: "true"
    healthcheck:
      test: ["CMD", "kafka-broker-api-versions", "--bootstrap-server", "localhost:9092"]
      interval: 15s
      timeout: 10s
      retries: 5

  kafka-connect:
    image: debezium/connect:2.4
    depends_on:
      kafka:
        condition: service_healthy
      postgres:
        condition: service_healthy
    ports:
      - "8083:8083"
    environment:
      BOOTSTRAP_SERVERS: kafka:29092
      GROUP_ID: 1
      CONFIG_STORAGE_TOPIC: connect_configs
      OFFSET_STORAGE_TOPIC: connect_offsets
      STATUS_STORAGE_TOPIC: connect_statuses
      KEY_CONVERTER: org.apache.kafka.connect.json.JsonConverter
      VALUE_CONVERTER: org.apache.kafka.connect.json.JsonConverter

volumes:
  pgdata:
```

### Start and Verify

```bash
# Start all services
docker compose up -d

# Check all 4 containers are running
docker compose ps

# Watch Kafka Connect logs until you see "Kafka Connect started"
docker compose logs -f kafka-connect

# Verify Kafka Connect REST API is up (should return [])
curl -s http://localhost:8083/connectors | jq .

# Verify Kafka broker is reachable
docker exec -it <kafka-container-name> kafka-topics --list --bootstrap-server localhost:9092
```

> ⏱ Kafka Connect takes ~30–60 seconds to fully initialize after the container starts. The REST API returns `[]` (empty connector list) when ready.

### Troubleshooting Common Issues

| Problem | Likely cause | Fix |
|---------|-------------|-----|
| `kafka-connect` exits immediately | Kafka not ready | Add `depends_on` with `condition: service_healthy` |
| `curl: Connection refused` on :8083 | Connect still initializing | Wait 60s, check `docker compose logs kafka-connect` |
| PostgreSQL not accepting connections | `init.sql` syntax error | Check `docker compose logs postgres` |

### Done when
- [ ] `docker compose ps` shows 4 containers: `Up`
- [ ] `curl http://localhost:8083/connectors` returns `[]`
- [ ] PostgreSQL is reachable: `psql -h localhost -U admin -d shopdb`

---

## Day 3 — PostgreSQL Logical Replication: WAL Config & Publication

**Goal:** Configure the `shopdb` schema and set up PostgreSQL logical replication so Debezium can stream changes.

### `init.sql` — Schema + Publication

This file runs automatically when the PostgreSQL container starts (mapped to `/docker-entrypoint-initdb.d/`).

```sql
-- init.sql

-- Application tables
CREATE TABLE IF NOT EXISTS orders (
  id          BIGSERIAL PRIMARY KEY,
  customer_id INT          NOT NULL,
  product_id  INT          NOT NULL,
  amount      NUMERIC(10,2) NOT NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS customers (
  id         BIGSERIAL PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  email      VARCHAR(200) UNIQUE NOT NULL,
  region     VARCHAR(20),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS products (
  id         SERIAL PRIMARY KEY,
  name       VARCHAR(200) NOT NULL,
  category   VARCHAR(100),
  price      NUMERIC(10,2),
  stock_qty  INT DEFAULT 0
);

-- Seed some data for initial snapshot testing
INSERT INTO customers (name, email, region) VALUES
  ('Alice Chen', 'alice@example.com', 'North'),
  ('Bob Torres', 'bob@example.com', 'South'),
  ('Carol Singh', 'carol@example.com', 'East');

INSERT INTO products (name, category, price, stock_qty) VALUES
  ('Laptop Pro', 'Electronics', 1299.99, 50),
  ('Running Shoes', 'Clothing', 89.99, 200);

-- Create Debezium publication
-- REPLICA IDENTITY FULL: include old row values in UPDATE/DELETE events
ALTER TABLE orders    REPLICA IDENTITY FULL;
ALTER TABLE customers REPLICA IDENTITY FULL;
ALTER TABLE products  REPLICA IDENTITY FULL;

CREATE PUBLICATION dbz_publication FOR TABLE orders, customers, products;
```

> **Why `REPLICA IDENTITY FULL`?** By default, PostgreSQL only includes the primary key in UPDATE/DELETE WAL events. Setting `REPLICA IDENTITY FULL` tells PostgreSQL to include all columns — so Debezium's `before` field is populated with the full pre-change row.

### Verify Replication is Configured

```sql
-- Check WAL level (should be 'logical')
SHOW wal_level;

-- Check the publication exists
SELECT pubname, puballtables FROM pg_publication;

-- List tables in the publication
SELECT * FROM pg_publication_tables WHERE pubname = 'dbz_publication';

-- Check existing replication slots (none yet — Debezium creates this)
SELECT slot_name, plugin, active FROM pg_replication_slots;
```

### If Init.sql Ran Before You Added It

If you started the PostgreSQL container before creating `init.sql`:
```bash
# Destroy the volume and recreate
docker compose down -v
docker compose up -d
```

> Postgres only runs init scripts on a **fresh data directory**. Stopping/starting alone won't re-run `init.sql`.

### Done when
- [ ] `SHOW wal_level;` → `logical`
- [ ] `SELECT * FROM pg_publication;` → `dbz_publication` exists
- [ ] All 3 tables exist with seed data
- [ ] `REPLICA IDENTITY FULL` set on all 3 tables

---

## Day 4 — Register Debezium Connector & Verify Event Flow

**Goal:** Register the PostgreSQL Debezium connector via the Kafka Connect REST API, then trigger DB changes and watch them flow to Kafka topics.

### `connector-config.json`

```json
{
  "name": "postgres-cdc-connector",
  "config": {
    "connector.class": "io.debezium.connector.postgresql.PostgresConnector",
    "database.hostname": "postgres",
    "database.port": "5432",
    "database.user": "admin",
    "database.password": "secret",
    "database.dbname": "shopdb",
    "topic.prefix": "cdc",
    "plugin.name": "pgoutput",
    "publication.name": "dbz_publication",
    "slot.name": "debezium_slot",
    "table.include.list": "public.orders,public.customers,public.products",
    "snapshot.mode": "initial",
    "transforms": "unwrap",
    "transforms.unwrap.type": "io.debezium.transforms.ExtractNewRecordState",
    "transforms.unwrap.drop.tombstones": "false",
    "transforms.unwrap.add.fields": "op,source.ts_ms",
    "heartbeat.interval.ms": "5000"
  }
}
```

> **`ExtractNewRecordState` (unwrap):** Flattens the full `before/after/source/op` envelope into a simple flat record. The `__op` field is added for operation type (`c`=create, `u`=update, `d`=delete, `r`=snapshot read). Your consumer always sees the flattened form.

### Register the Connector

```bash
# Register via REST API
curl -X POST http://localhost:8083/connectors \
  -H "Content-Type: application/json" \
  -d @connector-config.json

# Verify connector is running (status should be RUNNING)
curl -s http://localhost:8083/connectors/postgres-cdc-connector/status | jq .

# List all topics created by Debezium
docker exec <kafka-container> kafka-topics --list --bootstrap-server localhost:9092
# Expect: cdc.public.orders  cdc.public.customers  cdc.public.products
```

### Verify the Replication Slot Was Created

```sql
-- Should now show debezium_slot as active
SELECT slot_name, plugin, active, restart_lsn
FROM pg_replication_slots;
```

### Trigger Changes & Watch Kafka

```bash
# Open a Kafka console consumer in a terminal window — leave it running
docker exec <kafka-container> kafka-console-consumer \
  --bootstrap-server localhost:9092 \
  --topic cdc.public.orders \
  --from-beginning
```

In a separate terminal, INSERT and UPDATE rows:
```sql
-- psql -h localhost -U admin -d shopdb

-- Trigger an INSERT (op = 'c')
INSERT INTO orders (customer_id, product_id, amount, status)
VALUES (1, 1, 299.99, 'pending');

-- Trigger an UPDATE (op = 'u')
UPDATE orders SET status = 'shipped' WHERE id = 1;

-- Trigger a DELETE (op = 'd') — generates tombstone
DELETE FROM orders WHERE id = 1;
```

**Expected Kafka output (flattened by ExtractNewRecordState):**
```json
{"id":1,"customer_id":1,"product_id":1,"amount":299.99,"status":"pending","__op":"c","__source_ts_ms":1710000000000}
{"id":1,"customer_id":1,"product_id":1,"amount":299.99,"status":"shipped","__op":"u","__source_ts_ms":1710000001000}
{"id":1,"__op":"d","__source_ts_ms":1710000002000}
```

The tombstone (DELETE's second message) has a **null value** — the console consumer prints it as an empty line.

### Done when
- [ ] `GET /connectors/postgres-cdc-connector/status` → `"state": "RUNNING"`
- [ ] `pg_replication_slots` shows `debezium_slot` as active
- [ ] INSERT in PostgreSQL → JSON event visible in Kafka topic within 1–2 seconds
- [ ] UPDATE and DELETE events also visible

---

## Day 5 — Python Consumer: Handle All Operation Types

**Goal:** Write a Python consumer that reads from Kafka topics and routes events by operation type (`c`, `u`, `d`).

### Setup Python Environment

```bash
pip install confluent-kafka
```

### `consumer.py`

```python
"""
CDC Consumer — handles INSERT, UPDATE, DELETE events from Debezium.
Events are flattened by ExtractNewRecordState; operation type is in __op field.
"""
from confluent_kafka import Consumer, KafkaError
import json
import logging

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s"
)
log = logging.getLogger(__name__)

TOPICS = ["cdc.public.orders", "cdc.public.customers", "cdc.public.products"]

consumer = Consumer({
    "bootstrap.servers": "localhost:9092",
    "group.id": "analytics-consumer-v1",
    "auto.offset.reset": "earliest",
    "enable.auto.commit": True,
})
consumer.subscribe(TOPICS)

def handle_insert(topic: str, event: dict) -> None:
    log.info(f"INSERT on {topic}: id={event.get('id')} | {event}")

def handle_update(topic: str, event: dict) -> None:
    log.info(f"UPDATE on {topic}: id={event.get('id')} | status={event.get('status')}")

def handle_delete(topic: str, event: dict) -> None:
    log.info(f"DELETE on {topic}: id={event.get('id')}")

def handle_tombstone(topic: str, key: str) -> None:
    # Tombstone: value is None, signals downstream to remove the record
    log.info(f"TOMBSTONE (cleanup signal) on {topic}: key={key}")

def process_message(msg) -> None:
    topic = msg.topic()
    raw_value = msg.value()

    # Tombstone message: value is None
    if raw_value is None:
        handle_tombstone(topic, msg.key())
        return

    event = json.loads(raw_value)
    op = event.get("__op")

    if op == "c":
        handle_insert(topic, event)
    elif op == "u":
        handle_update(topic, event)
    elif op == "d":
        handle_delete(topic, event)
    elif op == "r":
        # Snapshot read — initial sync of existing rows
        log.debug(f"SNAPSHOT on {topic}: id={event.get('id')}")
    else:
        log.warning(f"Unknown op '{op}' on {topic}: {event}")

try:
    log.info(f"Subscribing to: {TOPICS}")
    while True:
        msg = consumer.poll(timeout=1.0)
        if msg is None:
            continue
        if msg.error():
            if msg.error().code() == KafkaError._PARTITION_EOF:
                log.debug("Reached end of partition")
                continue
            log.error(f"Kafka error: {msg.error()}")
            break
        process_message(msg)

except KeyboardInterrupt:
    log.info("Consumer stopped.")
finally:
    consumer.close()
```

### Run and Test

```bash
# Terminal 1: start consumer
python consumer.py

# Terminal 2: trigger events in PostgreSQL
psql -h localhost -U admin -d shopdb -c \
  "INSERT INTO orders (customer_id, product_id, amount, status) VALUES (2, 1, 149.50, 'pending');"

psql -h localhost -U admin -d shopdb -c \
  "UPDATE orders SET status = 'delivered' WHERE customer_id = 2;"
```

**Expected consumer output:**
```
2026-08-31 [INFO] INSERT on cdc.public.orders: id=2 | {...}
2026-08-31 [INFO] UPDATE on cdc.public.orders: id=2 | status=delivered
```

### Test Snapshot (initial sync of existing rows)

Stop and delete the consumer group offset to replay from the beginning:
```bash
# Stop consumer first, then:
docker exec <kafka-container> kafka-consumer-groups \
  --bootstrap-server localhost:9092 \
  --group analytics-consumer-v1 \
  --delete

# Restart consumer — will see 'r' (snapshot read) events for seeded rows
python consumer.py
```

### Done when
- [ ] Consumer prints INSERT / UPDATE correctly
- [ ] Consumer handles `r` (snapshot) events without crashing
- [ ] Code handles `msg.value() is None` (tombstone) without error

---

## Day 6 — Delete / Tombstone Handling + Latency Measurement

**Goal:** Fully test DELETE flow, understand tombstones, and measure the end-to-end latency from DB write to consumer receipt.

### Understanding Tombstones

When a row is deleted, Debezium emits **two messages** to Kafka:

```
Message 1 (DELETE event):
  key:   {"id": 1}
  value: {"id": 1, "__op": "d", "__source_ts_ms": 1710000002000}

Message 2 (Tombstone):
  key:   {"id": 1}
  value: null   ← signals Kafka log compaction to remove this key
```

The tombstone exists for **Kafka log compaction** — it tells Kafka's compaction process to eventually delete all older messages with that key. Without it, a compacted topic would retain stale "ghost" records forever.

**Your consumer must handle `None` values** — already done in Day 5's `handle_tombstone()`. Verify it works:

```sql
-- Trigger a DELETE
INSERT INTO orders (customer_id, product_id, amount, status) VALUES (3, 2, 75.00, 'pending');
-- Note the ID printed in consumer logs
DELETE FROM orders WHERE customer_id = 3;
```

Expected consumer output:
```
[INFO] INSERT on cdc.public.orders: id=5
[INFO] DELETE on cdc.public.orders: id=5
[INFO] TOMBSTONE (cleanup signal) on cdc.public.orders: key=b'{"id":5}'
```

### Measure End-to-End Latency

The Debezium event includes `__source_ts_ms` (when the DB wrote the change) and Kafka message timestamp (when it was written to Kafka).

Add latency measurement to `consumer.py`:

```python
import time

def process_message(msg) -> None:
    topic = msg.topic()
    raw_value = msg.value()
    kafka_ts_ms = msg.timestamp()[1]  # Kafka broker timestamp

    if raw_value is None:
        handle_tombstone(topic, msg.key())
        return

    event = json.loads(raw_value)
    op = event.get("__op")
    source_ts_ms = event.get("__source_ts_ms", 0)
    consumer_ts_ms = int(time.time() * 1000)

    if source_ts_ms:
        db_to_kafka_ms = kafka_ts_ms - source_ts_ms
        kafka_to_consumer_ms = consumer_ts_ms - kafka_ts_ms
        total_latency_ms = consumer_ts_ms - source_ts_ms
        log.info(
            f"op={op} | DB→Kafka: {db_to_kafka_ms}ms | "
            f"Kafka→Consumer: {kafka_to_consumer_ms}ms | "
            f"Total: {total_latency_ms}ms"
        )

    # ... rest of routing logic
```

### Document Latency in `results/latency.md`

```markdown
# CDC End-to-End Latency Measurements

## Environment
- PostgreSQL 15, Debezium 2.4, Kafka (Confluent 7.5.0)
- All services: Docker on localhost (MacBook M2, 16GB RAM)
- Date: 2026-08-31

## Results (average of 10 events per operation type)

| Operation | DB→Kafka | Kafka→Consumer | Total |
|-----------|----------|----------------|-------|
| INSERT | ~XX ms | ~XX ms | ~XX ms |
| UPDATE | ~XX ms | ~XX ms | ~XX ms |
| DELETE | ~XX ms | ~XX ms | ~XX ms |

## Notes
- heartbeat.interval.ms=5000 ensures WAL is read even during idle periods
- Local Docker latency is ~10-50ms; production Kafka on same network is similar
```

### Also Explore: NATS JetStream as a Lightweight Alternative

If you have time, stand up a NATS server alongside Kafka:
```bash
docker run -d -p 4222:4222 -p 8222:8222 nats:latest -js
```

NATS JetStream is worth understanding for clients who want a lighter-weight broker than Kafka. Compare:

| | Kafka | NATS JetStream |
|-|-------|---------------|
| Setup complexity | High (ZooKeeper/KRaft + Connect) | Low (single binary) |
| Throughput | Very high | High |
| Ecosystem | Mature (connectors, Streams, ksqlDB) | Growing |
| Best for | Enterprise data pipelines, high volume | Lightweight event buses, microservices |

### Done when
- [ ] DELETE → DELETE event → Tombstone all visible in consumer logs
- [ ] Latency numbers recorded in `results/latency.md`
- [ ] Consumer doesn't crash on `None` value messages

---

## Day 7 — Consolidation & Deliverable

**Goal:** Assemble the final deliverable, close all checklist items, and document the pipeline.

### Final Deliverable Structure

```
cdc-pipeline/
├── docker-compose.yml         # All 4 services
├── init.sql                   # Schema + publication + seed data
├── connector-config.json      # Debezium connector registration payload
├── consumer.py                # Python consumer with INSERT/UPDATE/DELETE routing
├── results/
│   └── latency.md             # Measured end-to-end latency
└── README.md                  # Setup guide + architecture diagram
```

### `README.md` Template

```markdown
# CDC Pipeline — Local PostgreSQL → Debezium → Kafka

## Architecture
[paste your architecture diagram from Day 1]

## Quick Start
\`\`\`bash
docker compose up -d
# Wait ~60s for Kafka Connect to initialize
curl -X POST http://localhost:8083/connectors -H "Content-Type: application/json" -d @connector-config.json
python consumer.py
\`\`\`

## Verify Pipeline
\`\`\`bash
# Insert a test row
psql -h localhost -U admin -d shopdb -c \
  "INSERT INTO orders (customer_id, product_id, amount, status) VALUES (1, 1, 99.99, 'pending');"
# Consumer should print the INSERT event within 1-2 seconds
\`\`\`

## Measured Latency
[paste results/latency.md table]

## Key Learnings
- [What surprised you about tombstones?]
- [What would be different on a client's on-prem environment?]
- [What monitoring would you add in production?]
```

### Morning: Final Checklist Review

Go through the milestone checklist:
- [ ] PostgreSQL configured with `wal_level = logical` and publication created?
- [ ] Kafka + Kafka Connect with Debezium running via Docker Compose?
- [ ] Connector registered and appearing in `GET /connectors`?
- [ ] INSERT/UPDATE triggering events visible in Kafka topic?
- [ ] Python consumer handling `c`, `u`, and `d` operations?
- [ ] DELETE tombstone handled (no crash on `None` value)?
- [ ] End-to-end latency documented?

### Afternoon: Production Hardening Notes

Add to `README.md` a "Production Considerations" section — this is what FDEs discuss with clients:

```markdown
## Production Considerations

| Area | Local Setup | Production Addition |
|------|------------|---------------------|
| **Secrets** | Plaintext in docker-compose.yml | Vault / AWS Secrets Manager |
| **Schema changes** | Restart connector | Schema Registry + Avro serialization |
| **Monitoring** | Manual log inspection | Kafka Connect JMX → Grafana, Debezium metrics |
| **Slot lag** | Not monitored | Alert when `pg_replication_slots.confirmed_flush_lsn` lags |
| **Consumer errors** | Crash + restart | Dead-letter topic + retry logic |
| **Multi-table fan-out** | Single consumer | Consumer group per downstream system |
```

### Self-Debrief Questions

Write answers in your notes:
1. A client says "we can't give you replication slot access." What's your fallback CDC approach?
2. What happens to the Kafka topic if the Debezium connector goes offline for 48 hours?
3. What's the difference between `snapshot.mode: initial` and `snapshot.mode: never`?

### Done when
- [ ] All 7 checklist items from the milestone are checked
- [ ] `cdc-pipeline/` directory committed to git
- [ ] `README.md` has Quick Start, latency table, and Production Considerations
- [ ] Self-debrief answers written

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Debezium PostgreSQL Connector Docs](https://debezium.io/documentation/reference/stable/connectors/postgresql.html) |
| 2 | [Docker Compose healthcheck docs](https://docs.docker.com/compose/compose-file/05-services/#healthcheck) |
| 3 | [PostgreSQL Logical Replication Docs](https://www.postgresql.org/docs/current/logical-replication.html) |
| 4 | [Kafka Connect REST API](https://docs.confluent.io/platform/current/connect/references/restapi.html) |
| 5 | [confluent-kafka Python client](https://docs.confluent.io/platform/current/clients/confluent-kafka-python/html/index.html) |
| 6 | [NATS JetStream Docs](https://docs.nats.io/nats-concepts/jetstream) |
| 7 | [Debezium Blog — Production CDC](https://debezium.io/blog/) |

---

*→ Next: [Milestone 03 — DB Internals: OLTP vs OLAP](../milestones/03-db-internals-oltp-olap.md)*
