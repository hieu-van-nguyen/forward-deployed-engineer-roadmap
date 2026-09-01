# Week 3 — DB Internals: OLTP vs. OLAP — Day-by-Day Plan

> **Milestone:** [03 — DB Internals: OLTP vs. OLAP Data Modeling](../milestones/03-db-internals-oltp-olap.md)
> **Month:** M1 · **Weeks:** W3–W4 (this plan covers W3, Days 1–7)
> **Pacing note:** The milestone spans W3–W4. This document covers W3. W4 is covered by [Milestone 04 — Glue Architecture: Resilient HTTP](../milestones/04-glue-architecture-resilient-http.md).
> **Environment note:** This week uses a dedicated `schema_bench` database — a fresh PostgreSQL instance separate from Week 1's `orders_bench` and Week 2's `shopdb`. You'll build three schemas side-by-side: OLTP (3NF), Star Schema, and OBT.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | OLTP vs. OLAP theory — storage models, query patterns, engine choices | Written notes, decision matrix draft |
| 2 | Build OLTP (3NF) schema + seed 1M+ rows | `oltp_schema.sql`, data loaded |
| 3 | Build Star Schema — fact table, all dimensions, SCD Type 2 explained | `star_schema.sql`, dim tables loaded |
| 4 | Build OBT — denormalize, run analytical queries on all three schemas | `obt.sql`, first timing comparison |
| 5 | EXPLAIN ANALYZE across all three schemas for same queries | 3 plan outputs, bottlenecks identified |
| 6 | Indexing strategies — B-tree, Hash, BRIN, GIN — applied and measured | Before/after timings per index type |
| 7 | Schema design doc — ERDs, benchmark table, decision matrix | `schema-design-doc.md` complete |

---

## Day 1 — OLTP vs. OLAP: Theory & Mental Model

**Goal:** Build the conceptual map before touching any SQL. Understand *why* two paradigms exist, what storage models drive them, and how to recognize each in the wild.

### The Core Distinction

Both paradigms store relational data — the difference is the **dominant workload**:

| Dimension | OLTP | OLAP |
|-----------|------|------|
| **Workload** | Many short read/write transactions | Few long analytical scans |
| **Schema style** | Normalized (3NF) | Denormalized (star / snowflake / OBT) |
| **Primary concern** | Write throughput + data integrity | Read throughput + query simplicity |
| **Rows touched per query** | 1–1,000 (point lookups) | Millions (full-column scans) |
| **Engines** | PostgreSQL, MySQL, Oracle | Redshift, BigQuery, Snowflake, DuckDB, ClickHouse |
| **Index strategy** | B-tree on PKs/FKs | Columnar, partitioning, sort keys, clustering |

> **FDE situation:** A client says "our dashboards are slow." Before adding indexes, ask: is the schema 3NF with many JOINs? If yes, the problem may be schema design, not indexes.

### Storage Models That Drive the Difference

**Row-oriented storage (OLTP):**
```
Row 1: [id=1, customer_id=42, amount=99.99, status='pending']
Row 2: [id=2, customer_id=17, amount=149.00, status='shipped']
```
- Read/write entire rows efficiently
- Good for: single-row lookups, point updates
- Bad for: `SELECT SUM(amount) FROM orders` — must read every column of every row

**Column-oriented storage (OLAP):**
```
id column:          [1, 2, 3, 4, ...]
amount column:      [99.99, 149.00, 249.00, ...]
status column:      ['pending', 'shipped', 'pending', ...]
```
- Scan only the columns you query
- Excellent compression (same data type per column)
- Bad for: single-row writes (must update every column file)

### Normal Forms — Why OLTP Uses Them

**1NF:** No repeating groups — every column holds one atomic value.

**2NF:** Every non-key attribute depends on the *entire* primary key (no partial dependencies). Applies to composite PKs.

**3NF:** Every non-key attribute depends *only* on the primary key, not on other non-key attributes.

**Practical example of 3NF violation (and fix):**
```sql
-- BAD: category_name depends on category_id, not on order_item_id
CREATE TABLE order_items (
  id           SERIAL PRIMARY KEY,
  order_id     INT,
  product_id   INT,
  category_id  INT,
  category_name VARCHAR(100)  -- ← violates 3NF: move to categories table
);

-- GOOD: separate categories table
CREATE TABLE categories (
  id   SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL
);
-- order_items references category via products.category_id
```

### Dimensional Modeling Basics (Kimball)

Ralph Kimball's star schema has two table types:

**Fact table** — stores measurable business events (orders, clicks, payments):
- Mostly numeric, append-only
- Foreign keys to every dimension
- Grain: one row = one event (e.g., one order line)

