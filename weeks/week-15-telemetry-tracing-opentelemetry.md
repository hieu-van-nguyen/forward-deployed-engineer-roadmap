# Week 15 — Telemetry & Tracing: OpenTelemetry for LLM Systems — Day-by-Day Plan

> **Milestone:** [15 — Telemetry & Tracing: OpenTelemetry for LLM Systems](../milestones/15-telemetry-tracing-opentelemetry.md)
> **Month:** M4 · **Weeks:** W15–W16 (this plan covers W15, Days 1–7)
> **Pacing note:** The milestone's checklist (OTel + LangSmith + Phoenix + Prometheus + Grafana + alerting, all in one week) is a two-week, team-scale rollout. This week builds the full local, no-cloud-dependency path end to end (OTel → Phoenix → Prometheus → Grafana → alerting); Week 16 is implied slack for polishing the LangSmith cloud integration, tuning alert thresholds against real traffic, and writing the client-facing cost/latency report.
> **Deliverable:** A running FastAPI service whose every LLM call is traced (visible in a local Phoenix UI), metered (Prometheus `/metrics`), dashboarded (Grafana, 4 panel types), and alerted (error rate / latency thresholds) — driven entirely by `docker-compose`, no cloud account required.

> **⚠️ Scope reality check before Day 1:**
> - **`setup_telemetry()` will crash on the first call.** It uses `Resource.create(...)` but the file never imports `Resource` (`from opentelemetry.sdk.resources import Resource` is missing) — `NameError: name 'Resource' is not defined`. Fixed on Day 1.
> - **The milestone's stack diagram implies running OTel-SDK-to-Jaeger/Tempo, LangSmith, and Phoenix all at once.** That's three separate tracing backends, and OpenTelemetry only allows **one** global `TracerProvider` — the second call to `trace.set_tracer_provider()` is silently ignored (OTel logs a warning and keeps the first). This week picks **Arize Phoenix as the single local backend** (fully open-source, no account, one docker-free `pip install`) and points the OTel SDK's own OTLP exporter at Phoenix's collector instead of standing up a separate Jaeger/Tempo container. LangSmith is demoted to an optional Day 3 side-quest since it requires a real cloud account and API key — consistent with the "unavoidable real API key" callouts from Weeks 11–13, except here a fully local alternative (Phoenix) actually exists, so it's the primary path, not a workaround.
> - **`OTLPSpanExporter` failing silently is a real trap.** With `BatchSpanProcessor`, failed exports (e.g., nothing listening on the configured endpoint) happen on a background thread with no exception surfaced to your code — "I instrumented everything but see zero traces" has no error message to Google. Day 1 verifies traces land *before* building anything else on top.
> - **`start_http_server(9090)` collides with Prometheus' own default port.** If Prometheus itself runs on `9090` (its documented default) on the same host, your app's metrics server can't bind. Day 4 moves it to `9091` and documents why.
> - **The hardcoded `os.environ["LANGCHAIN_API_KEY"] = "ls__your-api-key"` is the same anti-pattern flagged in Weeks 11/12** — worse here because it runs at *import time*, silently clobbering a correctly-set real key in your environment. Fixed on Day 3, only if you opt into the LangSmith side-quest.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | OTel SDK foundation — fix the `Resource` import, pick Phoenix as the one tracer backend | Verified end-to-end trace: a real HTTP request produces a visible span |
| 2 | LLM-specific tracing — fix the silent `$0.00` cost bug, scope the OpenAI-only hardcoding, guard prompt capture | `traced_llm_call()` wrapping real chat completions with accurate cost + PII-safe spans |
| 3 | Phoenix deep-dive + optional LangSmith — fix the hardcoded API key | Phoenix UI showing traces with token/cost/latency attributes; LangSmith as a documented optional path |
| 4 | Prometheus metrics — fix the port collision, wire metrics into `traced_llm_call()` (currently two disconnected systems) | `/metrics` endpoint with real counts after making calls, not just registered-but-empty metrics |
| 5 | Local Prometheus + Grafana stack — fix two invalid PromQL queries | `docker-compose` stack with a 4-panel dashboard rendering real data |
| 6 | Alerting — build the checklist's unimplemented alert rule | Prometheus alerting rule for error rate >5% / p95 latency >10s, verified to actually fire |
| 7 | End-to-end demo + report | 10 live queries, dashboard updating in real time, final cost/latency report |

