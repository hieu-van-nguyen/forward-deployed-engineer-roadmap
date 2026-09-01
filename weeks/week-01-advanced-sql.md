# Week 1 — Advanced SQL: Day-by-Day Plan

> **Milestone:** [01 — Data Engineering: Advanced SQL](../milestones/01-data-engineering-advanced-sql.md)
> **Month:** M1 · **Weeks:** W1–W2 (this plan covers W1, Days 1–7)
> **Pacing note:** The milestone spans two weeks (W1–W2). This document covers the first week. Use W2 for the companion milestone [02 — CDC Pipelines](../milestones/02-data-pipelines-cdc.md) or to continue optimizing SQL work from W1 if you need extra reps.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Environment setup + 10M-row dataset | PostgreSQL running, `orders_bench` populated |
| 2 | Window functions — ranking & partitioning | Queries 01 & 02 written and explained |
| 3 | Window functions — time series & analytics | Query 03 (MoM revenue trend with LAG) written |
| 4 | Recursive CTEs | Query 04 (category tree) written, hierarchy traversal understood |
| 5 | EXPLAIN ANALYZE — reading query plans | 3 plans read, bottlenecks identified |
| 6 | Indexing & optimization | Before/after timing documented, 10x improvement achieved |
| 7 | Consolidation & benchmark report | `timing.md` + report structure complete, checklist done |

---

## Day 1 — Environment Setup & Data Generation

**Goal:** Get PostgreSQL running locally and generate the 10M-row benchmark dataset.

### Tasks

