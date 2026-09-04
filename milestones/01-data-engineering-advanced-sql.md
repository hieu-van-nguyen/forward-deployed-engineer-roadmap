# Milestone 01 — Data Engineering: Advanced SQL

| Field | Value |
|---|---|
| **Month** | M1 |
| **Weeks** | W1–W2 |
| **Priority** | P1 — Critical |
| **Domain** | Data Engineering |
| **Objective** | Master Advanced SQL (Window functions, recursive CTEs, query plan optimization via EXPLAIN ANALYZE) |
| **Key Deliverable** | Optimized benchmark queries on 10M+ row dataset |

**📅 Day-by-day plan:** [Week 1 Schedule](../weeks/week-01-advanced-sql.md) (Days 1–7)

---

## Why This Matters for FDEs

Unlike SWEs who write SQL to fetch data for a known API contract, FDEs encounter raw, poorly documented client databases regularly. You must debug slow queries on-site, optimize on unknown schemas, and demo results in real time. Advanced SQL fluency is table stakes.

---

## Core Concepts to Master

### 1. Window Functions
Window functions perform calculations across a set of rows related to the current row — without collapsing them like `GROUP BY` does.

```sql
-- Running total of revenue per customer
SELECT
  customer_id,
  order_date,
  amount,
  SUM(amount) OVER (PARTITION BY customer_id ORDER BY order_date) AS running_total
FROM orders;

-- Rank customers by revenue in each region
SELECT
  customer_id,
  region,
  revenue,
  RANK() OVER (PARTITION BY region ORDER BY revenue DESC) AS regional_rank
FROM customers;
```

**Key window functions to know:**
- `ROW_NUMBER()`, `RANK()`, `DENSE_RANK()`
- `LAG()`, `LEAD()` — compare current row to previous/next
- `FIRST_VALUE()`, `LAST_VALUE()`, `NTH_VALUE()`
- `NTILE(n)` — divide rows into n buckets
- `SUM()`, `AVG()`, `COUNT()` with `OVER()`

### 2. Recursive CTEs
Common Table Expressions (CTEs) become recursive when they reference themselves — essential for hierarchical data like org charts, bill-of-materials, and graph traversal.

```sql
-- Traverse an org chart downward from a CEO
WITH RECURSIVE org_tree AS (
  -- Anchor: start with the root
  SELECT id, name, manager_id, 1 AS depth
  FROM employees
  WHERE manager_id IS NULL

  UNION ALL

  -- Recursive: join children to their parent
  SELECT e.id, e.name, e.manager_id, ot.depth + 1
  FROM employees e
  INNER JOIN org_tree ot ON e.manager_id = ot.id
)
SELECT * FROM org_tree ORDER BY depth, name;
```

**Common use cases:**
- Organizational hierarchies
- Bill of materials (BOM) explosions
- Graph pathfinding (shortest path queries)
- Folder/file tree traversal

### 3. EXPLAIN ANALYZE & Query Plan Optimization

```sql
-- Always run EXPLAIN ANALYZE, not just EXPLAIN
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT c.name, COUNT(o.id) AS order_count
FROM customers c
LEFT JOIN orders o ON c.id = o.customer_id
WHERE o.created_at > NOW() - INTERVAL '30 days'
GROUP BY c.name
HAVING COUNT(o.id) > 5;
```

**Reading the plan — key nodes:**
| Node | What it means |
|------|--------------|
| `Seq Scan` | Full table scan — investigate if table is large |
| `Index Scan` | Using an index — good |
| `Hash Join` | Hashing the smaller relation — OK for medium tables |
| `Nested Loop` | O(n×m) — bad if outer set is large |
| `Sort` | Expensive; consider indexes for ORDER BY columns |
| `Bitmap Heap Scan` | Multi-condition index scan — usually fine |

**Optimization workflow:**
1. Identify slow query with `pg_stat_statements`
2. Run `EXPLAIN ANALYZE` — look at actual vs estimated rows
3. Check for Seq Scans on large tables → add index
4. Check for high cost Sort nodes → partial index or covering index
5. Check for bad row estimates → run `ANALYZE tablename` to update statistics
6. Use `SET enable_seqscan = off` temporarily to force index and compare plans

---

## Benchmark Project Setup

### Generate a 10M+ Row Dataset
```sql
-- Create a synthetic orders table with 10M rows
CREATE TABLE orders_bench (
  id BIGSERIAL PRIMARY KEY,
  customer_id INT,
  product_id INT,
  region VARCHAR(20),
  amount NUMERIC(10,2),
  status VARCHAR(20),
  created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Insert 10 million rows
INSERT INTO orders_bench (customer_id, product_id, region, amount, status, created_at)
SELECT
  (random() * 100000)::INT,
  (random() * 5000)::INT,
  (ARRAY['North','South','East','West'])[ceil(random()*4)],
  (random() * 1000)::NUMERIC(10,2),
  (ARRAY['pending','shipped','delivered','cancelled'])[ceil(random()*4)],
  NOW() - (random() * 730)::INT * INTERVAL '1 day'
FROM generate_series(1, 10000000);

-- Update statistics
ANALYZE orders_bench;
```