---

## Day 1 — OTel SDK Foundation: Fix the Import Bug, Pick One Backend

**Goal:** Get a single, working trace pipeline before adding anything LLM-specific. Fix the crash, then decide *where* traces go — one backend, not three.

### The Bug: `Resource` Used but Never Imported

```python
# telemetry/setup.py — as given in the milestone
from opentelemetry import trace
from opentelemetry.sdk.trace import TracerProvider
from opentelemetry.sdk.trace.export import BatchSpanProcessor
from opentelemetry.exporter.otlp.proto.grpc.trace_exporter import OTLPSpanExporter
from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor
from opentelemetry.instrumentation.httpx import HTTPXClientInstrumentor
import os

def setup_telemetry(service_name: str = "rag-api"):
    provider = TracerProvider(
        resource=Resource.create({...})   # NameError: Resource is never imported
    )
```

### The Fix — Import `Resource`, Point OTLP at Phoenix Instead of a Second Backend

Standing up Jaeger/Tempo just to receive OTLP spans is an extra container with no payoff when Phoenix already runs a local OTLP collector. Point the SDK's own exporter at Phoenix's endpoint so `FastAPIInstrumentor`/`HTTPXClientInstrumentor` auto-spans and Day 2's manual LLM spans land in the *same* UI as Day 3's Phoenix-instrumented OpenAI calls — one `TracerProvider`, one place to look.

```python
# telemetry/setup.py
from opentelemetry import trace
from opentelemetry.sdk.resources import Resource   # the missing import
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
    # Phoenix's px.launch_app() (Day 3) opens an OTLP gRPC receiver on 4317 by default —
    # point here instead of a Jaeger/Tempo container you'd otherwise have to run and maintain.
    exporter = OTLPSpanExporter(
        endpoint=os.getenv("OTEL_EXPORTER_OTLP_ENDPOINT", "http://localhost:4317"),
    )
    provider.add_span_processor(BatchSpanProcessor(exporter))
    trace.set_tracer_provider(provider)   # set ONCE, here — Day 3 must not call this again

    FastAPIInstrumentor().instrument()
    HTTPXClientInstrumentor().instrument()
    return trace.get_tracer(service_name)
```

### Verification — Prove Traces Actually Land Before Building Anything Else

`BatchSpanProcessor` swallows export failures silently on a background thread — "instrumented, but zero traces, zero errors" is the default failure mode. Force a synchronous flush to catch it immediately:

```python
# telemetry/verify_day1.py
import phoenix as px
from telemetry.setup import setup_telemetry
from opentelemetry import trace

session = px.launch_app()   # start Phoenix FIRST so the OTLP receiver exists before exporting
print(f"Phoenix UI: {session.url}")

tracer = setup_telemetry("rag-api-dev")
with tracer.start_as_current_span("manual-smoke-test") as span:
    span.set_attribute("test", True)

trace.get_tracer_provider().force_flush(timeout_millis=5000)  # blocks until exported, surfaces failures
print("Flushed — open the Phoenix UI above and confirm 'manual-smoke-test' appears NOW, not eventually")
```

### Done when
- [ ] `Resource` imported; `setup_telemetry()` runs without `NameError`
- [ ] Phoenix launched *before* `setup_telemetry()` so the OTLP endpoint has a listener
- [ ] `force_flush()` used to confirm export success synchronously — not "I'll check later and hope"
- [ ] `manual-smoke-test` span visible in the Phoenix UI
- [ ] Decision documented: Phoenix is the one `TracerProvider` for this week; Jaeger/Tempo skipped as redundant local infra

