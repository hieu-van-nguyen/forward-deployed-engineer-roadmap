# Milestone 15 — Telemetry & Tracing: OpenTelemetry for LLM Systems

| Field | Value |
|---|---|
| **Month** | M4 |
| **Weeks** | W15–W16 |
| **Priority** | P2 — High |
| **Domain** | Telemetry & Tracing |
| **Objective** | Implement OpenTelemetry / LangSmith / Phoenix for LLM trace logging, token counting, & cost tracking |
| **Key Deliverable** | Dashboard tracking latency, token usage, and cost per call |

**📅 Day-by-day plan:** [Week 15 Schedule](../weeks/week-15-telemetry-tracing-opentelemetry.md) (Days 1–7)

---

## Why This Matters for FDEs

Clients ask: "How much does this cost to run?" and "Why was that response slow?" Without observability, you have no answers. LLM tracing is also how you debug unexpected behavior post-deployment. FDEs must instrument their AI systems before going to production.

---

## Observability Stack

```
LLM Application
      │
      ▼ OTel SDK (traces, metrics, logs)
      │
      ├──▶ LangSmith (LLM-native traces, prompt playground)
      ├──▶ Arize Phoenix (local open-source option)
      └──▶ Grafana / Prometheus (infrastructure metrics)
```

---

## OpenTelemetry Setup

```python
# telemetry/setup.py
from opentelemetry import trace
from opentelemetry.sdk.trace import TracerProvider
from opentelemetry.sdk.trace.export import BatchSpanProcessor
from opentelemetry.exporter.otlp.proto.grpc.trace_exporter import OTLPSpanExporter
from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor
from opentelemetry.instrumentation.httpx import HTTPXClientInstrumentor
import os

def setup_telemetry(service_name: str = "rag-api"):
    provider = TracerProvider(
        resource=Resource.create({
            "service.name": service_name,
            "service.version": os.getenv("APP_VERSION", "dev"),
            "deployment.environment": os.getenv("ENV", "development"),
        })
    )
    # Export to Grafana Tempo / Jaeger / OTLP endpoint
    exporter = OTLPSpanExporter(
        endpoint=os.getenv("OTEL_EXPORTER_OTLP_ENDPOINT", "http://localhost:4317"),
    )
    provider.add_span_processor(BatchSpanProcessor(exporter))
    trace.set_tracer_provider(provider)

    # Auto-instrument FastAPI and HTTPX
    FastAPIInstrumentor().instrument()
    HTTPXClientInstrumentor().instrument()

    return trace.get_tracer(service_name)
```

---

## LLM-Specific Tracing

