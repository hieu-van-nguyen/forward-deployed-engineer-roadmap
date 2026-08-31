# Milestone 03 — DB Internals: OLTP vs. OLAP Data Modeling

| Field | Value |
|---|---|
| **Month** | M1 |
| **Weeks** | W3–W4 |
| **Priority** | P2 — High |
| **Domain** | DB Internals |
| **Objective** | Study OLTP vs. OLAP data modeling (Star Schema, OBT) & indexing strategies |
| **Key Deliverable** | Schema design doc comparing OLTP vs OLAP performance |

---

## Why This Matters for FDEs

Clients ask FDEs to "make the dashboards faster" or "help us query our data." Before writing a single query, you need to recognize whether the underlying schema is optimized for analytics or transactions — and whether it needs to change. Getting this wrong wastes weeks of client time.

---

## OLTP vs. OLAP at a Glance

| Dimension | OLTP | OLAP |
|-----------|------|------|
| **Workload** | Many short read/write transactions | Few long analytical scans |
| **Schema style** | Normalized (3NF) | Denormalized (star/snowflake/OBT) |
| **Primary concern** | Write throughput, data integrity | Read throughput, query simplicity |
| **Row count per query** | Few rows (point lookups) | Millions of rows (aggregations) |
| **Engines** | PostgreSQL, MySQL, Oracle | Redshift, BigQuery, Snowflake, DuckDB |
| **Index strategy** | B-tree on PKs/FKs | Columnar, partitioning, clustering |

---

## OLTP: Normalized Schema (3NF)

```sql
-- Third Normal Form — minimize redundancy
CREATE TABLE customers (
  id SERIAL PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  name VARCHAR(255)
);

CREATE TABLE products (
  id SERIAL PRIMARY KEY,
  sku VARCHAR(50) UNIQUE,
  name VARCHAR(255),
  category_id INT REFERENCES categories(id),
  price NUMERIC(10,2)
);

CREATE TABLE orders (
  id BIGSERIAL PRIMARY KEY,
  customer_id INT REFERENCES customers(id),
  created_at TIMESTAMPTZ DEFAULT NOW(),
  status VARCHAR(20)
);

CREATE TABLE order_items (
  id BIGSERIAL PRIMARY KEY,
  order_id INT REFERENCES orders(id),
  product_id INT REFERENCES products(id),
  qty INT,
  unit_price NUMERIC(10,2)
);
```

**Pros:** No data anomalies, efficient writes, referential integrity  
**Cons:** Analytics queries require many JOINs → slow on large data

---

## OLAP: Star Schema

```sql
-- Fact table (wide, append-only)
CREATE TABLE fact_orders (
  order_id BIGINT,
  customer_key INT,
  product_key INT,
  date_key INT,
  region_key INT,
  quantity INT,
  unit_price NUMERIC(10,2),
  discount NUMERIC(5,4),
  gross_revenue NUMERIC(12,2),
  net_revenue NUMERIC(12,2)
)
PARTITION BY RANGE (date_key);  -- partition by month/year

-- Dimension tables (slowly changing)
CREATE TABLE dim_customer (
  customer_key SERIAL PRIMARY KEY,
  customer_id INT,         -- natural key
  name VARCHAR(255),
  email VARCHAR(255),
  segment VARCHAR(50),     -- e.g., 'enterprise', 'smb'
  country VARCHAR(50),
  -- SCD Type 2 fields:
  valid_from DATE,
  valid_to DATE,
  is_current BOOLEAN
);

CREATE TABLE dim_product (
  product_key SERIAL PRIMARY KEY,
  product_id INT,
  sku VARCHAR(50),
  name VARCHAR(255),
  category VARCHAR(100),
  subcategory VARCHAR(100),
  brand VARCHAR(100)
);

CREATE TABLE dim_date (
  date_key INT PRIMARY KEY,    -- YYYYMMDD integer
  full_date DATE,
  year INT,
  quarter INT,
  month INT,
  week INT,
  day_of_week VARCHAR(10),
  is_weekend BOOLEAN,
  is_holiday BOOLEAN
);
```

**Star schema query (fast!):**
```sql
SELECT
  d.year,
  d.quarter,
  dp.category,
  SUM(f.net_revenue) AS revenue,
  COUNT(DISTINCT f.customer_key) AS unique_customers
FROM fact_orders f
JOIN dim_date d ON f.date_key = d.date_key
JOIN dim_product dp ON f.product_key = dp.product_key
JOIN dim_customer dc ON f.customer_key = dc.customer_key
WHERE d.year = 2024
  AND dc.segment = 'enterprise'
GROUP BY d.year, d.quarter, dp.category
ORDER BY revenue DESC;
```

---

## OBT: One Big Table

Used in modern cloud data warehouses (BigQuery, Snowflake) where JOINs are expensive but columnar scans are cheap.

```sql
-- Denormalize everything into one wide table
CREATE TABLE obt_orders AS
SELECT
  o.id AS order_id,
  o.created_at,
  c.id AS customer_id,
  c.name AS customer_name,
  c.email,
  c.segment,
  c.country,
  oi.product_id,
  p.name AS product_name,
  p.category,
  cat.name AS category_name,
  oi.qty,
  oi.unit_price,
  oi.qty * oi.unit_price AS line_revenue
FROM orders o
JOIN customers c ON o.customer_id = c.id
JOIN order_items oi ON oi.order_id = o.id
JOIN products p ON oi.product_id = p.id
JOIN categories cat ON p.category_id = cat.id;
```