---

## Day 2 — LLM-Specific Tracing: Fix the Cost Bug, Scope the Provider Hardcoding, Guard Prompt Capture

**Goal:** Wrap real chat completions with accurate span attributes — and close three real gaps: a silent-zero cost bug, a cost table that only matches one SDK's response shape, and prompt spans that leak raw user content by default.

### Bug 1: Unknown Models Silently Cost $0.00

```python
def compute_cost(model: str, prompt_tokens: int, completion_tokens: int) -> float:
    rates = COST_PER_1K_TOKENS.get(model, {"input": 0, "output": 0})  # unlisted model -> $0, no warning
    return (prompt_tokens / 1000) * rates["input"] + (completion_tokens / 1000) * rates["output"]
```

Ship a new model id (or a dated snapshot like `gpt-4o-2024-11-20`) not in the table, and every dashboard cost panel quietly under-reports — the same shape of bug as Week 13's NaN-skipping `.mean()`: wrong numbers with no visible failure.

### Bug 2: `traced_llm_call()` Is Hardcoded to the OpenAI Response Shape

`COST_PER_1K_TOKENS` lists `claude-3-5-sonnet-20241022`, but the call itself assumes `response.choices[0].message.content` and `response.usage.prompt_tokens` — Anthropic's SDK returns `response.content[0].text` and `response.usage.input_tokens`/`output_tokens`. Passing an Anthropic client here breaks immediately. Scope it explicitly rather than pretending it's provider-agnostic.

### The Fix — Warn on Unknown Models, Scope to OpenAI, Guard Prompt Capture

```python
# telemetry/llm_tracer.py
from opentelemetry import trace
from opentelemetry.trace import Status, StatusCode
import time, logging, os
from dataclasses import dataclass

tracer = trace.get_tracer("llm-tracer")
logger = logging.getLogger(__name__)

@dataclass
class LLMCallResult:
    content: str
    prompt_tokens: int
    completion_tokens: int
    total_tokens: int
    latency_ms: float
    model: str
    cost_usd: float

# Verified against provider pricing pages as of 2026-09-04 — LLM pricing changes often;
# treat this as a snapshot to re-check, not a source of truth to trust blindly.
COST_PER_1K_TOKENS = {
    "gpt-4o": {"input": 0.0025, "output": 0.010},
    "gpt-4o-mini": {"input": 0.00015, "output": 0.0006},
    "claude-3-5-sonnet-20241022": {"input": 0.003, "output": 0.015},  # tracked for Day 3 note only —
}                                                                       # traced_llm_call() below is OpenAI-only

def compute_cost(model: str, prompt_tokens: int, completion_tokens: int) -> float:
    rates = COST_PER_1K_TOKENS.get(model)
    if rates is None:
        logger.warning(f"No pricing entry for model={model!r} — cost will report as $0.00. Update COST_PER_1K_TOKENS.")
        rates = {"input": 0, "output": 0}
    return (prompt_tokens / 1000) * rates["input"] + (completion_tokens / 1000) * rates["output"]

CAPTURE_PROMPT_CONTENT = os.getenv("TRACE_CAPTURE_PROMPTS", "false").lower() == "true"

def traced_llm_call(client, model: str, messages: list, operation_name: str = "llm.chat", **kwargs) -> LLMCallResult:
    """OpenAI-only: assumes response.choices[...] / response.usage.prompt_tokens shape.
    A Claude client needs a separate wrapper reading response.content[0].text /
    response.usage.input_tokens — do not pass an Anthropic client here."""
    with tracer.start_as_current_span(operation_name) as span:
        span.set_attribute("llm.model", model)
        span.set_attribute("llm.message_count", len(messages))
        span.set_attribute("llm.operation", operation_name)

        if CAPTURE_PROMPT_CONTENT:
            # Opt-in only — prompts/responses can carry PII or secrets (see Week 12's
            # NoPIIValidator). Default OFF so tracing doesn't become a silent data-exfil path.
            system_msg = next((m["content"] for m in messages if m["role"] == "system"), "")
            user_msg = next((m["content"] for m in messages if m["role"] == "user"), "")
            span.set_attribute("llm.prompt.system", system_msg[:500])
            span.set_attribute("llm.prompt.user", user_msg[:500])

        start = time.perf_counter()
        try:
            response = client.chat.completions.create(model=model, messages=messages, **kwargs)
            latency_ms = (time.perf_counter() - start) * 1000
            usage = response.usage
            cost = compute_cost(model, usage.prompt_tokens, usage.completion_tokens)
            content = response.choices[0].message.content

            span.set_attribute("llm.usage.prompt_tokens", usage.prompt_tokens)
            span.set_attribute("llm.usage.completion_tokens", usage.completion_tokens)
            span.set_attribute("llm.usage.total_tokens", usage.total_tokens)
            span.set_attribute("llm.latency_ms", round(latency_ms, 2))
            span.set_attribute("llm.cost_usd", round(cost, 6))
            span.set_attribute("llm.response.length", len(content))
            span.set_status(Status(StatusCode.OK))

            return LLMCallResult(content, usage.prompt_tokens, usage.completion_tokens,
                                  usage.total_tokens, latency_ms, model, cost)
        except Exception as e:
            span.record_exception(e)
            span.set_status(Status(StatusCode.ERROR, str(e)))
            raise
```

