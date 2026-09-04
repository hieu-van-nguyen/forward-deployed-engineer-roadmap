# Milestone 12 — LLM Guardrails: Structured Output & Safety

| Field | Value |
|---|---|
| **Month** | M3 |
| **Weeks** | W11–W12 |
| **Priority** | P2 — High |
| **Domain** | LLM Guardrails |
| **Objective** | Integrate structured data extraction (Pydantic, Instructor) and safety guardrails (Guardrails AI) |
| **Key Deliverable** | Production API enforcing strict JSON output schemas |

**📅 Day-by-day plan:** [Week 12 Schedule](../weeks/week-12-llm-guardrails-structured-output.md) (Days 1–7)

---

## Why This Matters for FDEs

LLMs hallucinate field names, return malformed JSON, and ignore schema constraints. In production client systems, a bad LLM output hitting a downstream database or API causes crashes. Structured output + validation guardrails are non-negotiable in production FDE deployments.

---

## The Problem

```python
# Naive approach — DO NOT use in production
response = llm.invoke("Extract order details as JSON")
data = json.loads(response.content)  # Fails 5-20% of the time
order = create_order(**data)          # Fails when fields are wrong/missing
```

---

## Solution 1: Instructor (Pydantic + LLM)

Instructor patches the OpenAI client to enforce Pydantic model output.

```python
# pip install instructor pydantic
import instructor
from openai import OpenAI
from pydantic import BaseModel, Field, field_validator, model_validator
from typing import Optional, Literal
from datetime import datetime
from decimal import Decimal

client = instructor.from_openai(OpenAI())

class OrderLineItem(BaseModel):
    product_name: str = Field(..., min_length=1, max_length=200)
    sku: str = Field(..., pattern=r'^[A-Z0-9\-]{4,20}$')
    quantity: int = Field(..., ge=1, le=1000)
    unit_price: Decimal = Field(..., ge=0)

    @field_validator('unit_price')
    @classmethod
    def price_precision(cls, v):
        if round(v, 2) != v:
            raise ValueError("Price must have at most 2 decimal places")
        return v

class OrderExtraction(BaseModel):
    order_id: str = Field(..., description="Order reference number, e.g. ORD-12345")
    customer_email: str = Field(..., description="Customer email address")
    status: Literal["pending", "confirmed", "shipped", "delivered", "cancelled"]
    line_items: list[OrderLineItem] = Field(..., min_length=1)
    total_amount: Decimal
    created_at: Optional[datetime] = None

    @model_validator(mode='after')
    def validate_total(self):
        computed = sum(i.quantity * i.unit_price for i in self.line_items)
        if abs(computed - self.total_amount) > Decimal("0.01"):
            raise ValueError(f"Total {self.total_amount} doesn't match line items sum {computed}")
        return self

# Extract from unstructured text
order = client.chat.completions.create(
    model="gpt-4o",
    response_model=OrderExtraction,
    max_retries=3,  # Instructor auto-retries on validation failure
    messages=[
        {"role": "system", "content": "Extract order information from the provided text."},
        {"role": "user", "content": """
            Customer John Smith (john@acme.com) placed order ORD-98765 today.
            He ordered 3x Widget Pro (SKU: WGT-PRO-001) at $49.99 each
            and 1x Adapter Kit (SKU: ADP-KIT-02) at $19.99.
            Total: $169.96. Status: confirmed.
        """},
    ],
)
# order is a validated OrderExtraction instance — guaranteed
print(order.model_dump_json(indent=2))
```

---

## Solution 2: OpenAI Structured Outputs (JSON Schema)

```python
# Native JSON mode (less powerful than Instructor but no extra dep)
from openai import OpenAI
import json

client = OpenAI()

ORDER_SCHEMA = {
    "type": "object",
    "properties": {
        "order_id": {"type": "string"},
        "status": {"type": "string", "enum": ["pending", "confirmed", "shipped"]},
        "line_items": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "sku": {"type": "string"},
                    "quantity": {"type": "integer", "minimum": 1},
                    "unit_price": {"type": "number"},
                },
                "required": ["sku", "quantity", "unit_price"]
            }
        }
    },
    "required": ["order_id", "status", "line_items"]
}

response = client.chat.completions.create(
    model="gpt-4o",
    response_format={
        "type": "json_schema",
        "json_schema": {
            "name": "order_extraction",
            "schema": ORDER_SCHEMA,
            "strict": True,
        }
    },
    messages=[
        {"role": "user", "content": "Extract order from: " + raw_text}
    ],
)
order_data = json.loads(response.choices[0].message.content)
# Still validate with Pydantic before using!
order = OrderExtraction(**order_data)
```

---

## Solution 3: Guardrails AI

```python
# pip install guardrails-ai
import guardrails as gd
from guardrails.hub import ToxicLanguage, ValidRange, RegexMatch

# Define a Guard
guard = gd.Guard().use_many(
    ToxicLanguage(on_fail="exception"),      # Block toxic content
    RegexMatch(
        pattern=r'^[\w\s,.-]+$',
        on_fail="fix",                        # Auto-fix instead of fail
        match_full=True,
    ),
)

# Use in a pipeline
@guard.validate
def classify_customer_feedback(feedback: str) -> str:
    response = client.chat.completions.create(
        model="gpt-4o",
        messages=[
            {"role": "user", "content": f"Classify sentiment of: {feedback}. Return: positive/negative/neutral"}
        ]
    )
    return response.choices[0].message.content
```

### Custom Guard for PII Detection

