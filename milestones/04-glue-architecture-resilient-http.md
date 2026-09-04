# Milestone 04 — Glue Architecture: Resilient HTTP Client

| Field | Value |
|---|---|
| **Month** | M1 |
| **Weeks** | W3–W4 |
| **Priority** | P2 — High |
| **Domain** | Glue Architecture |
| **Objective** | Build robust error-handling & retry wrappers with backoff for unreliable 3rd-party REST/GraphQL APIs |
| **Key Deliverable** | Reusable Python/Go resilient HTTP client package |

**📅 Day-by-day plan:** [Week 4 Schedule](../weeks/week-04-glue-architecture-resilient-http.md) (Days 1–7)

---

## Why This Matters for FDEs

FDEs integrate with client vendor APIs constantly — ERP systems, payment processors, legacy middleware, salesforce, and internal microservices. These APIs are flaky, poorly documented, rate-limited, and return non-standard error codes. A production-grade HTTP client handles this gracefully so your integration doesn't wake the client team at 3am.

---

## Core Patterns

### 1. Exponential Backoff with Jitter
Naive retry: wait 1s, 2s, 4s, 8s...  
Problem: All retrying clients hit the server at the same time (thundering herd).  
Solution: Add random jitter.

```python
import random, time

def exponential_backoff(attempt: int, base: float = 1.0, cap: float = 60.0) -> float:
    """Full jitter backoff — uniform random between 0 and min(cap, base * 2^attempt)"""
    wait = min(cap, base * (2 ** attempt))
    return random.uniform(0, wait)
```

### 2. Retryable vs Non-Retryable Errors

| HTTP Status | Retryable? | Reason |
|-------------|-----------|--------|
| 429 | Yes (with backoff) | Rate limited — respect Retry-After header |
| 503 | Yes | Service temporarily unavailable |
| 504 | Yes | Gateway timeout |
| 500 | Conditional | May be transient; cap retries |
| 400 | No | Bad request — retrying won't help |
| 401 | No | Auth failure — retry after token refresh |
| 404 | No | Resource doesn't exist |
| 409 | No | Conflict — idempotency issue |

---

## Python: Production-Grade Resilient Client