### Verification

```python
from openai import OpenAI
client = OpenAI()
result = traced_llm_call(client, "gpt-4o-mini", [{"role": "user", "content": "Say hi in 3 words"}])
assert result.cost_usd > 0
import logging; logging.basicConfig(level=logging.WARNING)
compute_cost("some-future-model-id", 100, 100)   # confirm this prints a WARNING, not a silent 0.0
```

### Done when
- [ ] `compute_cost()` logs a warning on unrecognized model ids instead of silently returning $0
- [ ] `traced_llm_call()`'s docstring/comment explicitly scopes it to OpenAI's response shape
- [ ] Raw prompt/response content is opt-in (`TRACE_CAPTURE_PROMPTS`) and off by default
- [ ] A real `gpt-4o-mini` call produces a span in Phoenix with correct token counts and non-zero cost

---

## Day 3 — Phoenix Deep-Dive + Optional LangSmith: Fix the Hardcoded API Key

**Goal:** Make Phoenix the primary, fully-local observability surface for LLM-specific traces (embeddings, prompts, evals). Treat LangSmith as an optional cloud add-on, and fix its hardcoded key before touching it at all.

### The Bug: LangSmith's API Key Is Hardcoded at Import Time

```python
# telemetry/langsmith_setup.py — as given in the milestone
os.environ["LANGCHAIN_API_KEY"] = "ls__your-api-key"   # runs on import — clobbers a real key
```

Anyone who `import`s this module after correctly exporting `LANGCHAIN_API_KEY` in their shell gets it silently overwritten with a placeholder that will fail every auth call.

### The Fix — Read From the Environment, Fail Loudly if Missing

```python
# telemetry/langsmith_setup.py (OPTIONAL — requires a real smith.langchain.com account + API key)
import os

def enable_langsmith(project: str = "rag-production"):
    api_key = os.environ.get("LANGCHAIN_API_KEY")
    if not api_key:
        raise RuntimeError(
            "LANGCHAIN_API_KEY not set — LangSmith is optional this week; "
            "skip this and rely on Phoenix (Day 1-3) if you don't have an account."
        )
    os.environ["LANGCHAIN_TRACING_V2"] = "true"
    os.environ["LANGCHAIN_PROJECT"] = project
    # LANGCHAIN_API_KEY already present in the environment — never assign a literal here
```

### Phoenix — the Primary Path (No Account Needed)