**Dimension table** — stores descriptive context (who, what, where, when):
- Wider, slower-changing
- Denormalized for query simplicity
- Surrogate keys (not natural keys) for SCD support

**Slowly Changing Dimensions (SCD) — three types:**

| Type | Behavior | Use when |
|------|----------|----------|
| SCD 1 | Overwrite old value | History doesn't matter (e.g., phone number) |
| SCD 2 | Add new row with valid_from/valid_to + is_current flag | Full history required (e.g., customer segment changes) |
| SCD 3 | Add `previous_value` column | Only last change matters |

### Study Tasks

- Read: [Kimball Group — Dimensional Modeling Techniques](https://www.kimballgroup.com/data-warehouse-business-intelligence-resources/kimball-techniques/dimensional-modeling-techniques/)
- Read: *Designing Data-Intensive Applications* Chapter 3 (storage engines, column stores) — or the [DDIA summary at Goodreads notes]
- Answer in writing:
  1. A client has a PostgreSQL database with 50 tables in 3NF and dashboard queries taking 30+ seconds. What do you recommend first — indexing or schema redesign?
  2. A BigQuery project charges by bytes scanned. Which schema minimizes cost: star schema or OBT?
  3. What is the "grain" of a fact table, and why does getting it wrong break aggregations?

### Done when
- [ ] Can explain 3NF in plain English without notes
- [ ] Know the difference between SCD Type 1, 2, and 3
- [ ] Decision matrix draft written (when OLTP, when Star, when OBT)

---

## Day 2 — Build the OLTP (3NF) Schema + Seed 1M+ Rows

**Goal:** Implement a realistic 3NF e-commerce schema in PostgreSQL and populate it with 1M+ orders for benchmarking.

### Setup

```bash
psql -U postgres -c "CREATE DATABASE schema_bench;"
psql -U postgres -d schema_bench
```

### `oltp_schema.sql`

```sql
-- ============================================================
-- OLTP: Third Normal Form (3NF) e-commerce schema
-- ============================================================

CREATE TABLE categories (
  id   SERIAL PRIMARY KEY,
  name VARCHAR(100) NOT NULL
);

CREATE TABLE customers (
  id         SERIAL PRIMARY KEY,
  email      VARCHAR(255) UNIQUE NOT NULL,
  name       VARCHAR(255) NOT NULL,
  segment    VARCHAR(50),       -- 'enterprise', 'smb', 'consumer'
  country    VARCHAR(50),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE products (
  id          SERIAL PRIMARY KEY,
  sku         VARCHAR(50) UNIQUE NOT NULL,
  name        VARCHAR(255) NOT NULL,
  category_id INT REFERENCES categories(id),
  price       NUMERIC(10,2) NOT NULL,
  brand       VARCHAR(100)
);

CREATE TABLE orders (
  id          BIGSERIAL PRIMARY KEY,
  customer_id INT REFERENCES customers(id),
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  status      VARCHAR(20) NOT NULL DEFAULT 'pending'
);

CREATE TABLE order_items (
  id         BIGSERIAL PRIMARY KEY,
  order_id   BIGINT REFERENCES orders(id),
  product_id INT REFERENCES products(id),
  qty        INT NOT NULL,
  unit_price NUMERIC(10,2) NOT NULL
);

-- Seed dimension data
INSERT INTO categories (name) VALUES
  ('Electronics'), ('Clothing'), ('Home & Garden'),
  ('Sports'), ('Books'), ('Toys'), ('Food'), ('Automotive');

INSERT INTO customers (email, name, segment, country)
SELECT
  'customer_' || i || '@example.com',
  'Customer ' || i,
  (ARRAY['enterprise','smb','consumer'])[1 + (i % 3)],
  (ARRAY['US','UK','CA','DE','AU'])[1 + (i % 5)]
FROM generate_series(1, 50000) i;

INSERT INTO products (sku, name, category_id, price, brand)
SELECT
  'SKU-' || LPAD(i::TEXT, 6, '0'),
  'Product ' || i,
  1 + (i % 8),
  (random() * 500 + 5)::NUMERIC(10,2),
  (ARRAY['BrandA','BrandB','BrandC','BrandD'])[1 + (i % 4)]
FROM generate_series(1, 5000) i;

-- Seed 1M+ orders (with order_items)
INSERT INTO orders (customer_id, created_at, status)
SELECT
  1 + (random() * 49999)::INT,
  NOW() - (random() * 730)::INT * INTERVAL '1 day',
  (ARRAY['pending','shipped','delivered','cancelled'])[1 + (random() * 3)::INT]
FROM generate_series(1, 1000000);

INSERT INTO order_items (order_id, product_id, qty, unit_price)
SELECT
  o.id,
  1 + (random() * 4999)::INT,
  1 + (random() * 5)::INT,
  p.price
FROM orders o
CROSS JOIN LATERAL (
  SELECT price FROM products WHERE id = 1 + (random() * 4999)::INT LIMIT 1
) p
WHERE random() < 1.0;  -- one item per order for simplicity

ANALYZE;
```

> ⏱ The `order_items` INSERT can take 3–8 minutes. Let it run.

### Verify

```sql
SELECT COUNT(*) FROM orders;        -- should be 1,000,000
SELECT COUNT(*) FROM order_items;   -- should be ~1,000,000
SELECT COUNT(*) FROM customers;     -- 50,000
SELECT COUNT(*) FROM products;      -- 5,000
```

### Run the Baseline Analytical Query (save timing)

```sql
-- Revenue by category, last 90 days — OLTP (3NF)
\timing on
EXPLAIN ANALYZE
SELECT
  cat.name AS category,
  SUM(oi.qty * oi.unit_price) AS revenue,
  COUNT(DISTINCT o.id) AS order_count
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
JOIN products p ON oi.product_id = p.id
JOIN categories cat ON p.category_id = cat.id
WHERE o.created_at > NOW() - INTERVAL '90 days'
GROUP BY cat.name
ORDER BY revenue DESC;
```

Save the execution time in `results/timing.md` as your **OLTP baseline**.

### Done when
- [ ] `schema_bench` database created with all 6 OLTP tables
- [ ] 1M+ rows in `orders` and `order_items`
- [ ] Baseline query time recorded

---

## Day 3 — Build the Star Schema + SCD Type 2

**Goal:** Build a star schema equivalent of the OLTP schema, populate it from the OLTP tables, and understand SCD Type 2 implementation.

### `star_schema.sql`

```sql
-- ============================================================
-- OLAP: Star Schema
-- ============================================================

-- Date dimension (populated separately)
CREATE TABLE dim_date (
  date_key     INT PRIMARY KEY,        -- format: YYYYMMDD
  full_date    DATE NOT NULL,
  year         INT,
  quarter      INT,
  month        INT,
  month_name   VARCHAR(20),
  week         INT,
  day_of_week  VARCHAR(10),
  is_weekend   BOOLEAN,
  is_holiday   BOOLEAN DEFAULT FALSE
);

-- Customer dimension (SCD Type 2)
CREATE TABLE dim_customer (
  customer_key SERIAL PRIMARY KEY,     -- surrogate key
  customer_id  INT NOT NULL,           -- natural key (from OLTP)
  name         VARCHAR(255),
  email        VARCHAR(255),
  segment      VARCHAR(50),
  country      VARCHAR(50),
  -- SCD Type 2 tracking fields:
  valid_from   DATE NOT NULL,
  valid_to     DATE,                   -- NULL means current record
  is_current   BOOLEAN NOT NULL DEFAULT TRUE
);

-- Product dimension (no SCD for simplicity)
CREATE TABLE dim_product (
  product_key  SERIAL PRIMARY KEY,
  product_id   INT NOT NULL,
  sku          VARCHAR(50),
  name         VARCHAR(255),
  category     VARCHAR(100),
  brand        VARCHAR(100),
  price_tier   VARCHAR(20)             -- 'budget', 'mid', 'premium'
);

-- Fact table (one row per order item — grain = order line)
CREATE TABLE fact_orders (
  order_key     BIGSERIAL PRIMARY KEY,
  order_id      BIGINT NOT NULL,
  customer_key  INT REFERENCES dim_customer(customer_key),
  product_key   INT REFERENCES dim_product(product_key),
  date_key      INT REFERENCES dim_date(date_key),
  qty           INT,
  unit_price    NUMERIC(10,2),
  gross_revenue NUMERIC(12,2),         -- qty * unit_price
  discount      NUMERIC(5,4) DEFAULT 0,
  net_revenue   NUMERIC(12,2)          -- gross_revenue * (1 - discount)
)
PARTITION BY RANGE (date_key);

-- Create partitions by year
CREATE TABLE fact_orders_2023 PARTITION OF fact_orders
  FOR VALUES FROM (20230101) TO (20240101);
CREATE TABLE fact_orders_2024 PARTITION OF fact_orders
  FOR VALUES FROM (20240101) TO (20250101);
CREATE TABLE fact_orders_2025 PARTITION OF fact_orders
  FOR VALUES FROM (20250101) TO (20260101);
CREATE TABLE fact_orders_2026 PARTITION OF fact_orders
  FOR VALUES FROM (20260101) TO (20270101);
```

### Populate the Dimensions

```sql
-- Populate dim_date (2023–2026)
INSERT INTO dim_date (date_key, full_date, year, quarter, month, month_name, week, day_of_week, is_weekend)
SELECT
  TO_CHAR(d, 'YYYYMMDD')::INT          AS date_key,
  d::DATE                              AS full_date,
  EXTRACT(YEAR FROM d)::INT            AS year,
  EXTRACT(QUARTER FROM d)::INT         AS quarter,
  EXTRACT(MONTH FROM d)::INT           AS month,
  TO_CHAR(d, 'Month')                  AS month_name,
  EXTRACT(WEEK FROM d)::INT            AS week,
  TO_CHAR(d, 'Day')                    AS day_of_week,
  EXTRACT(DOW FROM d) IN (0, 6)        AS is_weekend
FROM generate_series('2023-01-01'::DATE, '2026-12-31'::DATE, '1 day') d;

-- Populate dim_customer from OLTP customers
INSERT INTO dim_customer (customer_id, name, email, segment, country, valid_from, is_current)
SELECT id, name, email, segment, country, '2023-01-01'::DATE, TRUE
FROM customers;

-- Populate dim_product from OLTP products + categories
INSERT INTO dim_product (product_id, sku, name, category, brand, price_tier)
SELECT
  p.id,
  p.sku,
  p.name,
  cat.name,
  p.brand,
  CASE
    WHEN p.price < 50  THEN 'budget'
    WHEN p.price < 200 THEN 'mid'
    ELSE 'premium'
  END
FROM products p
JOIN categories cat ON p.category_id = cat.id;

-- Populate fact_orders from OLTP order_items
INSERT INTO fact_orders (order_id, customer_key, product_key, date_key, qty, unit_price, gross_revenue, net_revenue)
SELECT
  oi.order_id,
  dc.customer_key,
  dp.product_key,
  TO_CHAR(o.created_at, 'YYYYMMDD')::INT,
  oi.qty,
  oi.unit_price,
  oi.qty * oi.unit_price,
  oi.qty * oi.unit_price  -- no discount in sample data
FROM order_items oi
JOIN orders o           ON oi.order_id = o.id
JOIN dim_customer dc    ON dc.customer_id = o.customer_id AND dc.is_current = TRUE
JOIN dim_product dp     ON dp.product_id = oi.product_id;

ANALYZE;
```

### SCD Type 2 — How It Works

When a customer changes segment (e.g., from `smb` to `enterprise`):

```sql
-- Step 1: expire the current record
UPDATE dim_customer
SET valid_to = CURRENT_DATE - 1, is_current = FALSE
WHERE customer_id = 42 AND is_current = TRUE;

-- Step 2: insert new current record
INSERT INTO dim_customer (customer_id, name, email, segment, country, valid_from, is_current)
VALUES (42, 'Alice Chen', 'alice@example.com', 'enterprise', 'US', CURRENT_DATE, TRUE);
```

**Why SCD Type 2 matters for FDEs:** A client asks "what was our revenue from SMB customers in Q1 2024?" Without SCD2, if customers upgraded to enterprise you'd have no way to answer — the dimension has been overwritten.

### Run the Star Schema Equivalent Query (save timing)

```sql
-- Revenue by category, last 90 days — Star Schema
\timing on
EXPLAIN ANALYZE
SELECT
  dp.category,
  SUM(f.net_revenue) AS revenue,
  COUNT(DISTINCT f.order_id) AS order_count
FROM fact_orders f
JOIN dim_product dp ON f.product_key = dp.product_key
JOIN dim_date d     ON f.date_key = d.date_key
WHERE d.full_date > CURRENT_DATE - 90
GROUP BY dp.category
ORDER BY revenue DESC;
```

Record timing in `results/timing.md` under **Star Schema**.

### Done when
- [ ] All 4 star schema tables created with correct partitioning
- [ ] `dim_date` populated for 2023–2026
- [ ] `fact_orders` populated from OLTP data
- [ ] Star schema query timing recorded
- [ ] Can explain SCD Type 2 with the 2-step UPDATE + INSERT pattern

---

## Day 4 — Build OBT + Compare All Three Schemas

**Goal:** Build the OBT denormalization, run the same analytical query on all three schemas, and observe where each shines.

### `obt.sql` — Build the One Big Table

```sql
-- ============================================================
-- OBT: One Big Table — everything denormalized
-- ============================================================
CREATE TABLE obt_orders AS
SELECT
  o.id                          AS order_id,
  o.created_at,
  o.status,
  -- Customer fields (denormalized)
  c.id                          AS customer_id,
  c.name                        AS customer_name,
  c.email                       AS customer_email,
  c.segment                     AS customer_segment,
  c.country                     AS customer_country,
  -- Product fields (denormalized)
  oi.product_id,
  p.sku                         AS product_sku,
  p.name                        AS product_name,
  p.brand                       AS product_brand,
  cat.name                      AS category_name,
  -- Metrics
  oi.qty,
  oi.unit_price,
  oi.qty * oi.unit_price        AS line_revenue,
  -- Date parts (denormalized — avoids date dimension join)
  EXTRACT(YEAR FROM o.created_at)::INT      AS order_year,
  EXTRACT(QUARTER FROM o.created_at)::INT   AS order_quarter,
  EXTRACT(MONTH FROM o.created_at)::INT     AS order_month,
  DATE_TRUNC('day', o.created_at)::DATE     AS order_date
FROM order_items oi
JOIN orders o      ON oi.order_id = o.id
JOIN customers c   ON o.customer_id = c.id
JOIN products p    ON oi.product_id = p.id
JOIN categories cat ON p.category_id = cat.id;

CREATE INDEX idx_obt_created_at ON obt_orders(order_date);
CREATE INDEX idx_obt_category ON obt_orders(category_name);

ANALYZE obt_orders;
```

### Run the Same Query on OBT (save timing)

```sql
-- Revenue by category, last 90 days — OBT
\timing on
EXPLAIN ANALYZE
SELECT
  category_name,
  SUM(line_revenue) AS revenue,
  COUNT(DISTINCT order_id) AS order_count
FROM obt_orders
WHERE order_date > CURRENT_DATE - 90
GROUP BY category_name
ORDER BY revenue DESC;
```

Record timing in `results/timing.md` under **OBT**.

### Run Two More Benchmark Queries on All Three Schemas

**Query 2 — Top 10 customers by revenue (all time):**

```sql
-- OLTP
SELECT c.name, SUM(oi.qty * oi.unit_price) AS revenue
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
JOIN customers c ON o.customer_id = c.id
GROUP BY c.name ORDER BY revenue DESC LIMIT 10;

-- Star Schema
SELECT dc.name, SUM(f.net_revenue) AS revenue
FROM fact_orders f
JOIN dim_customer dc ON f.customer_key = dc.customer_key AND dc.is_current = TRUE
GROUP BY dc.name ORDER BY revenue DESC LIMIT 10;

-- OBT
SELECT customer_name, SUM(line_revenue) AS revenue
FROM obt_orders
GROUP BY customer_name ORDER BY revenue DESC LIMIT 10;
```

**Query 3 — Monthly revenue trend:**

```sql
-- OLTP
SELECT DATE_TRUNC('month', o.created_at) AS month, SUM(oi.qty * oi.unit_price) AS revenue
FROM order_items oi JOIN orders o ON oi.order_id = o.id
GROUP BY 1 ORDER BY 1;

-- Star Schema
SELECT d.year, d.month, SUM(f.net_revenue) AS revenue
FROM fact_orders f JOIN dim_date d ON f.date_key = d.date_key
GROUP BY d.year, d.month ORDER BY d.year, d.month;

-- OBT
SELECT order_year, order_month, SUM(line_revenue) AS revenue
FROM obt_orders
GROUP BY order_year, order_month ORDER BY order_year, order_month;
```

### What to Observe

| Pattern | Expected Behavior |
|---------|------------------|
| OLTP 3NF | Multiple Hash Joins in EXPLAIN plan; performance degrades with more JOINs |
| Star Schema | Partition pruning on `fact_orders` (check "Partitions: " in plan); fewer rows scanned |
| OBT | Single table scan; fast for aggregations; duplicated data means larger table size |

### OBT Trade-offs to Document

| Factor | OBT Advantage | OBT Disadvantage |
|--------|--------------|-----------------|
| Query speed | Fast — no JOINs | Depends on indexes |
| Storage | Simple | Data duplication (customer name repeated per order) |
| Schema changes | One table to update | UPDATE fan-out is expensive |
| Columnar engines | Ideal (BigQuery, Snowflake) | Wasteful in row-based PostgreSQL |

### Done when
- [ ] `obt_orders` created and indexed
- [ ] All 3 queries run on all 3 schemas
- [ ] Timing table in `results/timing.md` has 3 queries × 3 schemas = 9 cells

---

## Day 5 — EXPLAIN ANALYZE: Cross-Schema Plan Comparison

**Goal:** Systematically compare EXPLAIN ANALYZE plans across OLTP, Star, and OBT for the same query. Identify and name the cost drivers.

### Setup: Ensure Statistics Are Fresh

```sql
ANALYZE orders; ANALYZE order_items; ANALYZE customers; ANALYZE products; ANALYZE categories;
ANALYZE fact_orders; ANALYZE dim_customer; ANALYZE dim_product; ANALYZE dim_date;
ANALYZE obt_orders;
```

### Capture Plans in Full

For each schema, run the **revenue by category (90 days)** query with full options:

```sql
-- OLTP plan
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT cat.name, SUM(oi.qty * oi.unit_price) AS revenue
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
JOIN products p ON oi.product_id = p.id
JOIN categories cat ON p.category_id = cat.id
WHERE o.created_at > NOW() - INTERVAL '90 days'
GROUP BY cat.name;
```

Save output to `results/explain_plans/oltp_revenue_90d.txt`

```sql
-- Star Schema plan
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT dp.category, SUM(f.net_revenue) AS revenue
FROM fact_orders f
JOIN dim_product dp ON f.product_key = dp.product_key
JOIN dim_date d ON f.date_key = d.date_key
WHERE d.full_date > CURRENT_DATE - 90
GROUP BY dp.category;
```

Save to `results/explain_plans/star_revenue_90d.txt`

```sql
-- OBT plan
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT category_name, SUM(line_revenue) AS revenue
FROM obt_orders
WHERE order_date > CURRENT_DATE - 90
GROUP BY category_name;
```

Save to `results/explain_plans/obt_revenue_90d.txt`

### Plan Analysis Worksheet

For each plan, fill in this table:

| Metric | OLTP | Star Schema | OBT |
|--------|------|-------------|-----|
| Plan node count (approx.) | | | |
| Most expensive node | | | |
| # of joins | | | |
| Seq Scans on large tables? | | | |
| Row estimate accuracy (est vs actual) | | | |
| Total execution time | | | |
| Buffers hit (shared hit) | | | |

### What to Look for in Each Plan

**OLTP plan — common patterns:**
- `Hash Join` or `Merge Join` for each of the 4 JOINs
- `Seq Scan on orders` (large table, no index on `created_at`)
- High estimated cost from joining `order_items` (1M rows) to everything

**Star Schema plan — common patterns:**
- `Append` node (partition scan) — only the partitions matching `date_key` range are scanned
- Fewer rows in `fact_orders` child partitions than full OLTP `order_items`
- `Index Scan on dim_date` for the date filter

**OBT plan — common patterns:**
- Single `Seq Scan on obt_orders` or `Index Scan on idx_obt_created_at`
- No join nodes at all
- Aggregate directly on scan output

### Paste to explain.dalibo.com

Visual tree view makes the cost distribution immediately obvious. Save screenshots for the schema design doc.

### Done when
- [ ] 3 EXPLAIN plan files saved to `results/explain_plans/`
- [ ] Plan analysis worksheet completed
- [ ] Can articulate the #1 cost driver for each schema

---

## Day 6 — Indexing Strategies: B-tree, Hash, BRIN, GIN

**Goal:** Apply all four index types from the milestone to appropriate columns; measure before/after performance.

### Index Type Decision Guide

| Index Type | Best for | PostgreSQL syntax |
|-----------|---------|------------------|
| **B-tree** | Equality + range: `=`, `<`, `>`, `BETWEEN`, `IN`, `ORDER BY` | `USING btree` (default) |
| **Hash** | Equality-only: `=` on high-cardinality columns | `USING hash` |
| **BRIN** | Naturally ordered large tables (timestamps, sequential IDs) | `USING brin` |
| **GIN** | Full-text search, JSONB containment, arrays | `USING gin` |

### Apply and Measure

#### B-tree Indexes (most common)

```sql
-- OLTP: filter on orders.created_at (most impactful)
\timing on
-- Baseline (no index):
SELECT COUNT(*) FROM orders WHERE created_at > NOW() - INTERVAL '90 days';

CREATE INDEX idx_orders_created_at ON orders(created_at DESC);

-- After index:
SELECT COUNT(*) FROM orders WHERE created_at > NOW() - INTERVAL '90 days';

-- Composite: customer_id + status (for customer dashboard queries)
CREATE INDEX idx_orders_customer_status ON orders(customer_id, status);

-- Partial: only pending orders (small, targeted)
CREATE INDEX idx_orders_pending_created ON orders(created_at)
WHERE status = 'pending';

-- Covering: avoid heap fetch for order list queries
CREATE INDEX idx_order_items_cover ON order_items(order_id)
INCLUDE (product_id, qty, unit_price);
```

#### Hash Index (equality on high-cardinality column)

```sql
-- Email lookup — always exact match, never range
CREATE INDEX idx_customers_email_hash ON customers USING hash(email);

-- Compare lookup speed:
-- Without hash index:
EXPLAIN ANALYZE SELECT * FROM customers WHERE email = 'customer_42@example.com';

-- With hash index:
EXPLAIN ANALYZE SELECT * FROM customers WHERE email = 'customer_42@example.com';
```

> **Note:** B-tree also handles equality well. Hash is marginally faster for pure equality on very high-cardinality columns, but offers no advantage for range queries. Use it intentionally.

#### BRIN Index (time-series / naturally ordered data)

```sql
-- BRIN on created_at — tiny index, good for append-mostly time-series
CREATE INDEX idx_orders_brin ON orders USING brin(created_at)
WITH (pages_per_range = 128);

-- Compare size vs B-tree:
SELECT
  indexname,
  pg_size_pretty(pg_relation_size(indexname::regclass)) AS index_size
FROM pg_indexes
WHERE tablename = 'orders'
  AND indexname IN ('idx_orders_created_at', 'idx_orders_brin');

-- BRIN is orders of magnitude smaller, but less precise
-- (it tracks min/max per page range, not per row)
```

#### GIN Index (JSONB + full-text)

```sql
-- Add a JSONB metadata column to products for testing
ALTER TABLE products ADD COLUMN metadata JSONB;

UPDATE products SET metadata = jsonb_build_object(
  'tags', ARRAY['sale','featured','new'],
  'weight_kg', (random() * 5)::NUMERIC(4,2)
);

-- GIN index for JSONB containment queries
CREATE INDEX idx_products_metadata_gin ON products USING gin(metadata jsonb_path_ops);

-- Before/after:
EXPLAIN ANALYZE SELECT * FROM products WHERE metadata @> '{"tags": ["sale"]}';

-- Full-text search GIN (requires tsvector)
ALTER TABLE products ADD COLUMN name_tsv TSVECTOR
  GENERATED ALWAYS AS (to_tsvector('english', name)) STORED;

CREATE INDEX idx_products_fts ON products USING gin(name_tsv);

EXPLAIN ANALYZE
SELECT name FROM products WHERE name_tsv @@ to_tsquery('english', 'Product & 42');
```

### Document All Results in `results/timing.md`

```markdown
## Indexing Results

| Index Type | Table/Column | Query | Before (ms) | After (ms) | Improvement |
|-----------|-------------|-------|------------|-----------|-------------|
| B-tree | orders(created_at) | 90-day filter | XX | XX | XXx |
| Composite B-tree | orders(customer_id, status) | Customer dashboard | XX | XX | XXx |
| Partial B-tree | orders(created_at) WHERE pending | Pending orders | XX | XX | XXx |
| Hash | customers(email) | Email lookup | XX | XX | XXx |
| BRIN | orders(created_at) | Date range | XX | XX | XXx |
| GIN | products(metadata) | JSONB tag filter | XX | XX | XXx |
| GIN | products(name_tsv) | Full-text search | XX | XX | XXx |
```

### Done when
- [ ] All 4 index types created and tested
- [ ] Before/after timings recorded for each
- [ ] Index size comparison (BRIN vs B-tree) noted
- [ ] At least one query improved by >10x

---

## Day 7 — Schema Design Doc: Assemble the Deliverable

**Goal:** Compile everything from the week into a structured design document an FDE could present to a client.

### Create `schema-design-doc.md`

```
schema-design-doc/
├── schema-design-doc.md     ← main document
├── sql/
│   ├── oltp_schema.sql
│   ├── star_schema.sql
│   └── obt.sql
└── results/
    ├── timing.md
    └── explain_plans/
        ├── oltp_revenue_90d.txt
        ├── star_revenue_90d.txt
        └── obt_revenue_90d.txt
```

### `schema-design-doc.md` Structure

```markdown
# Schema Design Document: OLTP vs. OLAP Performance Comparison

## 1. Overview
[1-paragraph summary of the exercise and findings]

## 2. Schema Designs

### 2.1 OLTP — Third Normal Form (3NF)
[ERD description or ASCII diagram]
- Tables: categories → products → order_items ← orders → customers
- Strengths: write integrity, no duplication, referential constraints
- Weaknesses: analytical queries require 4+ JOINs

### 2.2 Star Schema
[ERD description: fact_orders at center, 3 dimension tables]
- Fact table grain: one row per order line item
- Partitioned by date_key (year)
- SCD Type 2 on dim_customer for segment history

### 2.3 One Big Table (OBT)
[Single wide table with all columns denormalized]
- Strengths: no JOINs, simple queries, ideal for columnar engines
- Weaknesses: data duplication, UPDATE fan-out cost

## 3. Benchmark Results

### Query 1: Revenue by Category (Last 90 Days)
| Schema | Execution Time | Plan Notes |
|--------|--------------|-----------|
| OLTP (3NF) | XX ms | 4 Hash Joins, Seq Scan on orders |
| Star Schema | XX ms | Partition pruning, 2 joins |
| OBT | XX ms | Single scan, no joins |

### Query 2: Top 10 Customers by Revenue (All Time)
[same table format]

### Query 3: Monthly Revenue Trend
[same table format]

## 4. Index Strategy per Schema

### OLTP Indexes
| Column | Index Type | Query Pattern | Improvement |
|--------|-----------|--------------|-------------|
| orders(created_at) | B-tree | Date range filter | XXx |
| customers(email) | Hash | Equality lookup | XXx |
| orders(created_at) WHERE status='pending' | Partial | Pending queue | XXx |

### Star Schema Indexes
| Column | Index Type | Notes |
|--------|-----------|-------|
| dim_date(full_date) | B-tree | Date range on dimension |
| fact_orders(date_key) | Partition | Year-level pruning (built-in) |

## 5. When to Use Each Schema — Decision Matrix

| Client Scenario | Recommended Schema | Rationale |
|----------------|-------------------|-----------|
| High write throughput, CRUD app | OLTP (3NF) | Write integrity, low redundancy |
| BI dashboards with complex slice-and-dice | Star Schema | Fast aggregations, SCD history |
| BigQuery / Snowflake analytics team, no JOINs | OBT | Columnar engine, bytes-scanned billing |
| Hybrid: CRUD + some reporting | OLTP + read replica + materialized views | Best of both |
| Real-time analytics with low latency | ClickHouse / DuckDB + OBT | Columnar + in-process |

## 6. Key Learnings
- [What surprised you about the star schema query plan?]
- [When would you recommend OLTP over star schema to a client?]
- [What is the risk of OBT in a row-store like PostgreSQL?]

## 7. Recommendation Template (FDE Field Use)

> "Given your workload of [X queries/day, Y write transactions/sec], I recommend [schema] because [reason].
> The trade-off is [constraint]. We should revisit if [trigger condition]."
```

### Morning: Checklist Review

- [ ] OLTP and Star Schema created in PostgreSQL?
- [ ] 1M+ orders populated?
- [ ] Same analytical queries run on all 3 schemas?
- [ ] EXPLAIN ANALYZE output saved for all 3?
- [ ] Index strategy documented with before/after timings?
- [ ] 1-page recommendation written?

### Self-Debrief Questions

Write answers in your notes:
1. A client has a BigQuery warehouse with a star schema. Their data team complains joins are slow. Do you move to OBT or add clustering keys?
2. What is the practical difference between a snowflake schema and a star schema? When does the extra normalization of snowflake actually help?
3. A customer dimension has 10M rows and customers frequently change their `segment`. What SCD type do you use and why?

### Done when
- [ ] All 6 checklist items checked
- [ ] `schema-design-doc.md` committed with all supporting files
- [ ] Decision matrix complete (at least 5 scenarios)
- [ ] Self-debrief written

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Kimball Group — Dimensional Modeling Techniques](https://www.kimballgroup.com/data-warehouse-business-intelligence-resources/kimball-techniques/dimensional-modeling-techniques/) |
| 2 | [PostgreSQL 3NF tutorial — postgresqltutorial.com](https://www.postgresqltutorial.com/) |
| 3 | [dbt Docs — How we structure our dbt projects](https://docs.getdbt.com/guides/best-practices/how-we-structure-our-dbt-projects) |
| 4 | [ClickHouse Docs — Data Modeling](https://clickhouse.com/docs) |
| 5 | [explain.dalibo.com](https://explain.dalibo.com) — visual plan tree |
| 6 | [Use The Index, Luke — Index Types](https://use-the-index-luke.com/sql/anatomy) |
| 7 | [Fivetran Blog — OLTP to OLAP migrations](https://www.fivetran.com/blog) |

---

*→ Next: [Week 4 — Milestone 04: Glue Architecture — Resilient HTTP Client](../milestones/04-glue-architecture-resilient-http.md)*