1. **Install PostgreSQL** (if not already installed)
   - macOS: `brew install postgresql@16` or use [Postgres.app](https://postgresapp.com/)
   - Docker alternative: `docker run -e POSTGRES_PASSWORD=dev -p 5432:5432 postgres:16`

2. **Install pgAdmin 4** — visual query plan explorer (download at pgadmin.org)

3. **Enable `pg_stat_statements`** — needed for Day 5
   ```sql
   -- In postgresql.conf add:
   shared_preload_libraries = 'pg_stat_statements'
   -- Then restart PostgreSQL and run:
   CREATE EXTENSION pg_stat_statements;
   ```

4. **Generate the benchmark dataset**
   ```sql
   CREATE TABLE orders_bench (
     id          BIGSERIAL PRIMARY KEY,
     customer_id INT,
     product_id  INT,
     region      VARCHAR(20),
     amount      NUMERIC(10,2),
     status      VARCHAR(20),
     created_at  TIMESTAMPTZ DEFAULT NOW()
   );

   INSERT INTO orders_bench (customer_id, product_id, region, amount, status, created_at)
   SELECT
     (random() * 100000)::INT,
     (random() * 5000)::INT,
     (ARRAY['North','South','East','West'])[ceil(random()*4)],
     (random() * 1000)::NUMERIC(10,2),
     (ARRAY['pending','shipped','delivered','cancelled'])[ceil(random()*4)],
     NOW() - (random() * 730)::INT * INTERVAL '1 day'
   FROM generate_series(1, 10000000);

   ANALYZE orders_bench;
   ```
   > ⏱ Expect ~2–5 minutes for the INSERT depending on hardware.

5. **Verify row count:** `SELECT COUNT(*) FROM orders_bench;` → should return 10,000,000

### Also create a supporting hierarchy table for Day 4
```sql
CREATE TABLE categories (
  id        SERIAL PRIMARY KEY,
  name      VARCHAR(100),
  parent_id INT REFERENCES categories(id)
);

INSERT INTO categories (id, name, parent_id) VALUES
  (1, 'All Products', NULL),
  (2, 'Electronics', 1),
  (3, 'Clothing', 1),
  (4, 'Laptops', 2),
  (5, 'Phones', 2),
  (6, 'Men', 3),
  (7, 'Women', 3),
  (8, 'Gaming Laptops', 4),
  (9, 'Ultrabooks', 4),
  (10, 'Smartphones', 5);
```

### Done when
- [ ] `orders_bench` has 10M rows
- [ ] pgAdmin connects to your local PostgreSQL
- [ ] `pg_stat_statements` extension is active

---

## Day 2 — Window Functions: Ranking & Partitioning

**Goal:** Understand `ROW_NUMBER`, `RANK`, `DENSE_RANK`, `NTILE`, and `PARTITION BY`.

### Concepts

Window functions do NOT collapse rows like `GROUP BY`. They add a computed column alongside the original rows. The `OVER()` clause defines the window.

```
FUNCTION() OVER (
  PARTITION BY <group columns>   -- reset window per group
  ORDER BY <sort column>         -- determines rank order within group
  ROWS/RANGE BETWEEN ...         -- optional frame specification
)
```

### Practice Queries

**ROW_NUMBER vs RANK vs DENSE_RANK — understand the gap behavior:**
```sql
SELECT
  customer_id,
  region,
  SUM(amount) AS total_revenue,
  ROW_NUMBER()  OVER (PARTITION BY region ORDER BY SUM(amount) DESC) AS row_num,
  RANK()        OVER (PARTITION BY region ORDER BY SUM(amount) DESC) AS rank,
  DENSE_RANK()  OVER (PARTITION BY region ORDER BY SUM(amount) DESC) AS dense_rank
FROM orders_bench
GROUP BY customer_id, region;
```
> Observe: when two customers tie, `RANK` skips a number; `DENSE_RANK` does not.

**NTILE — divide customers into revenue quartiles:**
```sql
SELECT
  customer_id,
  SUM(amount) AS total_revenue,
  NTILE(4) OVER (ORDER BY SUM(amount) DESC) AS revenue_quartile
FROM orders_bench
GROUP BY customer_id;
```

### 📝 Deliverable: `queries/02_top_customers_per_region.sql`
Write a query that returns the **top 5 customers by revenue per region** using `RANK()`.

```sql
-- Save this as queries/02_top_customers_per_region.sql
WITH ranked AS (
  SELECT
    customer_id,
    region,
    SUM(amount) AS total_revenue,
    RANK() OVER (PARTITION BY region ORDER BY SUM(amount) DESC) AS regional_rank
  FROM orders_bench
  GROUP BY customer_id, region
)
SELECT * FROM ranked WHERE regional_rank <= 5
ORDER BY region, regional_rank;
```

### Done when
- [ ] You can explain the difference between `RANK` and `DENSE_RANK` without looking it up
- [ ] `02_top_customers_per_region.sql` saved and running

---

## Day 3 — Window Functions: Time Series & LAG/LEAD

**Goal:** Master time-aware window functions: `LAG`, `LEAD`, `SUM OVER`, `AVG OVER` for trend analysis.

### Concepts

`LAG(col, n)` — access a value from n rows **before** the current row.
`LEAD(col, n)` — access a value from n rows **after** the current row.

These are critical for calculating month-over-month, week-over-week deltas.

### Practice Queries

**Running total of revenue per customer over time:**
```sql
SELECT
  customer_id,
  created_at::DATE AS order_date,
  amount,
  SUM(amount) OVER (
    PARTITION BY customer_id
    ORDER BY created_at
    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
  ) AS running_total
FROM orders_bench
ORDER BY customer_id, created_at
LIMIT 100;
```

**7-day moving average of revenue:**
```sql
SELECT
  created_at::DATE AS day,
  SUM(amount) AS daily_revenue,
  AVG(SUM(amount)) OVER (
    ORDER BY created_at::DATE
    ROWS BETWEEN 6 PRECEDING AND CURRENT ROW
  ) AS seven_day_avg
FROM orders_bench
GROUP BY created_at::DATE
ORDER BY day;
```

### 📝 Deliverable: `queries/01_window_revenue_trend.sql`
Month-over-month revenue growth using `LAG`:

```sql
-- Save as queries/01_window_revenue_trend.sql
WITH monthly AS (
  SELECT
    DATE_TRUNC('month', created_at) AS month,
    SUM(amount) AS revenue
  FROM orders_bench
  GROUP BY 1
),
with_lag AS (
  SELECT
    month,
    revenue,
    LAG(revenue) OVER (ORDER BY month) AS prev_month_revenue
  FROM monthly
)
SELECT
  month,
  revenue,
  prev_month_revenue,
  ROUND(
    100.0 * (revenue - prev_month_revenue) / NULLIF(prev_month_revenue, 0),
    2
  ) AS mom_growth_pct
FROM with_lag
ORDER BY month;
```

### Done when
- [ ] You understand the difference between `ROWS BETWEEN` and `RANGE BETWEEN` frames
- [ ] `01_window_revenue_trend.sql` returns MoM growth percentages
- [ ] You've read: [PostgreSQL Window Function Tutorial](https://www.postgresql.org/docs/current/tutorial-window.html)

---

## Day 4 — Recursive CTEs

**Goal:** Understand how recursive CTEs work, write a hierarchy traversal, and understand termination conditions.

### Concepts

A recursive CTE has two parts separated by `UNION ALL`:
1. **Anchor** — the base case (starting rows)
2. **Recursive member** — joins the CTE back to itself, adding one level per iteration

PostgreSQL stops when the recursive member returns zero rows.

```sql
WITH RECURSIVE cte_name AS (
  -- Anchor: starting point
  SELECT ...

  UNION ALL

  -- Recursive: join to previous results
  SELECT ... FROM table JOIN cte_name ON ...
)
SELECT * FROM cte_name;
```

> ⚠️ Always add a depth counter or cycle guard to prevent infinite loops on circular data.

### Practice: Org chart traversal
```sql
WITH RECURSIVE org_tree AS (
  -- Anchor: CEO (no manager)
  SELECT id, name, manager_id, 1 AS depth, name::TEXT AS path
  FROM employees
  WHERE manager_id IS NULL

  UNION ALL

  -- Recursive: each employee's reports
  SELECT e.id, e.name, e.manager_id, ot.depth + 1, ot.path || ' > ' || e.name
  FROM employees e
  INNER JOIN org_tree ot ON e.manager_id = ot.id
  WHERE ot.depth < 10  -- safety guard
)
SELECT depth, path FROM org_tree ORDER BY path;
```

### 📝 Deliverable: `queries/03_recursive_category_tree.sql`
Using the `categories` table from Day 1:

```sql
-- Save as queries/03_recursive_category_tree.sql
WITH RECURSIVE category_tree AS (
  -- Anchor: root category
  SELECT
    id,
    name,
    parent_id,
    1 AS depth,
    name::TEXT AS full_path
  FROM categories
  WHERE parent_id IS NULL

  UNION ALL

  -- Recursive: children
  SELECT
    c.id,
    c.name,
    c.parent_id,
    ct.depth + 1,
    ct.full_path || ' > ' || c.name
  FROM categories c
  INNER JOIN category_tree ct ON c.parent_id = ct.id
)
SELECT depth, full_path FROM category_tree ORDER BY full_path;
```

**Expected output shape:**
```
depth | full_path
------+-------------------------------------------
  1   | All Products
  2   | All Products > Electronics
  2   | All Products > Clothing
  3   | All Products > Electronics > Laptops
  3   | All Products > Electronics > Phones
  4   | All Products > Electronics > Laptops > Gaming Laptops
```

### Done when
- [ ] Can explain anchor + recursive member without notes
- [ ] `03_recursive_category_tree.sql` shows 3+ levels of nesting
- [ ] Added a depth guard to prevent infinite loops

---

## Day 5 — EXPLAIN ANALYZE: Reading Query Plans

**Goal:** Read and interpret PostgreSQL execution plans. Identify expensive nodes and understand row estimates vs actuals.

### How to Run

Always use `EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)` — not just `EXPLAIN`:
- `ANALYZE` — actually runs the query and shows real row counts and timing
- `BUFFERS` — shows cache hits vs disk reads

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT c.customer_id, COUNT(*) AS order_count
FROM orders_bench c
WHERE c.region = 'North'
  AND c.created_at > NOW() - INTERVAL '90 days'
GROUP BY c.customer_id
HAVING COUNT(*) > 3;
```

### Key Plan Nodes — What to Look For

| Node | 🚦 Signal | Action |
|------|----------|--------|
| `Seq Scan` on large table | 🔴 Bad | Add index or filter earlier |
| `Index Scan` | 🟢 Good | Normal |
| `Index Only Scan` | 🟢 Best | Covering index in use |
| `Hash Join` | 🟡 OK | Fine for medium sets |
| `Nested Loop` with large outer | 🔴 Bad | Check join order, add index |
| `Sort` | 🟡 Watch | Add index on ORDER BY column |
| `Bitmap Heap Scan` | 🟢 Usually OK | Multi-condition index use |

### What to Examine in Output

```
-> Seq Scan on orders_bench  (cost=0.00..312408.00 rows=2500000 width=46)
                              (actual time=0.018..2341.7 rows=2487321 loops=1)
```

- `cost=0.00..312408.00` — planner's estimated cost (startup..total)
- `rows=2500000` — **estimated** rows (from table statistics)
- `actual rows=2487321` — **real** rows returned (only with ANALYZE)
- Large gap between estimated and actual → stale statistics → run `ANALYZE tablename`

### Exercise: Analyze 3 queries
Run `EXPLAIN (ANALYZE, BUFFERS)` on:
1. `SELECT * FROM orders_bench WHERE region = 'North' LIMIT 100;`
2. Your `02_top_customers_per_region.sql` query
3. `SELECT * FROM orders_bench WHERE created_at > NOW() - INTERVAL '7 days';`

For each, answer:
- Is there a Seq Scan on a large table?
- What is the most expensive node (highest cost)?
- Are row estimates accurate?

### Paste plans to explain.dalibo.com
Visual tree view makes it much easier to spot the expensive nodes.

### Done when
- [ ] 3 EXPLAIN ANALYZE outputs saved to `results/explain_plans/`
- [ ] You can identify the most expensive node in each plan
- [ ] You understand what "rows estimate vs actual" gap means

---

## Day 6 — Indexing & Query Optimization

**Goal:** Create indexes that eliminate Seq Scans, measure before/after performance, and achieve >10x improvement on at least one query.

### Indexing Strategy

**B-tree index (default)** — equality and range queries:
```sql
CREATE INDEX idx_orders_region ON orders_bench(region);
CREATE INDEX idx_orders_created_at ON orders_bench(created_at DESC);
```

**Composite index** — when filtering on multiple columns together:
```sql
-- Filters WHERE region = 'X' AND status = 'Y'
CREATE INDEX idx_orders_region_status ON orders_bench(region, status);
-- Column order matters: most selective column first
```

**Partial index** — index only the rows you actually query:
```sql
-- Only index 'pending' orders (much smaller index)
CREATE INDEX idx_orders_pending ON orders_bench(created_at)
WHERE status = 'pending';
```

**Covering index** — include all columns the query needs (enables Index Only Scan):
```sql
CREATE INDEX idx_orders_covering ON orders_bench(region, created_at)
INCLUDE (amount, customer_id);
```

### Benchmark Workflow

```sql
-- 1. Time the baseline (no index)
\timing on
SELECT region, SUM(amount)
FROM orders_bench
WHERE created_at > NOW() - INTERVAL '30 days'
GROUP BY region;

-- 2. Create index
CREATE INDEX idx_orders_created_at ON orders_bench(created_at);

-- 3. Time again
SELECT region, SUM(amount)
FROM orders_bench
WHERE created_at > NOW() - INTERVAL '30 days'
GROUP BY region;

-- 4. Compare EXPLAIN ANALYZE before vs after
```

> Force PostgreSQL to use your index (for testing): `SET enable_seqscan = off;`
> Always reset after: `SET enable_seqscan = on;`

### 📝 Deliverables: `queries/04_running_inventory.sql` + `queries/05_percentile_analysis.sql`

**Inventory depletion (cumulative SUM):**
```sql
-- Save as queries/04_running_inventory.sql
-- Simulates stock events: positive = restock, negative = sale
WITH stock_events AS (
  SELECT
    product_id,
    created_at,
    CASE status
      WHEN 'delivered' THEN -1 * amount::INT
      WHEN 'cancelled' THEN  1 * amount::INT
      ELSE 0
    END AS stock_delta
  FROM orders_bench
  WHERE product_id = 42
)
SELECT
  created_at,
  stock_delta,
  SUM(stock_delta) OVER (ORDER BY created_at
    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
  ) AS running_stock_level
FROM stock_events
ORDER BY created_at;
```

**Percentile analysis:**
```sql
-- Save as queries/05_percentile_analysis.sql
SELECT
  product_id,
  COUNT(*)                                              AS order_count,
  ROUND(AVG(amount)::NUMERIC, 2)                        AS avg_amount,
  PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY amount)  AS p50_median,
  PERCENTILE_CONT(0.90) WITHIN GROUP (ORDER BY amount)  AS p90,
  PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY amount)  AS p99