```python
from guardrails import Validator, register_validator
from guardrails.validators import ValidationResult, FailResult, PassResult
import re

@register_validator(name="no-pii", data_type="string")
class NoPIIValidator(Validator):
    """Block responses containing PII (SSN, credit card numbers)."""

    SSN_PATTERN = re.compile(r'\b\d{3}-\d{2}-\d{4}\b')
    CC_PATTERN = re.compile(r'\b(?:\d{4}[- ]){3}\d{4}\b')

    def validate(self, value: str, metadata: dict) -> ValidationResult:
        if self.SSN_PATTERN.search(value):
            return FailResult(error_message="Response contains SSN pattern")
        if self.CC_PATTERN.search(value):
            return FailResult(error_message="Response contains credit card pattern")
        return PassResult()

# Use in guard
guard = gd.Guard().use(NoPIIValidator(on_fail="exception"))
```

---

## Production API Pattern

```python
# api/extract.py
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import instructor
from openai import OpenAI
import logging

logger = logging.getLogger(__name__)
app = FastAPI()
client = instructor.from_openai(OpenAI())

class ExtractionRequest(BaseModel):
    raw_text: str
    schema_type: Literal["order", "invoice", "contact"]

SCHEMA_MAP = {
    "order": OrderExtraction,
    "invoice": InvoiceExtraction,
    "contact": ContactExtraction,
}

@app.post("/extract")
async def extract(req: ExtractionRequest):
    schema = SCHEMA_MAP.get(req.schema_type)
    if not schema:
        raise HTTPException(400, f"Unknown schema: {req.schema_type}")

    try:
        result = client.chat.completions.create(
            model="gpt-4o",
            response_model=schema,
            max_retries=3,
            messages=[
                {"role": "system", "content": f"Extract {req.schema_type} data from the provided text."},
                {"role": "user", "content": req.raw_text},
            ],
        )
        logger.info(f"Extraction successful: schema={req.schema_type}")
        return result.model_dump()

    except instructor.exceptions.InstructorRetryException as e:
        logger.error(f"Extraction failed after retries: {e}")
        raise HTTPException(422, f"Could not extract valid {req.schema_type} from input: {str(e)}")
    except Exception as e:
        logger.exception("Unexpected extraction error")
        raise HTTPException(500, "Internal extraction error")
```

---

## Test Suite

```python
# tests/test_extraction.py
import pytest

def test_valid_order_extraction():
    text = "Order ORD-001: 2x SKU-A at $10.00 each. Total $20.00. Status: pending."
    order = extract_order(text)
    assert order.order_id == "ORD-001"
    assert order.total_amount == Decimal("20.00")
    assert len(order.line_items) == 1

def test_total_mismatch_raises():
    text = "Order ORD-002: 1x SKU-B at $10.00. Total $999.00. Status: confirmed."
    with pytest.raises(Exception, match="Total.*doesn't match"):
        extract_order(text)

def test_toxic_content_blocked():
    with pytest.raises(Exception):
        classify_customer_feedback("[hate speech content]")

def test_pii_blocked():
    with pytest.raises(Exception, match="SSN"):
        clean_text("Customer SSN is 123-45-6789")
```

---

## Checklist

- [ ] Pydantic model for at least one complex entity (order, invoice, etc.)
- [ ] `@model_validator` for cross-field business rules (total validation)
- [ ] Instructor wrapping OpenAI client with `max_retries=3`
- [ ] Native JSON schema mode demonstrated as alternative
- [ ] Guardrails AI toxic language filter on any user-facing output
- [ ] Custom PII validator blocking SSN and credit card patterns
- [ ] FastAPI endpoint returning validated Pydantic model as JSON
- [ ] Unit tests for validation failure cases
- [ ] API tested with malformed/tricky input that would break naive JSON parsing

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Pydantic Documentation* | Pydantic team (free online) | Complete reference for validators, model validators, and field constraints — read before any production LLM integration |
| *AI Engineering* | Chip Huyen | Chapter on LLM reliability covers structured output, validation strategies, and failure modes |
| *Building Trustworthy AI Systems* | Various (O'Reilly) | Covers guardrails, red-teaming, and safety evaluation for deployed LLM systems |
| *Prompt Engineering Guide* | DAIR.AI (free) | Techniques for making LLMs produce more consistent structured output |
| *Designing Machine Learning Systems* | Chip Huyen | Data validation patterns applicable to LLM output pipelines |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Instructor Documentation | [python.useinstructor.com](https://python.useinstructor.com) | Complete Instructor library docs — structured extraction, retry logic, and validation patterns |
| Pydantic Documentation | [docs.pydantic.dev](https://docs.pydantic.dev) | Validators, model validators, field constraints, and custom types |
| OpenAI Structured Outputs | [platform.openai.com/docs/guides/structured-outputs](https://platform.openai.com/docs/guides/structured-outputs) | Native JSON schema enforcement in OpenAI API |
| Guardrails AI Documentation | [docs.guardrailsai.com](https://docs.guardrailsai.com) | Complete guardrails validator library — built-in and custom validators |
| Outlines Library | [github.com/outlines-dev/outlines](https://github.com/outlines-dev/outlines) | Grammar-constrained generation for local LLMs — guarantees valid JSON output |
| Microsoft Guidance | [github.com/microsoft/guidance](https://github.com/microsoft/guidance) | Constrained LLM generation — interleave generation and validation at token level |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Improving Accuracy of LLM Applications* | DeepLearning.AI (free) | Structured output, validation, and accuracy improvements in LLM applications |
| *Red Teaming LLM Applications* | DeepLearning.AI (free) | Adversarial testing — find ways LLMs bypass your guardrails |
| *Building Production LLM Applications* | Weights & Biases (free) | End-to-end production patterns including output validation and monitoring |