```python
"""
resilient_http/client.py
Reusable resilient HTTP client with retry, backoff, circuit breaker, and timeout.
"""

import time
import random
import logging
from typing import Optional, Dict, Any, Callable
from dataclasses import dataclass, field
from enum import Enum

import httpx

logger = logging.getLogger(__name__)


class CircuitState(Enum):
    CLOSED = "closed"      # Normal operation
    OPEN = "open"          # Failing — reject requests
    HALF_OPEN = "half_open"  # Testing recovery


@dataclass
class RetryConfig:
    max_attempts: int = 4
    base_delay: float = 1.0       # seconds
    max_delay: float = 60.0       # seconds
    retryable_status_codes: set = field(default_factory=lambda: {429, 500, 502, 503, 504})
    retry_on_timeout: bool = True


@dataclass
class CircuitBreakerConfig:
    failure_threshold: int = 5     # consecutive failures to open circuit
    recovery_timeout: float = 30.0  # seconds before trying half-open


class CircuitBreaker:
    def __init__(self, config: CircuitBreakerConfig):
        self.config = config
        self.state = CircuitState.CLOSED
        self.failure_count = 0
        self.last_failure_time: Optional[float] = None

    def record_success(self):
        self.failure_count = 0
        self.state = CircuitState.CLOSED

    def record_failure(self):
        self.failure_count += 1
        self.last_failure_time = time.monotonic()
        if self.failure_count >= self.config.failure_threshold:
            logger.warning("Circuit OPEN — too many failures")
            self.state = CircuitState.OPEN

    def allow_request(self) -> bool:
        if self.state == CircuitState.CLOSED:
            return True
        if self.state == CircuitState.OPEN:
            elapsed = time.monotonic() - (self.last_failure_time or 0)
            if elapsed >= self.config.recovery_timeout:
                logger.info("Circuit HALF_OPEN — testing recovery")
                self.state = CircuitState.HALF_OPEN
                return True
            return False
        return True  # HALF_OPEN: allow one request


class ResilientHTTPClient:
    def __init__(
        self,
        base_url: str,
        retry_config: Optional[RetryConfig] = None,
        circuit_config: Optional[CircuitBreakerConfig] = None,
        default_timeout: float = 30.0,
        default_headers: Optional[Dict[str, str]] = None,
    ):
        self.base_url = base_url.rstrip("/")
        self.retry_config = retry_config or RetryConfig()
        self.circuit_breaker = CircuitBreaker(circuit_config or CircuitBreakerConfig())
        self.default_timeout = default_timeout
        self.client = httpx.Client(
            base_url=self.base_url,
            headers=default_headers or {},
            timeout=default_timeout,
        )

    def _backoff_delay(self, attempt: int) -> float:
        cfg = self.retry_config
        wait = min(cfg.max_delay, cfg.base_delay * (2 ** attempt))
        return random.uniform(0, wait)  # full jitter

    def request(
        self,
        method: str,
        path: str,
        on_before_retry: Optional[Callable[[int, Exception], None]] = None,
        **kwargs,
    ) -> httpx.Response:
        if not self.circuit_breaker.allow_request():
            raise RuntimeError(f"Circuit breaker OPEN for {self.base_url}")

        last_exception: Optional[Exception] = None

        for attempt in range(self.retry_config.max_attempts):
            try:
                response = self.client.request(method, path, **kwargs)

                # Handle rate limiting with Retry-After header
                if response.status_code == 429:
                    retry_after = float(response.headers.get("Retry-After", self._backoff_delay(attempt)))
                    logger.warning(f"Rate limited. Waiting {retry_after:.1f}s (attempt {attempt+1})")
                    time.sleep(retry_after)
                    continue

                if response.status_code not in self.retry_config.retryable_status_codes:
                    self.circuit_breaker.record_success()
                    return response

                # Retryable status code
                logger.warning(f"Retryable status {response.status_code} (attempt {attempt+1}/{self.retry_config.max_attempts})")
                self.circuit_breaker.record_failure()

            except (httpx.TimeoutException, httpx.ConnectError) as e:
                if not self.retry_config.retry_on_timeout:
                    raise
                last_exception = e
                logger.warning(f"Network error on attempt {attempt+1}: {e}")
                self.circuit_breaker.record_failure()

            if attempt < self.retry_config.max_attempts - 1:
                delay = self._backoff_delay(attempt)
                logger.info(f"Retrying in {delay:.2f}s...")
                if on_before_retry:
                    on_before_retry(attempt, last_exception)
                time.sleep(delay)

        raise RuntimeError(
            f"All {self.retry_config.max_attempts} attempts failed for {method} {path}. "
            f"Last error: {last_exception}"
        )

    def get(self, path: str, **kwargs) -> httpx.Response:
        return self.request("GET", path, **kwargs)

    def post(self, path: str, **kwargs) -> httpx.Response:
        return self.request("POST", path, **kwargs)

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.client.close()
```

### Usage Example

```python
from resilient_http.client import ResilientHTTPClient, RetryConfig

client = ResilientHTTPClient(
    base_url="https://api.vendor.com/v1",
    retry_config=RetryConfig(max_attempts=5, base_delay=2.0),
    default_headers={"Authorization": "Bearer <token>"},
)

# Automatic retry with backoff
response = client.get("/orders", params={"since": "2024-01-01"})
data = response.json()
```

---

## GraphQL Support

```python
def graphql_query(
    client: ResilientHTTPClient,
    query: str,
    variables: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    response = client.post(
        "/graphql",
        json={"query": query, "variables": variables or {}},
    )
    result = response.json()

    # GraphQL errors come in 200 responses!
    if "errors" in result:
        errors = result["errors"]
        logger.error(f"GraphQL errors: {errors}")
        # Check if any error is retryable
        for err in errors:
            if err.get("extensions", {}).get("code") == "RATE_LIMITED":
                raise httpx.HTTPStatusError("Rate limited via GraphQL", ...)
        raise ValueError(f"GraphQL query failed: {errors}")

    return result.get("data", {})
```

---

## Go Implementation

