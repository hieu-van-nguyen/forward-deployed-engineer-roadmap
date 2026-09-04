# Week 4 — Glue Architecture: Resilient HTTP Client — Day-by-Day Plan

> **Milestone:** [04 — Glue Architecture: Resilient HTTP Client](../milestones/04-glue-architecture-resilient-http.md)
> **Month:** M1 · **Weeks:** W3–W4 (this plan covers W4, Days 1–7)
> **Pacing note:** The milestone spans W3–W4. This document covers W4. W3 is covered by [Milestone 03 — DB Internals: OLTP vs OLAP](../milestones/03-db-internals-oltp-olap.md).
> **Deliverable:** A runnable, importable Python package (`resilient_http/`) with passing tests, packaged via `pyproject.toml`.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Resilience patterns theory — retry, backoff, circuit breaker, timeout, bulkhead | Notes + retryable/non-retryable classification table |
| 2 | Base HTTP client — `RetryConfig`, backoff function, retry loop with error classification | `retry.py` + `client.py` skeleton |
| 3 | Circuit breaker — CLOSED/OPEN/HALF_OPEN state machine integrated into client | `circuit_breaker.py`, integrated into `client.py` |
| 4 | Middleware — request/response logging + 401 token-refresh wrapper | `middleware/logging.py`, `middleware/auth.py` |
| 5 | GraphQL support — 200-with-errors pattern, error classification, rate limit handling | `graphql.py` |
| 6 | Testing — unit tests with `respx` for retry + circuit breaker; integration test with mock server | `tests/` suite, all tests passing |
| 7 | Package + deliverable — `pyproject.toml`, README, Prometheus metrics hook, checklist | `resilient_http/` installable with `pip install -e .` |

---

## Day 1 — Resilience Patterns: Theory & Mental Model

**Goal:** Understand *why* each pattern exists, *when* to apply it, and *how* they compose. FDEs face flaky vendor APIs constantly — this vocabulary makes you dangerous in a client discovery call.

### Why Vendor APIs Fail

| Failure Mode | Example | Pattern that fixes it |
|-------------|---------|----------------------|
| Transient network error | TCP timeout on corporate VPN | **Retry with backoff** |
| Rate limiting | Salesforce 429 after burst | **Retry with `Retry-After` + backoff** |
| Cascading overload | Your retries flood an already-struggling service | **Jitter** (de-synchronizes clients) |
| Prolonged outage | Downstream payment API down 10 min | **Circuit breaker** (fail fast, stop hammering) |
| Slow API draining threads | ERP endpoint takes 90s per call | **Timeout** |
| One slow dependency starves everything | Single API pool blocking all requests | **Bulkhead** (separate thread/connection pools) |

### Exponential Backoff — Three Variants

```
Attempt:  0     1     2     3     4
Fixed:    1s    1s    1s    1s    1s     ← thundering herd
Exponential: 1s 2s    4s    8s    16s   ← still synchronized if all start together
Full jitter:  0–1s 0–2s 0–4s 0–8s 0–16s ← best: random between 0 and cap
```

**Full jitter formula:**
```python
wait = random.uniform(0, min(cap, base * 2**attempt))
```

AWS recommends full jitter for most cases. "Equal jitter" (`wait/2 + random(0, wait/2)`) is a reasonable second choice that avoids very short waits.

### Circuit Breaker — Three States

```
    ┌─────────────────────────────────────────────┐
    │                                             │
    ▼                                             │
 CLOSED ──(N consecutive failures)──▶ OPEN       │
    ▲                                   │         │
    │                         (recovery_timeout)  │
    │                                   ▼         │
    └──(success)────────── HALF_OPEN ───┘         │
                                │                 │
                          (failure)───────────────┘
```

| State | Behavior |
|-------|----------|
| **CLOSED** | Normal; requests flow through; failures counted |
| **OPEN** | All requests rejected immediately (fail fast); no network calls |
| **HALF_OPEN** | After `recovery_timeout`, one probe request is allowed to test recovery |

> **Why fail fast?** If a downstream API is down for 10 minutes, retrying every call for 10 minutes multiplies failures by your retry count × your request rate. The circuit breaker protects *your* system (thread pools, memory) and gives the downstream API space to recover.

### Retryable vs Non-Retryable — Memorize This

| HTTP Status | Retry? | Reason |
|------------|--------|--------|
| 429 | ✅ Yes — respect `Retry-After` | Rate limited; server tells you when to try again |
| 500 | ✅ Yes (capped) | May be transient (deploy, crash, GC pause) |
| 502 | ✅ Yes | Bad gateway — upstream probably recovering |
| 503 | ✅ Yes | Service unavailable — standard temporary failure |
| 504 | ✅ Yes | Gateway timeout — transient |
| 400 | ❌ No | Bad request — your payload is wrong; retrying won't help |
| 401 | ❌ No (from retry loop) | Auth failure — must refresh token *outside* the retry loop |
| 403 | ❌ No | Forbidden — permissions issue, not transient |
| 404 | ❌ No | Resource gone — retrying won't create it |
| 409 | ❌ No | Conflict — idempotency issue; inspect and fix |

