# Milestone 09 — Vector Databases: HNSW, pgvector, Pinecone, Qdrant

| Field | Value |
|---|---|
| **Month** | M3 |
| **Weeks** | W9–W10 |
| **Priority** | P1 — Critical |
| **Domain** | Vector Databases |
| **Objective** | Master vector indexing (HNSW, IVFFlat) and implement pgvector / Pinecone / Qdrant search engines |
| **Key Deliverable** | Benchmark report comparing vector search latency & recall |

**📅 Day-by-day plan:** [Week 9 Schedule](../weeks/week-09-vector-databases.md) (Days 1–7)

---

## Why This Matters for FDEs

AI product demos almost always involve semantic search over documents, products, or customer records. Vector databases are the core infrastructure. FDEs must be able to choose the right vector store for a client's stack (PostgreSQL extension vs. dedicated DB), tune indexing parameters, and present a benchmark demonstrating viability.

---

## Core Concepts

### Embeddings
Dense numerical vectors that capture semantic meaning. Semantically similar items cluster close together in vector space.

```python
from sentence_transformers import SentenceTransformer

model = SentenceTransformer("all-MiniLM-L6-v2")  # 384-dim embeddings

texts = [
    "How do I reset my password?",
    "I forgot my login credentials",
    "What are your business hours?",
]
embeddings = model.encode(texts)
# Shape: (3, 384)
```

### Distance Metrics
| Metric | Formula | Use when |
|--------|---------|---------|
| Cosine similarity | 1 - cos(a,b) | NLP embeddings (direction matters) |
| Inner product | -a·b | When vectors are normalized |
| L2 (Euclidean) | √Σ(aᵢ-bᵢ)² | Image embeddings, physical distances |

---

## Indexing Algorithms

### HNSW (Hierarchical Navigable Small World)
- **Idea:** Build a multi-layer graph where each node connects to its closest neighbors; search from top (sparse) to bottom (dense) layer
- **Pros:** Excellent query speed (O(log n)), high recall
- **Cons:** High memory usage, slow index build time
- **Params:** `m` (connections per node, higher = better recall, more memory), `ef_construction` (build quality), `ef_search` (query quality)

### IVFFlat (Inverted File Index)
- **Idea:** Cluster vectors into `n_lists` partitions (k-means); at query time, search only `n_probes` nearest clusters
- **Pros:** Lower memory, good for large static datasets
- **Cons:** Lower recall than HNSW, requires training step
- **Params:** `n_lists` (√n_vectors is a good start), `n_probes` (more = better recall, slower)

| | HNSW | IVFFlat |
|---|------|---------|
| Memory | High | Low |
| Query speed | Very fast | Fast |
| Recall | High | Medium-High |
| Build time | Medium | Fast (needs training) |
| Updates | Easy | Rebuild needed |

---

## pgvector (PostgreSQL Extension)

```sql
-- Install extension
CREATE EXTENSION IF NOT EXISTS vector;

-- Create table with embedding column
CREATE TABLE documents (
  id BIGSERIAL PRIMARY KEY,
  content TEXT,
  source VARCHAR(255),
  embedding vector(384)  -- match your model's dimension
);

-- Create HNSW index
CREATE INDEX ON documents USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

-- (or IVFFlat)
CREATE INDEX ON documents USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);

-- Set ef_search at query time
SET hnsw.ef_search = 64;

-- Semantic search query
SELECT
  id,
  content,
  1 - (embedding <=> $1::vector) AS similarity
FROM documents
ORDER BY embedding <=> $1::vector
LIMIT 10;

-- Hybrid: combine with metadata filter (pre-filter)
SELECT id, content, 1 - (embedding <=> $1::vector) AS similarity
FROM documents
WHERE source = 'policy_docs'
ORDER BY embedding <=> $1::vector
LIMIT 10;
```

```python
# Insert embeddings
import psycopg2
from sentence_transformers import SentenceTransformer

model = SentenceTransformer("all-MiniLM-L6-v2")

conn = psycopg2.connect("postgresql://user:pass@localhost/db")
cur = conn.cursor()

texts = load_documents()  # Your documents
embeddings = model.encode(texts, batch_size=64, show_progress_bar=True)

cur.executemany(
    "INSERT INTO documents (content, embedding) VALUES (%s, %s)",
    [(text, emb.tolist()) for text, emb in zip(texts, embeddings)]
)
conn.commit()
```

---

## Qdrant (Dedicated Vector DB)

```python
from qdrant_client import QdrantClient
from qdrant_client.models import (
    Distance, VectorParams, PointStruct, HnswConfigDiff, Filter, FieldCondition, MatchValue
)

client = QdrantClient("localhost", port=6333)

# Create collection
client.create_collection(
    collection_name="documents",
    vectors_config=VectorParams(
        size=384,
        distance=Distance.COSINE,
    ),
    hnsw_config=HnswConfigDiff(m=16, ef_construct=100),
)

# Upsert points
points = [
    PointStruct(
        id=i,
        vector=embedding.tolist(),
        payload={"text": text, "source": "wiki", "category": "policy"},
    )
    for i, (text, embedding) in enumerate(zip(texts, embeddings))
]
client.upsert(collection_name="documents", points=points)

# Search with payload filter
results = client.search(
    collection_name="documents",
    query_vector=query_embedding.tolist(),
    query_filter=Filter(
        must=[FieldCondition(key="category", match=MatchValue(value="policy"))]
    ),
    limit=10,
    with_payload=True,
    score_threshold=0.7,
)
```