FROM orders_bench
GROUP BY product_id
ORDER BY order_count DESC
LIMIT 20;
```

### Done when
- [ ] At least one query improved by >10x with an index
- [ ] Before/after timings recorded in `results/timing.md`
- [ ] `04_running_inventory.sql` and `05_percentile_analysis.sql` saved

---

## Day 7 — Consolidation & Benchmark Report

**Goal:** Assemble the deliverable, review the full checklist, and fill gaps.

### Morning: Review & Fill Gaps

Go through the milestone checklist:
- [ ] 5 window function queries written and run? (Queries 01, 02 cover this — review all 5 from the milestone)
- [ ] Recursive CTE traversing 3+ levels? (Query 03)
- [ ] 10M+ rows loaded? (Day 1)
- [ ] EXPLAIN ANALYZE on 3+ queries? (Day 5)
- [ ] Index reducing query time >10x? (Day 6)
- [ ] Before/after timings documented? (Day 6)

If any box is unchecked, use this morning to close it.

### Afternoon: Build the Benchmark Report

Create the deliverable structure:
```
benchmark-report/
├── setup.sql
├── queries/
│   ├── 01_window_revenue_trend.sql
│   ├── 02_top_customers_per_region.sql
│   ├── 03_recursive_category_tree.sql
│   ├── 04_running_inventory.sql
│   └── 05_percentile_analysis.sql
├── results/
│   ├── explain_plans/
│   │   ├── plan_01_no_index.txt
│   │   ├── plan_01_with_index.txt
│   │   └── plan_02_region_filter.txt
│   └── timing.md
└── README.md
```

### `results/timing.md` template

```markdown
# Benchmark Timing Results