**When to use OBT:**
- BigQuery, Snowflake (columnar engines, charge per bytes scanned)
- Presto/Trino on S3 (Parquet files, columnar format)
- When the analytics team is SQL-only and JOINs are error-prone

---

## Indexing Strategies

### B-Tree Index (OLTP default)
```sql
-- Single column
CREATE INDEX idx_orders_customer_id ON orders(customer_id);

-- Composite (order matters — matches left-prefix queries)
CREATE INDEX idx_orders_customer_status ON orders(customer_id, status);

-- Partial index (only index a subset of rows)
CREATE INDEX idx_orders_pending ON orders(created_at)
WHERE status = 'pending';

-- Covering index (include columns to avoid heap fetch)
CREATE INDEX idx_orders_cover ON orders(customer_id)
INCLUDE (amount, status, created_at);
```

### Hash Index (equality-only lookups)
```sql
CREATE INDEX idx_customers_email_hash ON customers USING hash(email);
```

### BRIN Index (sorted, large tables — time-series)
```sql
-- Very small, efficient for naturally ordered data (timestamps)
CREATE INDEX idx_events_ts_brin ON events USING brin(created_at)
WITH (pages_per_range = 128);
```

### GIN Index (full-text, arrays, JSONB)
```sql
-- JSONB containment queries
CREATE INDEX idx_metadata_gin ON events USING gin(metadata jsonb_path_ops);

-- Full-text search
CREATE INDEX idx_products_fts ON products USING gin(to_tsvector('english', description));
```

---

## Performance Comparison: Setup

```sql
-- Test query: total revenue by category for last 90 days

-- On OLTP (normalized):
EXPLAIN ANALYZE
SELECT cat.name, SUM(oi.qty * oi.unit_price) AS revenue
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
JOIN products p ON oi.product_id = p.id
JOIN categories cat ON p.category_id = cat.id
WHERE o.created_at > NOW() - INTERVAL '90 days'
GROUP BY cat.name;
-- Expect: multiple Hash Joins, possible Seq Scans

-- On Star Schema (OLAP):
EXPLAIN ANALYZE
SELECT dp.category, SUM(f.net_revenue) AS revenue
FROM fact_orders f
JOIN dim_product dp ON f.product_key = dp.product_key
JOIN dim_date d ON f.date_key = d.date_key
WHERE d.full_date > CURRENT_DATE - 90
GROUP BY dp.category;
-- Expect: partition pruning, faster aggregation
```

---

## Deliverable: Schema Design Doc

Your document should include:
1. ERD for the OLTP normalized schema
2. ERD for the Star Schema equivalent
3. OBT query showing denormalized output
4. Benchmark results table:

| Query Type | OLTP (ms) | Star Schema (ms) | OBT (ms) |
|-----------|-----------|-----------------|---------|
| Revenue by category (90d) | ? | ? | ? |
| Top 10 customers (all time) | ? | ? | ? |
| Daily active users (30d) | ? | ? | ? |

5. Index strategy recommendations per schema
6. When to use each approach (decision matrix)

---

## Checklist

- [ ] Create both OLTP and Star Schema in PostgreSQL
- [ ] Populate with realistic test data (1M+ orders)
- [ ] Run the same analytical queries on both schemas
- [ ] Compare EXPLAIN ANALYZE output (cost, rows, time)
- [ ] Document index choices and their impact
- [ ] Write a 1-page recommendation: "Given client X workload, I recommend Y schema because..."

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *The Data Warehouse Toolkit* | Ralph Kimball & Margy Ross | The definitive reference for dimensional modeling — star schema, snowflake, SCD types, and OBT patterns |
| *Designing Data-Intensive Applications* | Martin Kleppmann | Chapters 3–4 cover storage engines, column stores, and OLAP vs OLTP trade-offs at a systems level |
| *Database Internals* | Alex Petrov | Covers B-tree vs LSM-tree storage, page layout, and indexing — directly explains what EXPLAIN ANALYZE reports |
| *Fundamentals of Data Engineering* | Joe Reis & Matt Housley | Chapter on data modeling and choosing the right storage paradigm for analytical workloads |
| *Agile Data Warehouse Design* | Lawrence Corr & Jim Stagnitto | Collaborative modeling approach — useful when building schemas with client data teams |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| dbt Documentation — Modeling Guide | [docs.getdbt.com/guides/best-practices](https://docs.getdbt.com/guides/best-practices/how-we-structure-our-dbt-projects) | Best practices for structuring OLAP models in dbt (staging → marts) |
| Kimball Group Design Tips | [kimballgroup.com/data-warehouse-business-intelligence-resources](https://www.kimballgroup.com/data-warehouse-business-intelligence-resources/kimball-techniques/dimensional-modeling-techniques/) | Free reference for every dimensional modeling technique |
| ClickHouse Docs — Data Modeling | [clickhouse.com/docs](https://clickhouse.com/docs/en/sql-reference/statements/create/table) | OLAP-native design patterns for columnar storage |
| PostgreSQL Docs — Partitioning | [postgresql.org/docs/current/ddl-partitioning.html](https://www.postgresql.org/docs/current/ddl-partitioning.html) | Partition pruning and strategies for large analytical tables |
| Fivetran Modern Data Stack Blog | [fivetran.com/blog](https://www.fivetran.com/blog) | Real-world OLTP → OLAP migration case studies |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Data Warehouse Fundamentals for Beginners* | Udemy | Star schema, dimension tables, fact tables, SCD types |
| *dbt Fundamentals* | dbt Learn (free) | Hands-on OLAP modeling with dbt on real data |
| *Data Engineering Zoomcamp* | DataTalks.Club (free) | End-to-end pipeline including data modeling in BigQuery/Redshift |