---

## Pinecone (Managed Cloud)

```python
from pinecone import Pinecone, ServerlessSpec

pc = Pinecone(api_key="your-api-key")

# Create index
pc.create_index(
    name="documents",
    dimension=384,
    metric="cosine",
    spec=ServerlessSpec(cloud="aws", region="us-east-1"),
)

index = pc.Index("documents")

# Upsert
index.upsert(
    vectors=[
        {
            "id": str(i),
            "values": embedding.tolist(),
            "metadata": {"text": text[:500], "source": source},
        }
        for i, (text, source, embedding) in enumerate(data)
    ],
    batch_size=100,
)

# Query
results = index.query(
    vector=query_embedding.tolist(),
    top_k=10,
    filter={"source": {"$eq": "policy_docs"}},
    include_metadata=True,
)
```

---

## Benchmark Setup

```python
# benchmark.py
import time
import numpy as np
from typing import List, Tuple

def benchmark_search(
    search_fn,
    queries: np.ndarray,
    ground_truth: List[List[int]],
    k: int = 10,
) -> dict:
    latencies = []
    recalls = []

    for query, gt in zip(queries, ground_truth):
        start = time.perf_counter()
        results = search_fn(query, k=k)
        latency = (time.perf_counter() - start) * 1000  # ms
        latencies.append(latency)

        # Recall@k: fraction of true neighbors found
        retrieved_ids = set(r.id for r in results)
        true_ids = set(gt[:k])
        recall = len(retrieved_ids & true_ids) / len(true_ids)
        recalls.append(recall)

    return {
        "p50_latency_ms": np.percentile(latencies, 50),
        "p95_latency_ms": np.percentile(latencies, 95),
        "p99_latency_ms": np.percentile(latencies, 99),
        "recall_at_k": np.mean(recalls),
        "throughput_qps": 1000 / np.mean(latencies),
    }
```

### Benchmark Results Table Template

| Engine | Index Type | Params | p50 (ms) | p95 (ms) | Recall@10 | Memory (GB) |
|--------|-----------|--------|----------|----------|-----------|-------------|
| pgvector | HNSW | m=16, ef=64 | ? | ? | ? | ? |
| pgvector | IVFFlat | lists=100, probes=10 | ? | ? | ? | ? |
| Qdrant | HNSW | m=16, ef=100 | ? | ? | ? | ? |
| Pinecone | Managed | Default | ? | ? | ? | N/A |

---

## Checklist

- [ ] pgvector installed and HNSW index created on 1M+ vectors
- [ ] pgvector IVFFlat index created and compared to HNSW
- [ ] Qdrant collection created with filtered search
- [ ] Pinecone index created (or mocked if no API key)
- [ ] Benchmark script measuring latency and recall@10
- [ ] Benchmark results documented in table
- [ ] Written analysis: which engine for which client scenario (on-prem vs. cloud, scale, filter requirements)

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Designing Machine Learning Systems* | Chip Huyen | Chapter on feature stores and embeddings covers vector representations and their role in ML pipelines |
| *Programming Machine Learning* | Paolo Perrotta | Builds intuition for how neural networks create vector representations — foundation for understanding embeddings |
| *Hands-On Machine Learning with Scikit-Learn, Keras, and TensorFlow* | Aurélien Géron | Embedding techniques, dimensionality, and distance metrics explained with code |
| *Vector: The Architecture of Modern AI* | Various authors | Emerging reference covering HNSW, ANN benchmarks, and production vector database patterns |
| *Natural Language Processing with Transformers* | Lewis Tunstall et al. | How transformer models generate embeddings — essential context for choosing embedding models |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| pgvector GitHub | [github.com/pgvector/pgvector](https://github.com/pgvector/pgvector) | Official pgvector docs — HNSW and IVFFlat index configuration and performance tips |
| Qdrant Documentation | [qdrant.tech/documentation](https://qdrant.tech/documentation/) | Complete Qdrant reference — filtering, payload indexing, and on-disk storage |
| ANN Benchmarks | [ann-benchmarks.com](https://ann-benchmarks.com) | Independent benchmarks of every major vector index algorithm — use to justify your choice |
| Pinecone Learning Center | [pinecone.io/learn](https://www.pinecone.io/learn/) | Excellent conceptual articles on vector search, HNSW, and ANN algorithms |
| Sentence Transformers Docs | [sbert.net](https://www.sbert.net) | Pretrained embedding models for semantic search — model selection guide |
| BEIR Benchmark | [github.com/beir-cellar/beir](https://github.com/beir-cellar/beir) | Standard retrieval benchmark — use to compare embedding models on your domain |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Vector Databases: from Embeddings to Applications* | DeepLearning.AI (free) | Short course by Weaviate — embeddings, vector search, and retrieval applications |
| *Building Semantic Search Systems* | Hugging Face (free) | Practical embedding models, similarity search, and FAISS integration |
| *LLM Engineering: Master AI and LLMs* | Udemy | Vector stores, RAG, and embedding models in the context of LLM applications |