### Benchmark Queries to Write
1. **Monthly revenue trend** using window functions with LAG for MoM growth %
2. **Top 5 customers per region** using `RANK()` and `PARTITION BY`
3. **Recursive product category tree** with depth-first path string
4. **Running inventory depletion** from stock events using cumulative SUM
5. **Percentile analysis** of order amounts per product using `PERCENTILE_CONT`

---

## Tools & Resources

| Tool | Purpose |
|------|---------|
| [pgAdmin 4](https://www.pgadmin.org/) | Visual query plan explorer |
| [explain.dalibo.com](https://explain.dalibo.com/) | Paste EXPLAIN output for visual tree |
| [pgbench](https://www.postgresql.org/docs/current/pgbench.html) | Built-in PostgreSQL benchmarking |
| [pg_stat_statements](https://www.postgresql.org/docs/current/pgstatstatements.html) | Track slowest queries in prod |

**Learning resources:**
- *Use The Index, Luke* — [use-the-index-luke.com](https://use-the-index-luke.com/) (free, essential)
- PostgreSQL official docs: Window Functions, CTEs
- *SQL Performance Explained* by Markus Winand

---

## Checklist

- [ ] Write and run 5 window function queries with `OVER(PARTITION BY ... ORDER BY ...)`
- [ ] Write a recursive CTE traversing at least 3 levels of hierarchy
- [ ] Load 10M+ rows into a local PostgreSQL instance
- [ ] Run `EXPLAIN ANALYZE` on at least 3 queries and interpret each plan node
- [ ] Add an index that reduces query time by >10x on at least one query
- [ ] Document before/after query times in your deliverable benchmark report

---

## Deliverable Format

```
benchmark-report/
├── setup.sql          # Schema + data generation
├── queries/
│   ├── 01_window_revenue_trend.sql
│   ├── 02_top_customers_per_region.sql
│   ├── 03_recursive_category_tree.sql
│   ├── 04_running_inventory.sql
│   └── 05_percentile_analysis.sql
├── results/
│   ├── explain_plans/  # Raw EXPLAIN ANALYZE output
│   └── timing.md       # Before/after index comparisons
└── README.md           # Summary of findings
```

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *SQL Performance Explained* | Markus Winand | The definitive guide to indexing and query optimization — covers B-tree, composite, and partial indexes with real benchmarks |
| *Use The Index, Luke* | Markus Winand | Free online companion; deep coverage of execution plans across PostgreSQL, MySQL, Oracle |
| *PostgreSQL: Up and Running* | Regina Obe & Leo Hsu | Comprehensive PostgreSQL reference covering window functions, CTEs, partitioning, and extensions |
| *Database Internals* | Alex Petrov | Storage engine internals, B-tree implementation, WAL — helps reason about EXPLAIN output |
| *High Performance MySQL* | Silvia Botros & Jeremy Tinley | Broad query optimization principles that translate across RDBMS systems |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Use The Index, Luke (free) | [use-the-index-luke.com](https://use-the-index-luke.com) | Best free resource for SQL index strategy and query plan interpretation |
| PostgreSQL Docs — Window Functions | [postgresql.org/docs](https://www.postgresql.org/docs/current/tutorial-window.html) | Primary source; covers all window function syntax and frame specifications |
| PostgreSQL Docs — CTEs | [postgresql.org/docs](https://www.postgresql.org/docs/current/queries-with.html) | Recursive CTE syntax, materialization hints, and optimization tips |
| explain.dalibo.com | [explain.dalibo.com](https://explain.dalibo.com) | Paste EXPLAIN output for a visual tree diagram — invaluable for plan analysis |
| pganalyze Blog | [pganalyze.com/blog](https://pganalyze.com/blog) | In-depth articles on PostgreSQL query tuning and statistics |
| Mode SQL Tutorial | [mode.com/sql-tutorial](https://mode.com/sql-tutorial/) | Window functions and advanced SQL with interactive exercises |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Advanced SQL for Data Scientists* | Coursera / UC Davis | Window functions, CTEs, analytical queries |
| *PostgreSQL for Everybody* | Coursera / U of Michigan | PostgreSQL fundamentals through advanced features |
| *Complete SQL + Databases Bootcamp* | Udemy (ZTM) | Hands-on SQL from basics to performance tuning |