## Query: Monthly Revenue Trend (01)
| Scenario | Execution Time | Plan Node |
|----------|---------------|-----------|
| No index | ~3,200 ms | Seq Scan |
| With index on created_at | ~210 ms | Index Scan |
| Improvement | **15x** | |

## Query: Top Customers per Region (02)
...
```

### Evening: Self-Debrief

Write 3–5 bullet points in your `README.md` under "Key Learnings":
- What surprised you about query plans?
- Which index type gave the most improvement?
- What would you do differently on a client's unknown schema?

### Done when
- [ ] All 6 checklist items from the milestone are checked
- [ ] `benchmark-report/` directory committed to git
- [ ] `results/timing.md` has at least 2 before/after comparisons
- [ ] `README.md` has Key Learnings section

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Postgres.app](https://postgresapp.com/) — easiest macOS install |
| 2–3 | [PostgreSQL Window Functions Docs](https://www.postgresql.org/docs/current/tutorial-window.html) |
| 4 | [PostgreSQL CTEs Docs](https://www.postgresql.org/docs/current/queries-with.html) |
| 5 | [explain.dalibo.com](https://explain.dalibo.com) — visual plan tree |
| 6 | [Use The Index, Luke](https://use-the-index-luke.com) — free, essential |
| 7 | [pganalyze Blog](https://pganalyze.com/blog) — production tuning |

---

*→ Next: [Week 2 covers Milestone 02 — CDC Pipelines](../milestones/02-data-pipelines-cdc.md)*