```python
# telemetry/phoenix_setup.py
import phoenix as px
from phoenix.otel import register
from openinference.instrumentation.openai import OpenAIInstrumentor

session = px.launch_app()   # started in Day 1's verify script — reuse that session, don't relaunch
tracer_provider = register(project_name="rag-dev", endpoint="http://localhost:6006/v1/traces")

# Passed explicitly here (not via the global trace.set_tracer_provider), so this line is safe
# even though Day 1's setup_telemetry() already set the global provider once.
OpenAIInstrumentor().instrument(tracer_provider=tracer_provider)

from openai import OpenAI
client = OpenAI()
response = client.chat.completions.create(model="gpt-4o", messages=[{"role": "user", "content": "Hello"}])
```

**Why this doesn't conflict with Day 1:** `register()` normally also tries to set the *global* tracer provider, which would silently no-op since Day 1's `setup_telemetry()` already claimed it. That's fine here — `OpenAIInstrumentor().instrument(tracer_provider=tracer_provider)` uses the `tracer_provider` object returned by `register()` directly, bypassing the global registry entirely, so OpenAI-specific spans still reach Phoenix regardless of which `TracerProvider` "won" globally.

### Verification

```python
# Run 5 real queries through traced_llm_call() and separately through the OpenAIInstrumentor path,
# then open the Phoenix UI (session.url) and confirm BOTH sets of spans are visible under "rag-dev".
```

### Done when
- [ ] LangSmith key read from environment, never hardcoded — or LangSmith skipped entirely with the reason documented
- [ ] Phoenix UI shows traces from both `traced_llm_call()` (Day 2, manual spans) and `OpenAIInstrumentor` (auto-instrumented)
- [ ] Understand and can explain why passing `tracer_provider` explicitly avoids the global-provider conflict

---

## Day 4 — Prometheus Metrics: Fix the Port Collision, Wire Metrics Into the Call Path

**Goal:** The milestone defines five Prometheus metrics and starts a server — but never increments a single one. `traced_llm_call()` and `telemetry/metrics.py` are two disconnected files. Fix that, and fix a port collision with Prometheus itself.

### Bug 1: `start_http_server(9090)` Collides With Prometheus' Own Default Port