```go
// resilient/client.go
package resilient

import (
    "context"
    "math"
    "math/rand"
    "net/http"
    "time"
)

type RetryConfig struct {
    MaxAttempts int
    BaseDelay   time.Duration
    MaxDelay    time.Duration
    RetryableStatusCodes map[int]bool
}

func DefaultRetryConfig() RetryConfig {
    return RetryConfig{
        MaxAttempts: 4,
        BaseDelay:   time.Second,
        MaxDelay:    60 * time.Second,
        RetryableStatusCodes: map[int]bool{
            429: true, 500: true, 502: true, 503: true, 504: true,
        },
    }
}

func (r RetryConfig) backoffDelay(attempt int) time.Duration {
    wait := float64(r.BaseDelay) * math.Pow(2, float64(attempt))
    if wait > float64(r.MaxDelay) {
        wait = float64(r.MaxDelay)
    }
    // Full jitter
    return time.Duration(rand.Float64() * wait)
}

type Client struct {
    httpClient *http.Client
    config     RetryConfig
}

func (c *Client) Do(ctx context.Context, req *http.Request) (*http.Response, error) {
    var lastErr error
    for attempt := 0; attempt < c.config.MaxAttempts; attempt++ {
        resp, err := c.httpClient.Do(req.Clone(ctx))
        if err == nil && !c.config.RetryableStatusCodes[resp.StatusCode] {
            return resp, nil
        }
        lastErr = err
        if attempt < c.config.MaxAttempts-1 {
            delay := c.config.backoffDelay(attempt)
            select {
            case <-time.After(delay):
            case <-ctx.Done():
                return nil, ctx.Err()
            }
        }
    }
    return nil, lastErr
}
```

---

## Package Structure

```
resilient-http/
├── client.py             # Core ResilientHTTPClient class
├── circuit_breaker.py    # Extracted CircuitBreaker
├── retry.py              # RetryConfig + backoff logic
├── middleware/
│   ├── auth.py           # Token refresh on 401
│   ├── logging.py        # Request/response logging
│   └── metrics.py        # Prometheus metrics hook
├── tests/
│   ├── test_retry.py     # Mocked server returning 503s
│   ├── test_circuit.py   # Circuit breaker state machine
│   └── test_graphql.py   # GraphQL error handling
└── README.md
```

---

## Checklist

- [ ] Implement exponential backoff with full jitter
- [ ] Handle `Retry-After` header on 429 responses
- [ ] Implement circuit breaker with CLOSED/OPEN/HALF_OPEN states
- [ ] Write unit tests using `httpretty` or `respx` mocking
- [ ] Handle GraphQL 200-with-errors pattern
- [ ] Add request/response logging middleware
- [ ] Package as importable module with `pyproject.toml`
- [ ] Write integration test against a rate-limited mock server

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Release It! Design and Deploy Production-Ready Software* | Michael Nygard | Coined the circuit breaker pattern; covers stability patterns including timeouts, bulkheads, and retries — essential reading for integration engineers |
| *Building Microservices* | Sam Newman | Chapter on resilience covers retry strategies, bulkhead patterns, and inter-service communication under failure |
| *Cloud Native Patterns* | Cornelia Davis | Patterns for service-to-service communication including retry, circuit breaker, and fallback in cloud-native systems |
| *Designing Distributed Systems* | Brendan Burns | Sidecar, ambassador, and adapter patterns for building resilient service communication layers |
| *API Design Patterns* | JJ Geewax | RESTful and gRPC API patterns including versioning, error handling, and backward compatibility |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| HTTPX Documentation | [python-httpx.org](https://www.python-httpx.org) | Async-first HTTP client for Python — the library used in this milestone |
| Tenacity Library Docs | [tenacity.readthedocs.io](https://tenacity.readthedocs.io) | Python retry library with decorators for easy retry + backoff logic |
| AWS Retry Best Practices | [docs.aws.amazon.com/general/latest/gr/api-retries.html](https://docs.aws.amazon.com/general/latest/gr/api-retries.html) | AWS guidelines on exponential backoff and jitter implementation |
| Martin Fowler — Circuit Breaker | [martinfowler.com/bliki/CircuitBreaker.html](https://martinfowler.com/bliki/CircuitBreaker.html) | Original circuit breaker pattern description by Fowler |
| Google API Design Guide — Errors | [cloud.google.com/apis/design/errors](https://cloud.google.com/apis/design/errors) | Industry-standard error handling and retry guidance from Google |
| Stripe API Error Handling | [stripe.com/docs/error-handling](https://stripe.com/docs/error-handling) | Real-world example of well-designed API error responses |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *REST API Design, Development & Management* | Udemy (Rajeev Sakhuja) | API error handling, versioning, and resilience patterns |
| *Microservices with Python* | Real Python (free articles) | Building resilient Python microservices with retry and circuit breaker |
| *API Testing Fundamentals* | Test Automation University (free) | Testing resilient HTTP clients including failure simulation |