> **401 note:** A 401 means "try again with a fresh token" — but the token refresh itself must happen in a *wrapper layer* before re-entering the retry loop. More on this in Day 4.

### Study Tasks

- Read: [Martin Fowler — Circuit Breaker](https://martinfowler.com/bliki/CircuitBreaker.html) (15 min)
- Read: [AWS Retry Best Practices (exponential backoff + jitter)](https://docs.aws.amazon.com/general/latest/gr/api-retries.html) (10 min)
- Read: [Stripe API Error Handling](https://stripe.com/docs/error-handling) — a real-world example of clean API errors
- Answer in writing:
  1. Why does full jitter outperform pure exponential backoff under load?
  2. A client's vendor API has no `Retry-After` header. How do you decide the backoff cap?
  3. The circuit breaker is OPEN. A new request arrives. What happens — and why is this better than retrying?

### Done when
- [ ] Can explain all 5 patterns (retry, jitter, circuit breaker, timeout, bulkhead) without notes
- [ ] Know which HTTP status codes are retryable and which aren't
- [ ] Written answers to the 3 study questions above

---

## Day 2 — Base HTTP Client: Retry Loop + Error Classification

**Goal:** Build `retry.py` (backoff logic + config) and the core `client.py` retry loop. No circuit breaker yet — one concern at a time.

### Project Setup

```bash
mkdir resilient-http && cd resilient-http
python -m venv .venv && source .venv/bin/activate
pip install httpx pytest respx pytest-httpx
```

### Package Structure (build incrementally this week)

```
resilient-http/               ← distribution root (hyphens OK here)
├── pyproject.toml
├── resilient_http/           ← importable package (underscores required)
│   ├── __init__.py
│   ├── retry.py
│   ├── circuit_breaker.py
│   ├── client.py
│   ├── graphql.py
│   └── middleware/
│       ├── __init__.py
│       ├── auth.py
│       ├── logging.py
│       └── metrics.py
└── tests/
    ├── test_retry.py
    ├── test_circuit.py
    └── test_graphql.py
```

> **Import name must use underscores:** `from resilient_http.client import ResilientHTTPClient`. The directory `resilient-http/` is fine for git/pip but Python cannot import from a path with hyphens.

### `resilient_http/retry.py`

```python
"""
Retry configuration and exponential backoff with full jitter.
"""
import random
import time
import logging
from dataclasses import dataclass, field

logger = logging.getLogger(__name__)

RETRYABLE_STATUS_CODES: frozenset = frozenset({429, 500, 502, 503, 504})
NON_RETRYABLE_STATUS_CODES: frozenset = frozenset({400, 401, 403, 404, 409, 422})


@dataclass
class RetryConfig:
    max_attempts: int = 4
    base_delay: float = 1.0       # seconds
    max_delay: float = 60.0       # seconds cap
    retryable_status_codes: frozenset = field(
        default_factory=lambda: RETRYABLE_STATUS_CODES
    )
    retry_on_timeout: bool = True


def backoff_delay(attempt: int, config: RetryConfig) -> float:
    """
    Full jitter backoff: uniform random in [0, min(max_delay, base * 2^attempt)].
    De-synchronizes retrying clients to prevent thundering herd.
    """
    ceiling = min(config.max_delay, config.base_delay * (2 ** attempt))
    return random.uniform(0, ceiling)


def sleep_with_log(seconds: float, attempt: int, reason: str = "") -> None:
    logger.info(f"Retry attempt {attempt}: waiting {seconds:.2f}s. {reason}")
    time.sleep(seconds)
```

### `resilient_http/client.py` (Day 2 version — no circuit breaker yet)

```python
"""
Core ResilientHTTPClient — retry loop with error classification.
Circuit breaker integrated on Day 3.
"""
import logging
from typing import Optional, Dict

import httpx

from resilient_http.retry import RetryConfig, backoff_delay, sleep_with_log

logger = logging.getLogger(__name__)


class ResilientHTTPClient:
    def __init__(
        self,
        base_url: str,
        retry_config: Optional[RetryConfig] = None,
        default_timeout: float = 30.0,
        default_headers: Optional[Dict[str, str]] = None,
    ):
        self.base_url = base_url.rstrip("/")
        self.retry_config = retry_config or RetryConfig()
        self.client = httpx.Client(
            base_url=self.base_url,
            headers=default_headers or {},
            timeout=default_timeout,
        )

    def request(self, method: str, path: str, **kwargs) -> httpx.Response:
        cfg = self.retry_config
        last_exc: Optional[Exception] = None

        for attempt in range(cfg.max_attempts):
            try:
                response = self.client.request(method, path, **kwargs)

                # 429 — respect Retry-After header if present
                if response.status_code == 429:
                    retry_after = float(
                        response.headers.get("Retry-After", backoff_delay(attempt, cfg))
                    )
                    sleep_with_log(retry_after, attempt, "Rate limited (429)")
                    continue

                # Non-retryable — return immediately (includes 401)
                if response.status_code not in cfg.retryable_status_codes:
                    return response

                # Retryable status code
                logger.warning(
                    f"{method} {path} → {response.status_code} "
                    f"(attempt {attempt + 1}/{cfg.max_attempts})"
                )

            except (httpx.TimeoutException, httpx.ConnectError) as exc:
                if not cfg.retry_on_timeout:
                    raise
                last_exc = exc
                logger.warning(f"Network error on attempt {attempt + 1}: {exc}")

            # Sleep before next attempt (skip on last)
            if attempt < cfg.max_attempts - 1:
                delay = backoff_delay(attempt, cfg)
                sleep_with_log(delay, attempt + 1)

        raise RuntimeError(
            f"All {cfg.max_attempts} attempts failed for {method} {path}. "
            f"Last error: {last_exc}"
        )

    def get(self, path: str, **kwargs) -> httpx.Response:
        return self.request("GET", path, **kwargs)

    def post(self, path: str, **kwargs) -> httpx.Response:
        return self.request("POST", path, **kwargs)

    def put(self, path: str, **kwargs) -> httpx.Response:
        return self.request("PUT", path, **kwargs)

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.client.close()
```

### Quick Smoke Test (manual)

```python
# scratch.py — run against httpbin.org for a quick check
from resilient_http.client import ResilientHTTPClient

with ResilientHTTPClient("https://httpbin.org") as client:
    resp = client.get("/status/200")
    print(resp.status_code)  # 200

    # Simulate non-retryable 404
    resp = client.get("/status/404")
    print(resp.status_code)  # 404 — returns immediately, no retry
```

### Done when
- [ ] `retry.py` with `RetryConfig` and `backoff_delay` complete
- [ ] `client.py` retry loop handles 429, retryable codes, network errors, and non-retryable codes
- [ ] Manual smoke test passes against a real endpoint

---

## Day 3 — Circuit Breaker: State Machine + Integration

**Goal:** Build the circuit breaker as a standalone class, integrate it into `client.py`, and understand the state transition rules precisely.

### `resilient_http/circuit_breaker.py`

```python
"""
Circuit breaker with CLOSED / OPEN / HALF_OPEN states.

State transitions:
  CLOSED   → OPEN:      after `failure_threshold` consecutive failures
  OPEN     → HALF_OPEN: after `recovery_timeout` seconds have elapsed
  HALF_OPEN → CLOSED:   on a successful probe request
  HALF_OPEN → OPEN:     on a failed probe request (reset timer)

IMPORTANT: In HALF_OPEN, this implementation allows one probe at a time.
`allow_request()` sets `_probe_in_flight` to True atomically (in single-thread
context) to gate exactly one trial. Thread-safe gating requires a Lock (see notes).
"""
import time
import logging
from enum import Enum

logger = logging.getLogger(__name__)


class CircuitState(Enum):
    CLOSED = "closed"
    OPEN = "open"
    HALF_OPEN = "half_open"


class CircuitBreaker:
    def __init__(
        self,
        failure_threshold: int = 5,
        recovery_timeout: float = 30.0,
    ):
        self.failure_threshold = failure_threshold
        self.recovery_timeout = recovery_timeout

        self.state = CircuitState.CLOSED
        self.failure_count = 0
        self.last_failure_time: float = 0.0
        self._probe_in_flight: bool = False  # gates single probe in HALF_OPEN

    # ------------------------------------------------------------------
    # State queries
    # ------------------------------------------------------------------

    def allow_request(self) -> bool:
        """Return True if the current state permits a request to proceed."""
        if self.state == CircuitState.CLOSED:
            return True

        if self.state == CircuitState.OPEN:
            elapsed = time.monotonic() - self.last_failure_time
            if elapsed >= self.recovery_timeout:
                logger.info("Circuit → HALF_OPEN: testing recovery")
                self.state = CircuitState.HALF_OPEN
                self._probe_in_flight = False
            else:
                return False  # still OPEN

        # HALF_OPEN: allow only one probe; reject subsequent requests
        if self.state == CircuitState.HALF_OPEN:
            if self._probe_in_flight:
                logger.warning("Circuit HALF_OPEN — probe already in flight; rejecting")
                return False
            self._probe_in_flight = True
            return True

        return False

    # ------------------------------------------------------------------
    # State transitions
    # ------------------------------------------------------------------

    def record_success(self) -> None:
        """Call after a successful response (non-retryable status or 2xx)."""
        if self.state != CircuitState.CLOSED:
            logger.info(f"Circuit → CLOSED (was {self.state.value})")
        self.state = CircuitState.CLOSED
        self.failure_count = 0
        self._probe_in_flight = False

    def record_failure(self) -> None:
        """Call after a retryable failure or network error."""
        self.failure_count += 1
        self.last_failure_time = time.monotonic()
        self._probe_in_flight = False

        if self.state == CircuitState.HALF_OPEN:
            logger.warning("Circuit probe FAILED → back to OPEN")
            self.state = CircuitState.OPEN
        elif self.failure_count >= self.failure_threshold:
            logger.warning(
                f"Circuit → OPEN after {self.failure_count} consecutive failures"
            )
            self.state = CircuitState.OPEN

    @property
    def is_open(self) -> bool:
        return self.state == CircuitState.OPEN
```

### Update `client.py` — Integrate Circuit Breaker

Add to `__init__`:
```python
from resilient_http.circuit_breaker import CircuitBreaker

# In __init__:
self.circuit_breaker = CircuitBreaker(
    failure_threshold=circuit_failure_threshold,
    recovery_timeout=circuit_recovery_timeout,
)
```

Wrap the `request()` method:
```python
def request(self, method: str, path: str, **kwargs) -> httpx.Response:
    if not self.circuit_breaker.allow_request():
        raise RuntimeError(
            f"Circuit breaker OPEN for {self.base_url}{path} — "
            f"rejecting request to protect downstream"
        )

    cfg = self.retry_config
    last_exc: Optional[Exception] = None

    for attempt in range(cfg.max_attempts):
        try:
            response = self.client.request(method, path, **kwargs)

            if response.status_code == 429:
                retry_after = float(
                    response.headers.get("Retry-After", backoff_delay(attempt, cfg))
                )
                sleep_with_log(retry_after, attempt, "Rate limited (429)")
                self.circuit_breaker.record_failure()
                continue

            if response.status_code not in cfg.retryable_status_codes:
                self.circuit_breaker.record_success()   # ← success path
                return response

            logger.warning(
                f"{method} {path} → {response.status_code} "
                f"(attempt {attempt + 1}/{cfg.max_attempts})"
            )
            self.circuit_breaker.record_failure()       # ← failure path

        except (httpx.TimeoutException, httpx.ConnectError) as exc:
            if not cfg.retry_on_timeout:
                self.circuit_breaker.record_failure()
                raise
            last_exc = exc
            logger.warning(f"Network error on attempt {attempt + 1}: {exc}")
            self.circuit_breaker.record_failure()

        if attempt < cfg.max_attempts - 1:
            delay = backoff_delay(attempt, cfg)
            sleep_with_log(delay, attempt + 1)

    raise RuntimeError(
        f"All {cfg.max_attempts} attempts failed for {method} {path}. "
        f"Last error: {last_exc}"
    )
```

> **Thread safety note:** `_probe_in_flight` is not thread-safe in the async or multi-threaded case. For production use, wrap the `allow_request()` / state-set block in a `threading.Lock`. This implementation is correct for synchronous single-threaded usage.

### State Machine Walkthrough (do this manually)

Create a `CircuitBreaker(failure_threshold=3, recovery_timeout=5)` and manually walk through:
1. 3 × `record_failure()` → state should be `OPEN`
2. `allow_request()` → should return `False`
3. Wait 5s → `allow_request()` → should return `True` and state → `HALF_OPEN`
4. `allow_request()` again immediately → should return `False` (probe in flight)
5. `record_success()` → state should be `CLOSED`

```python
import time
from resilient_http.circuit_breaker import CircuitBreaker, CircuitState

cb = CircuitBreaker(failure_threshold=3, recovery_timeout=5)
assert cb.state == CircuitState.CLOSED

cb.record_failure(); cb.record_failure(); cb.record_failure()
assert cb.state == CircuitState.OPEN
assert not cb.allow_request()

time.sleep(5.1)
assert cb.allow_request()           # → HALF_OPEN, probe granted
assert cb.state == CircuitState.HALF_OPEN
assert not cb.allow_request()       # second call blocked
cb.record_success()
assert cb.state == CircuitState.CLOSED
print("All circuit breaker state assertions passed.")
```

### Done when
- [ ] `circuit_breaker.py` with correct CLOSED/OPEN/HALF_OPEN transitions
- [ ] `_probe_in_flight` gates exactly one probe in HALF_OPEN
- [ ] `record_success()` / `record_failure()` hooked into `client.py` retry loop
- [ ] Manual state-machine walkthrough passes all assertions

---

## Day 4 — Middleware: Logging + 401 Token-Refresh Wrapper

**Goal:** Build request/response logging middleware and understand why 401 handling must live *outside* the retry loop as a wrapper.

### Why 401 Cannot Live Inside the Retry Loop

The retry loop in `client.py` classifies 401 as **non-retryable** and returns immediately (correct). Retrying a 401 with the same expired token just hammers the auth endpoint. The correct flow is:

```
Request → 401 → [OUTSIDE retry loop] refresh token → re-invoke request() → retry loop runs fresh
```

This means the 401 handler must be a **wrapper** that calls `self.request()` again, not a branch inside it.

### `resilient_http/middleware/logging.py`

```python
"""
Request/response structured logging for ResilientHTTPClient.
Hook via httpx event hooks (request / response).
"""
import time
import logging
from typing import Optional
import httpx

logger = logging.getLogger("resilient_http.requests")


def make_logging_hooks() -> dict:
    """
    Returns httpx event hooks dict for structured request/response logging.

    Usage:
        client = httpx.Client(event_hooks=make_logging_hooks())
    """
    _request_start_times: dict = {}

    def on_request(request: httpx.Request) -> None:
        _request_start_times[id(request)] = time.monotonic()
        logger.info(
            "→ %s %s",
            request.method,
            request.url,
        )

    def on_response(response: httpx.Response) -> None:
        start = _request_start_times.pop(id(response.request), None)
        elapsed_ms = (time.monotonic() - start) * 1000 if start else None
        logger.info(
            "← %s %s %s | %.0fms",
            response.status_code,
            response.request.method,
            response.request.url,
            elapsed_ms or 0,
        )

    return {
        "request": [on_request],
        "response": [on_response],
    }
```

Update `client.py` `__init__` to wire logging hooks:
```python
from resilient_http.middleware.logging import make_logging_hooks

self.client = httpx.Client(
    base_url=self.base_url,
    headers=default_headers or {},
    timeout=default_timeout,
    event_hooks=make_logging_hooks(),
)
```

### `resilient_http/middleware/auth.py`

```python
"""
401 Token-Refresh Wrapper.

Sits OUTSIDE the retry loop. When the inner request() returns a 401,
this wrapper refreshes the token and re-invokes request() once.

This cannot be inside the retry loop because:
  - The loop classifies 401 as non-retryable and returns immediately.
  - Retrying with the same expired token won't help.
  - The refresh itself may fail, which needs separate error handling.
"""
import logging
from typing import Callable, Optional
import httpx

from resilient_http.client import ResilientHTTPClient

logger = logging.getLogger(__name__)


class TokenRefreshClient:
    """
    Wraps ResilientHTTPClient with automatic Bearer token refresh on 401.

    Args:
        inner:          The underlying ResilientHTTPClient.
        token_fetcher:  Callable() → str. Called to obtain a fresh token.
                        Must not raise — handle its own retries internally.
    """

    def __init__(
        self,
        inner: ResilientHTTPClient,
        token_fetcher: Callable[[], str],
    ):
        self._inner = inner
        self._token_fetcher = token_fetcher
        self._refresh()  # fetch initial token on construction

    def _refresh(self) -> None:
        token = self._token_fetcher()
        self._inner.client.headers["Authorization"] = f"Bearer {token}"
        logger.info("Bearer token refreshed")

    def request(self, method: str, path: str, **kwargs) -> httpx.Response:
        response = self._inner.request(method, path, **kwargs)

        if response.status_code == 401:
            logger.warning("401 received — refreshing token and retrying once")
            self._refresh()
            response = self._inner.request(method, path, **kwargs)

            if response.status_code == 401:
                logger.error("Still 401 after token refresh — check credentials")

        return response

    def get(self, path: str, **kwargs) -> httpx.Response:
        return self.request("GET", path, **kwargs)

    def post(self, path: str, **kwargs) -> httpx.Response:
        return self.request("POST", path, **kwargs)
```

### Usage Example

```python
from resilient_http.client import ResilientHTTPClient
from resilient_http.middleware.auth import TokenRefreshClient

def fetch_access_token() -> str:
    # e.g., call OAuth2 /token endpoint
    resp = httpx.post("https://auth.vendor.com/oauth/token", data={...})
    return resp.json()["access_token"]

base_client = ResilientHTTPClient("https://api.vendor.com/v1")
client = TokenRefreshClient(base_client, token_fetcher=fetch_access_token)

response = client.get("/orders")  # auto-refreshes token on 401
```

### Done when
- [ ] `middleware/logging.py` logs method, URL, status, and elapsed ms per request
- [ ] Logging visible in terminal when making requests
- [ ] `middleware/auth.py` wraps `ResilientHTTPClient`, refreshes on 401, retries once
- [ ] Written comment in `auth.py` explaining why this is a wrapper, not a retry branch

---

## Day 5 — GraphQL Support: 200-with-Errors Pattern

**Goal:** Build GraphQL query support that correctly handles the 200-with-errors response pattern — the most common GraphQL gotcha.

### The Core Problem

HTTP conventions break with GraphQL. A failed GraphQL query returns **HTTP 200** with an `errors` array in the body:

```json
HTTP/1.1 200 OK
{
  "data": null,
  "errors": [
    {
      "message": "You have exceeded your rate limit",
      "extensions": { "code": "RATE_LIMITED" }
    }
  ]
}
```

A naive consumer that only checks `response.status_code == 200` will silently swallow errors.

### `resilient_http/graphql.py`

```python
"""
GraphQL support for ResilientHTTPClient.

Key design decision: GraphQL errors come in HTTP 200 responses.
This module inspects the response body and raises/retries accordingly.
"""
import logging
import time
from typing import Any, Dict, Optional

import httpx

from resilient_http.client import ResilientHTTPClient
from resilient_http.retry import backoff_delay

logger = logging.getLogger(__name__)

# GraphQL error extension codes that are retryable
RETRYABLE_GQL_CODES = frozenset({"RATE_LIMITED", "SERVICE_UNAVAILABLE", "TIMEOUT"})


class GraphQLError(Exception):
    """Raised when a GraphQL response contains non-retryable errors."""
    def __init__(self, errors: list):
        self.errors = errors
        super().__init__(f"GraphQL errors: {errors}")


def graphql_query(
    client: ResilientHTTPClient,
    query: str,
    variables: Optional[Dict[str, Any]] = None,
    endpoint: str = "/graphql",
    max_gql_retries: int = 3,
) -> Dict[str, Any]:
    """
    Execute a GraphQL query with retry on retryable error codes.

    Returns:
        The `data` dict from a successful response.

    Raises:
        GraphQLError:  Non-retryable GraphQL errors.
        RuntimeError:  All retries exhausted (from ResilientHTTPClient).
    """
    payload = {"query": query, "variables": variables or {}}

    for attempt in range(max_gql_retries):
        # ResilientHTTPClient handles HTTP-level retries internally
        response = client.post(endpoint, json=payload)
        body = response.json()

        # Successful response with data
        if "errors" not in body:
            return body.get("data", {})

        errors = body["errors"]

        # Classify each error
        retryable = [
            err for err in errors
            if err.get("extensions", {}).get("code") in RETRYABLE_GQL_CODES
        ]
        non_retryable = [err for err in errors if err not in retryable]

        if non_retryable:
            logger.error(f"Non-retryable GraphQL errors: {non_retryable}")
            raise GraphQLError(non_retryable)

        if retryable and attempt < max_gql_retries - 1:
            delay = backoff_delay(attempt, client.retry_config)
            logger.warning(
                f"Retryable GraphQL error(s) (attempt {attempt + 1}/{max_gql_retries}): "
                f"{retryable}. Retrying in {delay:.2f}s"
            )
            time.sleep(delay)
            continue

    raise GraphQLError(errors)


# ------------------------------------------------------------------
# Example query helper
# ------------------------------------------------------------------

ORDERS_QUERY = """
query GetOrders($since: String!) {
  orders(filter: { createdAfter: $since }) {
    id
    status
    totalAmount
    customer {
      name
      email
    }
  }
}
"""


def fetch_orders(client: ResilientHTTPClient, since: str) -> list:
    data = graphql_query(client, ORDERS_QUERY, variables={"since": since})
    return data.get("orders", [])
```

### Test the 200-with-Errors Pattern Manually

Create a small FastAPI mock server to validate:

```python
# mock_graphql_server.py — run with: uvicorn mock_graphql_server:app
from fastapi import FastAPI
app = FastAPI()

call_count = {"n": 0}

@app.post("/graphql")
async def graphql(body: dict):
    call_count["n"] += 1
    # First 2 calls: rate limited (retryable)
    if call_count["n"] <= 2:
        return {
            "data": None,
            "errors": [{"message": "Rate limited", "extensions": {"code": "RATE_LIMITED"}}]
        }
    # Third call: success
    return {"data": {"orders": [{"id": 1, "status": "pending"}]}}
```

```bash
pip install fastapi uvicorn
uvicorn mock_graphql_server:app --port 8000

# In another terminal:
python -c "
from resilient_http.client import ResilientHTTPClient
from resilient_http.graphql import fetch_orders
with ResilientHTTPClient('http://localhost:8000') as c:
    print(fetch_orders(c, '2026-01-01'))
"
```

### Done when
- [ ] `graphql.py` correctly classifies retryable vs non-retryable GQL error codes
- [ ] `GraphQLError` raised for non-retryable errors
- [ ] Retryable GQL errors trigger backoff and retry (separate from HTTP retry loop)
- [ ] Successful data returned after retries pass

---

## Day 6 — Testing: Unit Tests + Integration Test

**Goal:** Write a full test suite using `respx` (purpose-built for `httpx`) covering retry logic, circuit breaker states, and GraphQL error handling.

> **Use `respx`, NOT `httpretty`.** The milestone mentions both, but `httpretty` patches at the socket level and is unreliable with `httpx`. `respx` is designed specifically for `httpx` and integrates cleanly with pytest.

```bash
pip install respx pytest
```

### `tests/test_retry.py`

```python
"""Tests for RetryConfig and retry loop behavior."""
import pytest
import respx
import httpx
from resilient_http.client import ResilientHTTPClient
from resilient_http.retry import RetryConfig


@respx.mock
def test_retries_on_503_then_succeeds():
    """Should retry on 503, succeed on 3rd attempt."""
    route = respx.get("https://api.test/data").mock(
        side_effect=[
            httpx.Response(503),
            httpx.Response(503),
            httpx.Response(200, json={"ok": True}),
        ]
    )
    client = ResilientHTTPClient(
        "https://api.test",
        retry_config=RetryConfig(max_attempts=3, base_delay=0),
    )
    response = client.get("/data")
    assert response.status_code == 200
    assert route.call_count == 3


@respx.mock
def test_no_retry_on_404():
    """404 is non-retryable — should return after 1 call."""
    route = respx.get("https://api.test/missing").mock(return_value=httpx.Response(404))
    client = ResilientHTTPClient(
        "https://api.test",
        retry_config=RetryConfig(max_attempts=4, base_delay=0),
    )
    response = client.get("/missing")
    assert response.status_code == 404
    assert route.call_count == 1  # no retries


@respx.mock
def test_respects_retry_after_header():
    """429 with Retry-After header should wait the specified time."""
    import time
    respx.get("https://api.test/endpoint").mock(
        side_effect=[
            httpx.Response(429, headers={"Retry-After": "0"}),
            httpx.Response(200, json={"ok": True}),
        ]
    )
    client = ResilientHTTPClient(
        "https://api.test",
        retry_config=RetryConfig(max_attempts=3, base_delay=0),
    )
    response = client.get("/endpoint")
    assert response.status_code == 200


@respx.mock
def test_raises_after_all_attempts_exhausted():
    """Should raise RuntimeError when all attempts are retryable failures."""
    respx.get("https://api.test/broken").mock(return_value=httpx.Response(503))
    client = ResilientHTTPClient(
        "https://api.test",
        retry_config=RetryConfig(max_attempts=3, base_delay=0),
    )
    with pytest.raises(RuntimeError, match="All 3 attempts failed"):
        client.get("/broken")


@respx.mock
def test_timeout_triggers_retry():
    """TimeoutException should trigger retry if retry_on_timeout=True."""
    route = respx.get("https://api.test/slow").mock(
        side_effect=[
            httpx.TimeoutException("timed out"),
            httpx.Response(200, json={"ok": True}),
        ]
    )
    client = ResilientHTTPClient(
        "https://api.test",
        retry_config=RetryConfig(max_attempts=3, base_delay=0, retry_on_timeout=True),
    )
    response = client.get("/slow")
    assert response.status_code == 200
    assert route.call_count == 2
```

### `tests/test_circuit.py`

```python
"""Tests for CircuitBreaker state machine."""
import time
import pytest
from resilient_http.circuit_breaker import CircuitBreaker, CircuitState


def test_initial_state_is_closed():
    cb = CircuitBreaker(failure_threshold=3, recovery_timeout=0.1)
    assert cb.state == CircuitState.CLOSED
    assert cb.allow_request()


def test_opens_after_failure_threshold():
    cb = CircuitBreaker(failure_threshold=3, recovery_timeout=60)
    cb.record_failure()
    cb.record_failure()
    assert cb.state == CircuitState.CLOSED  # not yet
    cb.record_failure()
    assert cb.state == CircuitState.OPEN


def test_open_rejects_requests():
    cb = CircuitBreaker(failure_threshold=1, recovery_timeout=60)
    cb.record_failure()
    assert not cb.allow_request()


def test_transitions_to_half_open_after_timeout():
    cb = CircuitBreaker(failure_threshold=1, recovery_timeout=0.05)
    cb.record_failure()
    assert cb.state == CircuitState.OPEN
    time.sleep(0.1)
    assert cb.allow_request()
    assert cb.state == CircuitState.HALF_OPEN


def test_half_open_allows_only_one_probe():
    cb = CircuitBreaker(failure_threshold=1, recovery_timeout=0.05)
    cb.record_failure()
    time.sleep(0.1)
    assert cb.allow_request()   # probe granted
    assert not cb.allow_request()  # second blocked


def test_success_in_half_open_closes_circuit():
    cb = CircuitBreaker(failure_threshold=1, recovery_timeout=0.05)
    cb.record_failure()
    time.sleep(0.1)
    cb.allow_request()
    cb.record_success()
    assert cb.state == CircuitState.CLOSED


def test_failure_in_half_open_reopens_circuit():
    cb = CircuitBreaker(failure_threshold=1, recovery_timeout=0.05)
    cb.record_failure()
    time.sleep(0.1)
    cb.allow_request()
    cb.record_failure()
    assert cb.state == CircuitState.OPEN
```

### `tests/test_graphql.py`

```python
"""Tests for GraphQL 200-with-errors handling."""
import pytest
import respx
import httpx
from resilient_http.client import ResilientHTTPClient
from resilient_http.graphql import graphql_query, GraphQLError
from resilient_http.retry import RetryConfig


GQL_QUERY = "{ orders { id } }"


@respx.mock
def test_successful_graphql_response():
    respx.post("https://api.test/graphql").mock(
        return_value=httpx.Response(200, json={"data": {"orders": [{"id": 1}]}})
    )
    client = ResilientHTTPClient("https://api.test", retry_config=RetryConfig(base_delay=0))
    result = graphql_query(client, GQL_QUERY)
    assert result == {"orders": [{"id": 1}]}


@respx.mock
def test_raises_on_non_retryable_gql_error():
    respx.post("https://api.test/graphql").mock(
        return_value=httpx.Response(200, json={
            "data": None,
            "errors": [{"message": "Not found", "extensions": {"code": "NOT_FOUND"}}]
        })
    )
    client = ResilientHTTPClient("https://api.test", retry_config=RetryConfig(base_delay=0))
    with pytest.raises(GraphQLError):
        graphql_query(client, GQL_QUERY)


@respx.mock
def test_retries_on_rate_limited_gql_error():
    route = respx.post("https://api.test/graphql").mock(
        side_effect=[
            httpx.Response(200, json={
                "data": None,
                "errors": [{"message": "Rate limited", "extensions": {"code": "RATE_LIMITED"}}]
            }),
            httpx.Response(200, json={"data": {"orders": []}}),
        ]
    )
    client = ResilientHTTPClient("https://api.test", retry_config=RetryConfig(base_delay=0))
    result = graphql_query(client, GQL_QUERY, max_gql_retries=3)
    assert result == {"orders": []}
    assert route.call_count == 2
```

### Run the Full Test Suite

```bash
pytest tests/ -v
```

All tests should pass. If any fail, the most common issues:
- `respx` not intercepting: ensure you use `@respx.mock` decorator or `respx.mock` context manager
- `base_delay=0` not set: tests will be slow (sleeping between retries) without zeroing the backoff

### Done when
- [ ] All 3 test files written
- [ ] `pytest tests/ -v` → all green
- [ ] `test_half_open_allows_only_one_probe` passes (validates the gating logic from Day 3)

---

## Day 7 — Package + Deliverable

**Goal:** Package `resilient_http` as an installable Python package, add the Prometheus metrics hook, and finalize the deliverable.

### `pyproject.toml`

```toml
[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "resilient-http"
version = "0.1.0"
description = "Production-grade resilient HTTP client with retry, backoff, and circuit breaker"
requires-python = ">=3.10"
dependencies = [
    "httpx>=0.27",
]

[project.optional-dependencies]
dev = [
    "pytest>=8",
    "respx>=0.21",
]
metrics = [
    "prometheus-client>=0.20",
]

[tool.hatch.build.targets.wheel]
packages = ["resilient_http"]
```

Install in editable mode:
```bash
pip install -e ".[dev,metrics]"
python -c "from resilient_http.client import ResilientHTTPClient; print('OK')"
```

### `resilient_http/middleware/metrics.py` — Prometheus Hook

```python
"""
Prometheus metrics middleware.
Tracks request count, latency histogram, and retry count per endpoint.

Usage:
    from resilient_http.middleware.metrics import make_metrics_hooks
    client = httpx.Client(event_hooks=make_metrics_hooks())
"""
import time
import logging

try:
    from prometheus_client import Counter, Histogram
    PROMETHEUS_AVAILABLE = True
except ImportError:
    PROMETHEUS_AVAILABLE = False

logger = logging.getLogger(__name__)

if PROMETHEUS_AVAILABLE:
    HTTP_REQUESTS_TOTAL = Counter(
        "resilient_http_requests_total",
        "Total HTTP requests made",
        ["method", "host", "status_code"],
    )
    HTTP_REQUEST_DURATION_SECONDS = Histogram(
        "resilient_http_request_duration_seconds",
        "HTTP request duration in seconds",
        ["method", "host"],
    )


def make_metrics_hooks() -> dict:
    """Returns httpx event hooks that emit Prometheus metrics."""
    if not PROMETHEUS_AVAILABLE:
        logger.warning("prometheus-client not installed; metrics hooks are no-ops")
        return {}

    _start_times: dict = {}

    def on_request(request) -> None:
        _start_times[id(request)] = time.monotonic()

    def on_response(response) -> None:
        start = _start_times.pop(id(response.request), None)
        if start:
            duration = time.monotonic() - start
            host = response.request.url.host
            method = response.request.method
            HTTP_REQUEST_DURATION_SECONDS.labels(method=method, host=host).observe(duration)
            HTTP_REQUESTS_TOTAL.labels(
                method=method,
                host=host,
                status_code=str(response.status_code),
            ).inc()

    return {"request": [on_request], "response": [on_response]}
```

### Final Checklist Review

- [ ] Exponential backoff with full jitter — `retry.py`
- [ ] `Retry-After` header on 429 respected — `client.py`
- [ ] Circuit breaker CLOSED/OPEN/HALF_OPEN with one-probe gate — `circuit_breaker.py`
- [ ] Unit tests with `respx` — all passing
- [ ] GraphQL 200-with-errors handled — `graphql.py`
- [ ] Request/response logging middleware — `middleware/logging.py`
- [ ] `pyproject.toml` — `pip install -e .` works
- [ ] Integration test (Day 6 mock server) passing

### Final Package Structure

```
resilient-http/
├── pyproject.toml
├── README.md
├── resilient_http/
│   ├── __init__.py
│   ├── retry.py
│   ├── circuit_breaker.py
│   ├── client.py
│   ├── graphql.py
│   └── middleware/
│       ├── __init__.py
│       ├── auth.py
│       ├── logging.py
│       └── metrics.py
└── tests/
    ├── test_retry.py
    ├── test_circuit.py
    └── test_graphql.py
```

### Self-Debrief Questions

Write answers in your notes:
1. A client's API returns `{"error": "quota exceeded"}` with HTTP 200. How does your client handle this — and what change is needed?
2. Your circuit breaker opens. A new engineer on the team asks "why are we getting errors when the downstream API is back up?" How do you explain `recovery_timeout`?
3. You're at a client site. Their internal API randomly returns 500 for ~2% of requests. Do you set `max_attempts=3` or `max_attempts=10`? What informs that number?

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Martin Fowler — Circuit Breaker](https://martinfowler.com/bliki/CircuitBreaker.html) |
| 1 | [AWS Exponential Backoff + Jitter](https://docs.aws.amazon.com/general/latest/gr/api-retries.html) |
| 2–3 | [HTTPX Documentation](https://www.python-httpx.org) |
| 2–3 | [Tenacity Library](https://tenacity.readthedocs.io) — compare to hand-rolled retry |
| 5 | [Stripe Error Handling](https://stripe.com/docs/error-handling) |
| 6 | [respx Documentation](https://lundberg.github.io/respx/) |
| 7 | [Python Packaging with Hatch](https://hatch.pypa.io/latest/config/build/) |

---

*→ Next: [Milestone 05 — Containerization / K8s: Helm](../milestones/05-containerization-k8s-helm.md)*