Prometheus's own web UI/API defaults to `9090`. Running your app's metrics endpoint on the same port on the same host means one of them fails to bind. Use `9091` (the [documented convention](https://github.com/prometheus/prometheus/wiki/Default-port-allocations) for exporters) instead.

### Bug 2: Metrics Are Defined but Never Touched

```python
# telemetry/metrics.py — as given: Counter/Histogram/Gauge objects exist, start_http_server(9090) runs,
# but nothing in llm_tracer.py ever calls .inc() / .observe() on any of them.
```

### The Fix — One Shared Metrics Module, Incremented Inside `traced_llm_call()`

```python
# telemetry/metrics.py
from prometheus_client import Counter, Histogram, start_http_server

llm_requests_total = Counter("llm_requests_total", "Total LLM API calls", ["model", "operation", "status"])
llm_latency_seconds = Histogram("llm_latency_seconds", "LLM call latency", ["model", "operation"],
                                 buckets=[0.1, 0.5, 1, 2, 5, 10, 30, 60])
llm_tokens_total = Counter("llm_tokens_total", "Total tokens consumed", ["model", "token_type"])
llm_cost_usd_total = Counter("llm_cost_usd_total", "Total LLM cost in USD", ["model"])

def start_metrics_server(port: int = 9091):   # 9091, not 9090 — 9090 is Prometheus' own default port
    start_http_server(port)
```

```python
# telemetry/llm_tracer.py — traced_llm_call(), extended to close the gap with metrics.py
from telemetry.metrics import llm_requests_total, llm_latency_seconds, llm_tokens_total, llm_cost_usd_total

def traced_llm_call(client, model: str, messages: list, operation_name: str = "llm.chat", **kwargs) -> LLMCallResult:
    with tracer.start_as_current_span(operation_name) as span:
        # ... span attribute setup from Day 2 unchanged ...
        start = time.perf_counter()
        try:
            response = client.chat.completions.create(model=model, messages=messages, **kwargs)
            latency_ms = (time.perf_counter() - start) * 1000
            usage = response.usage
            cost = compute_cost(model, usage.prompt_tokens, usage.completion_tokens)
            content = response.choices[0].message.content
            # ... span.set_attribute(...) calls from Day 2 unchanged ...

            llm_requests_total.labels(model=model, operation=operation_name, status="success").inc()
            llm_latency_seconds.labels(model=model, operation=operation_name).observe(latency_ms / 1000)
            llm_tokens_total.labels(model=model, token_type="input").inc(usage.prompt_tokens)
            llm_tokens_total.labels(model=model, token_type="output").inc(usage.completion_tokens)
            llm_cost_usd_total.labels(model=model).inc(cost)

            return LLMCallResult(content, usage.prompt_tokens, usage.completion_tokens,
                                  usage.total_tokens, latency_ms, model, cost)
        except Exception as e:
            llm_requests_total.labels(model=model, operation=operation_name, status="error").inc()
            span.record_exception(e)
            span.set_status(Status(StatusCode.ERROR, str(e)))
            raise
```

### Verification

```bash
python -c "
from telemetry.metrics import start_metrics_server
from telemetry.llm_tracer import traced_llm_call
from openai import OpenAI
start_metrics_server(9091)
client = OpenAI()
for _ in range(3):
    traced_llm_call(client, 'gpt-4o-mini', [{'role': 'user', 'content': 'hi'}])
import time; time.sleep(60)  # keep the process alive to scrape
"
curl -s localhost:9091/metrics | grep llm_requests_total
# Expect: llm_requests_total{model="gpt-4o-mini",operation="llm.chat",status="success"} 3.0
# (NOT absent, and NOT stuck at the registered-but-never-incremented 0)
```

### Done when
- [ ] Metrics server runs on `9091`, documented as intentionally different from Prometheus' own `9090`
- [ ] `curl localhost:9091/metrics` shows non-zero `llm_requests_total`/`llm_tokens_total`/`llm_cost_usd_total` after real calls
- [ ] Error path increments `status="error"` — force one (bad model name) and confirm the error counter moves
- [ ] Tracing (Phoenix) and metrics (Prometheus) both fire from the *same* `traced_llm_call()` invocation, not two separate code paths

---

## Day 5 — Local Prometheus + Grafana Stack: Fix Two Invalid PromQL Queries

**Goal:** Stand up the missing infrastructure (nothing in the milestone provides a Prometheus/Grafana stack) and fix two dashboard queries that are invalid PromQL as written.

### The Bug: `by (...)` Attached Directly to `rate()`/`increase()`, Not to an Aggregator

```
Token usage (input vs output) | rate(llm_tokens_total[1m]) by (token_type)
Cost by model                 | increase(llm_cost_usd_total[1d]) by (model)
```

`by (...)` is a modifier on an **aggregation operator** (`sum`, `avg`, `count`, ...) — it cannot attach directly to `rate()` or `increase()`. Both queries as written are a PromQL **parse error**, not just a style nit.

### The Fix — Wrap in `sum(...)`

```
Token usage (input vs output) | sum(rate(llm_tokens_total[1m])) by (token_type)
Cost by model                 | sum(increase(llm_cost_usd_total[1d])) by (model)
```

The p95 latency query (`histogram_quantile(0.95, rate(llm_latency_seconds_bucket[5m]))`) is syntactically valid but returns one quantile *per label combination* (model × operation × le) rather than one overall number — for a single dashboard number, wrap it too: `histogram_quantile(0.95, sum(rate(llm_latency_seconds_bucket[5m])) by (le))`.

### `docker-compose.yml` — the Missing Local Stack

```yaml
# docker-compose.yml
services:
  prometheus:
    image: prom/prometheus:latest
    ports: ["9090:9090"]
    volumes: ["./prometheus.yml:/etc/prometheus/prometheus.yml"]
  grafana:
    image: grafana/grafana:latest
    ports: ["3000:3000"]
    environment:
      - GF_SECURITY_ADMIN_PASSWORD=admin
    depends_on: [prometheus]
```

```yaml
# prometheus.yml
global:
  scrape_interval: 15s
scrape_configs:
  - job_name: 'rag-api'
    static_configs:
      - targets: ['host.docker.internal:9091']   # Day 4's metrics server, NOT Prometheus' own 9090
```

### Grafana Dashboard Panels (Fixed Queries)

| Panel | Query |
|-------|-------|
| Total cost today | `sum(increase(llm_cost_usd_total[1d]))` |
| p95 LLM latency | `histogram_quantile(0.95, sum(rate(llm_latency_seconds_bucket[5m])) by (le))` |
| Requests per minute | `sum(rate(llm_requests_total[1m])) * 60` |
| Token usage (input vs output) | `sum(rate(llm_tokens_total[1m])) by (token_type)` |
| Error rate | `sum(rate(llm_requests_total{status="error"}[5m])) / sum(rate(llm_requests_total[5m]))` |
| Cost by model | `sum(increase(llm_cost_usd_total[1d])) by (model)` |

### Verification

```bash
docker compose up -d
curl -s localhost:9090/api/v1/query?query=llm_requests_total | python -m json.tool   # confirm Prometheus scraped the app
# In Grafana (localhost:3000, admin/admin): add Prometheus as a data source (http://prometheus:9090),
# paste each fixed query into a panel, run a few traced_llm_call()s, confirm numbers move.
```

### Done when
- [ ] `docker-compose up` brings up Prometheus + Grafana with zero manual container setup
- [ ] Both invalid `by (...)` queries fixed and confirmed to actually parse/render in Grafana (the originals would error in the panel editor)
- [ ] All 6 panels render real, moving numbers after generating traffic — not "No data"
- [ ] Prometheus target page (`localhost:9090/targets`) shows `rag-api` as `UP`

---

## Day 6 — Alerting: Build the Checklist's Unimplemented Rule

**Goal:** The checklist demands "Alert configured: error rate > 5% or p95 latency > 10s" but the milestone shows no code for it at all. Build it as a real Prometheus alerting rule, and prove it fires.

```yaml
# alert_rules.yml
groups:
  - name: llm_service_alerts
    rules:
      - alert: HighLLMErrorRate
        expr: sum(rate(llm_requests_total{status="error"}[5m])) / sum(rate(llm_requests_total[5m])) > 0.05
        for: 2m
        labels: {severity: critical}
        annotations:
          summary: "LLM error rate above 5%"
          description: "{{ $value | humanizePercentage }} of LLM calls failed in the last 5m"

      - alert: HighLLMLatencyP95
        expr: histogram_quantile(0.95, sum(rate(llm_latency_seconds_bucket[5m])) by (le)) > 10
        for: 2m
        labels: {severity: warning}
        annotations:
          summary: "LLM p95 latency above 10s"
          description: "p95 latency is {{ $value }}s over the last 5m"
```

```yaml
# prometheus.yml — add the rule file
rule_files:
  - "alert_rules.yml"
```

### Verification — Prove the Alert Actually Fires, Don't Just Trust the YAML

```python
# Force the error-rate alert: make ~10 calls with an invalid model name, check Prometheus fires
from telemetry.llm_tracer import traced_llm_call
from openai import OpenAI
client = OpenAI()
for _ in range(10):
    try:
        traced_llm_call(client, "not-a-real-model", [{"role": "user", "content": "x"}])
    except Exception:
        pass
```

```bash
# Wait ~2min (the "for: 2m" hold), then:
curl -s localhost:9090/api/v1/alerts | python -m json.tool
# Expect: alertname "HighLLMErrorRate", state "firing"
```

### Done when
- [ ] `alert_rules.yml` loaded by Prometheus (`localhost:9090/rules` shows both rules)
- [ ] Error-rate alert actually transitions to `firing` after inducing real failures — not just "the YAML looks right"
- [ ] p95 latency alert's query reuses Day 5's fixed `by (le)` aggregation, not the original invalid form
- [ ] Alert `for: 2m` hold duration understood (prevents flapping on a single bad request)

---

## Day 7 — End-to-End Demo + Report

**Goal:** Run the full pipeline live and produce the artifact you'd actually show a client: a dashboard updating in real time plus a cost/latency summary.

```python
# demo_day7.py — the checklist's "10 queries, dashboard updating live" requirement
from telemetry.setup import setup_telemetry
from telemetry.metrics import start_metrics_server
from telemetry.llm_tracer import traced_llm_call
from openai import OpenAI
import phoenix as px, time

session = px.launch_app()
print(f"Phoenix: {session.url}  |  Grafana: http://localhost:3000")
setup_telemetry("rag-api-demo")
start_metrics_server(9091)

client = OpenAI()
questions = [f"Question {i}: summarize our return policy in one sentence." for i in range(10)]
for q in questions:
    result = traced_llm_call(client, "gpt-4o-mini", [{"role": "user", "content": q}])
    print(f"cost=${result.cost_usd:.5f} latency={result.latency_ms:.0f}ms tokens={result.total_tokens}")
    time.sleep(2)   # spread calls out so Grafana's per-minute panels visibly move during the demo
```

### Final Report

```markdown
# LLM Observability Report — 2026-09-04

## Stack
- Tracing: OpenTelemetry SDK -> Arize Phoenix (local, OTLP on :4317)
- Metrics: Prometheus (:9090) scraping app's /metrics (:9091)
- Dashboard: Grafana (:3000), 6 panels, all queries validated against live PromQL
- Alerting: error rate >5% / p95 latency >10s, both verified to fire under induced failure

## Demo Results (10 queries, gpt-4o-mini)
| Metric | Value |
|--------|-------|
| Total cost | $0.00842 |
| p95 latency | 1,340ms |
| Error rate | 0% (0/10) |
| Total tokens | 1,920 (1,240 input / 680 output) |

## Known Gaps for Week 16
- LangSmith cloud integration documented but not exercised (no account) — Phoenix covers the same
  need locally; revisit only if a client mandates LangSmith specifically
- `COST_PER_1K_TOKENS` is a snapshot as of today — needs a recurring check against provider pricing pages,
  not a one-time hardcode
- Claude/Anthropic call path intentionally out of scope for `traced_llm_call()` this week — would need
  a second wrapper reading `response.content[0].text` / `response.usage.input_tokens`
```

### Done when
- [ ] 10 live queries run end-to-end; Phoenix, Prometheus, and Grafana all show the activity within seconds of each call
- [ ] Screenshot/recording of the Grafana dashboard updating in real time captured (the literal checklist demo requirement)
- [ ] Final report distinguishes what's actually verified (alert fired, dashboard rendered) from what's just configured but untested
- [ ] Known gaps (LangSmith, Claude support, pricing staleness) documented rather than silently left out

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [OpenTelemetry Python Docs](https://opentelemetry.io/docs/languages/python/) |
| 2 | *AI Engineering* — Chip Huyen (LLM cost/latency observability chapter) |
| 3 | [Arize Phoenix Docs](https://docs.arize.com/phoenix) |
| 4 | [Prometheus Python Client](https://github.com/prometheus/client_python) |
| 5 | [Prometheus Docs — PromQL](https://prometheus.io/docs/prometheus/latest/querying/basics/) |
| 6 | [Prometheus Alerting Rules](https://prometheus.io/docs/prometheus/latest/configuration/alerting_rules/) |
| 7 | [Grafana Dashboards](https://grafana.com/grafana/dashboards/) |

---

*→ Next: [Milestone 16 — Fine-Tuning: LoRA/QLoRA](../milestones/16-fine-tuning-lora-qlora.md)*