```python
# telemetry/llm_tracer.py
from opentelemetry import trace
from opentelemetry.trace import Status, StatusCode
import time
from typing import Optional
from dataclasses import dataclass

tracer = trace.get_tracer("llm-tracer")

@dataclass
class LLMCallResult:
    content: str
    prompt_tokens: int
    completion_tokens: int
    total_tokens: int
    latency_ms: float
    model: str
    cost_usd: float

# Token cost table (update as pricing changes)
COST_PER_1K_TOKENS = {
    "gpt-4o": {"input": 0.0025, "output": 0.010},
    "gpt-4o-mini": {"input": 0.00015, "output": 0.0006},
    "claude-3-5-sonnet-20241022": {"input": 0.003, "output": 0.015},
}

def compute_cost(model: str, prompt_tokens: int, completion_tokens: int) -> float:
    rates = COST_PER_1K_TOKENS.get(model, {"input": 0, "output": 0})
    return (
        (prompt_tokens / 1000) * rates["input"] +
        (completion_tokens / 1000) * rates["output"]
    )

def traced_llm_call(
    client,
    model: str,
    messages: list,
    operation_name: str = "llm.chat",
    **kwargs,
) -> LLMCallResult:
    with tracer.start_as_current_span(operation_name) as span:
        span.set_attribute("llm.model", model)
        span.set_attribute("llm.message_count", len(messages))
        span.set_attribute("llm.operation", operation_name)

        # Capture prompt
        system_msg = next((m["content"] for m in messages if m["role"] == "system"), "")
        user_msg = next((m["content"] for m in messages if m["role"] == "user"), "")
        span.set_attribute("llm.prompt.system", system_msg[:500])
        span.set_attribute("llm.prompt.user", user_msg[:500])

        start = time.perf_counter()
        try:
            response = client.chat.completions.create(
                model=model, messages=messages, **kwargs
            )
            latency_ms = (time.perf_counter() - start) * 1000

            usage = response.usage
            cost = compute_cost(model, usage.prompt_tokens, usage.completion_tokens)
            content = response.choices[0].message.content

            # Add semantic conventions
            span.set_attribute("llm.usage.prompt_tokens", usage.prompt_tokens)
            span.set_attribute("llm.usage.completion_tokens", usage.completion_tokens)
            span.set_attribute("llm.usage.total_tokens", usage.total_tokens)
            span.set_attribute("llm.latency_ms", round(latency_ms, 2))
            span.set_attribute("llm.cost_usd", round(cost, 6))
            span.set_attribute("llm.response.length", len(content))
            span.set_status(Status(StatusCode.OK))

            return LLMCallResult(
                content=content,
                prompt_tokens=usage.prompt_tokens,
                completion_tokens=usage.completion_tokens,
                total_tokens=usage.total_tokens,
                latency_ms=latency_ms,
                model=model,
                cost_usd=cost,
            )

        except Exception as e:
            span.record_exception(e)
            span.set_status(Status(StatusCode.ERROR, str(e)))
            raise
```

---

## LangSmith Integration

```python
# telemetry/langsmith_setup.py
import os
from langsmith import Client

os.environ["LANGCHAIN_TRACING_V2"] = "true"
os.environ["LANGCHAIN_API_KEY"] = "ls__your-api-key"
os.environ["LANGCHAIN_PROJECT"] = "rag-production"

# With LangChain — auto-traced when env vars set
from langchain_openai import ChatOpenAI
from langchain_core.prompts import ChatPromptTemplate

llm = ChatOpenAI(model="gpt-4o", temperature=0)
prompt = ChatPromptTemplate.from_template("Answer: {question}")
chain = prompt | llm

# This call is automatically traced in LangSmith
response = chain.invoke({"question": "What is our return policy?"})
```

### Adding Custom Metadata to LangSmith Traces

```python
from langsmith import traceable

@traceable(
    run_type="chain",
    name="HybridRAGPipeline",
    metadata={"pipeline_version": "2.1", "retriever": "hybrid"},
)
def rag_query(question: str) -> dict:
    chunks = retrieve(question)
    answer = generate(question, chunks)
    return {"answer": answer, "sources": [c.metadata for c in chunks]}
```

---

## Arize Phoenix (Local Open-Source)

```python
# telemetry/phoenix_setup.py
import phoenix as px
from phoenix.otel import register

# Launch Phoenix UI locally
session = px.launch_app()
print(f"Phoenix UI: {session.url}")

# Register as OTEL tracer
tracer_provider = register(
    project_name="rag-dev",
    endpoint="http://localhost:6006/v1/traces",
)

# Auto-instrument OpenAI
from openinference.instrumentation.openai import OpenAIInstrumentor
OpenAIInstrumentor().instrument(tracer_provider=tracer_provider)

# Now all OpenAI calls are traced in Phoenix
from openai import OpenAI
client = OpenAI()
response = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "Hello"}],
)
```

---

## Prometheus Metrics

```python
# telemetry/metrics.py
from prometheus_client import Counter, Histogram, Gauge, start_http_server

# Define metrics
llm_requests_total = Counter(
    "llm_requests_total",
    "Total LLM API calls",
    ["model", "operation", "status"],
)
llm_latency_seconds = Histogram(
    "llm_latency_seconds",
    "LLM call latency",
    ["model", "operation"],
    buckets=[0.1, 0.5, 1, 2, 5, 10, 30, 60],
)
llm_tokens_total = Counter(
    "llm_tokens_total",
    "Total tokens consumed",
    ["model", "token_type"],  # token_type: input/output
)
llm_cost_usd_total = Counter(
    "llm_cost_usd_total",
    "Total LLM cost in USD",
    ["model"],
)
rag_retrieval_latency = Histogram(
    "rag_retrieval_latency_seconds",
    "RAG retrieval step latency",
    ["retriever_type"],  # dense/sparse/reranker
)

# Start metrics server
start_http_server(9090)
```

---

## Grafana Dashboard Panels

Configure these panels in Grafana (connect to Prometheus):

| Panel | Query |
|-------|-------|
| Total cost today | `sum(increase(llm_cost_usd_total[1d]))` |
| p95 LLM latency | `histogram_quantile(0.95, rate(llm_latency_seconds_bucket[5m]))` |
| Requests per minute | `rate(llm_requests_total[1m]) * 60` |
| Token usage (input vs output) | `rate(llm_tokens_total[1m]) by (token_type)` |
| Error rate | `rate(llm_requests_total{status="error"}[5m]) / rate(llm_requests_total[5m])` |
| Cost by model | `increase(llm_cost_usd_total[1d]) by (model)` |

---

## Checklist

- [ ] OpenTelemetry SDK initialized with OTLP exporter
- [ ] `traced_llm_call()` wrapping all LLM API calls
- [ ] Token counting and cost computation per call
- [ ] LangSmith traces visible in LangSmith UI (or Phoenix locally)
- [ ] Prometheus metrics exposed on `/metrics` endpoint
- [ ] Grafana dashboard with cost, latency, token, and error panels
- [ ] Alert configured: error rate > 5% or p95 latency > 10s
- [ ] Demo: make 10 queries, show dashboard updating in real time

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Distributed Systems Observability* | Cindy Sridharan | Definitive guide to the three pillars: logs, metrics, and traces — required reading before production instrumentation |
| *Observability Engineering* | Charity Majors, Liz Fong-Jones & George Miranda | Modern observability philosophy and implementation — explains why tracing beats logging for complex systems |
| *The Site Reliability Workbook* | Google SRE Team | SLOs, error budgets, and observability in large-scale production — applies directly to FDE client deployments |
| *AI Engineering* | Chip Huyen | LLM monitoring, cost tracking, and evaluation in production — token-level observability patterns |
| *Database Reliability Engineering* | Laine Campbell & Charity Majors | Monitoring and observability for data infrastructure — applicable to database-heavy AI stacks |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| OpenTelemetry Documentation | [opentelemetry.io/docs](https://opentelemetry.io/docs/) | Official OTel docs — SDK setup, instrumentation, and exporter configuration for Python |
| LangSmith Documentation | [docs.smith.langchain.com](https://docs.smith.langchain.com) | Tracing, evaluation, and dataset management for LangChain applications |
| Arize Phoenix | [docs.arize.com/phoenix](https://docs.arize.com/phoenix) | Open-source LLM observability — traces, embeddings, and evals in a local UI |
| OpenTelemetry Python Contrib | [github.com/open-telemetry/opentelemetry-python-contrib](https://github.com/open-telemetry/opentelemetry-python-contrib) | Auto-instrumentation for FastAPI, SQLAlchemy, httpx, Redis and other common libraries |
| Prometheus Documentation | [prometheus.io/docs](https://prometheus.io/docs/introduction/overview/) | Metrics collection, PromQL queries, and alerting rules |
| Grafana Dashboards | [grafana.com/grafana/dashboards](https://grafana.com/grafana/dashboards/) | Pre-built dashboards for LLM cost, latency, and error rate monitoring |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *LLMOps* | DeepLearning.AI (free) | MLflow tracking, deployment, and monitoring for LLM applications |
| *Evaluating and Debugging Generative AI* | DeepLearning.AI / W&B (free) | Weights & Biases tracing, experiment tracking, and debugging for LLM systems |
| *Cloud-Native Monitoring with Prometheus* | Linux Foundation / edX | Prometheus setup, exporters, PromQL, and alerting — directly applicable to AI service monitoring |
