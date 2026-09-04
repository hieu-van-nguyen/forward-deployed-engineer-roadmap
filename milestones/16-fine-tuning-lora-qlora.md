# Milestone 16 — Fine-Tuning Basics: LoRA / QLoRA

| Field | Value |
|---|---|
| **Month** | M4 |
| **Weeks** | W15–W16 |
| **Priority** | P3 — Medium |
| **Domain** | Fine-Tuning Basics |
| **Objective** | Learn LoRA/QLoRA fine-tuning concepts for open-source models (Llama 3 / Mistral) for domain tuning |
| **Key Deliverable** | Fine-tuned small model benchmarked against base model |

**📅 Day-by-day plan:** [Week 16 Schedule](../weeks/week-16-fine-tuning-lora-qlora.md) (Days 1–7)

---

## Why This Matters for FDEs

Some clients have air-gapped environments where they can't call OpenAI. Others need models that consistently follow a very specific output format or domain vocabulary. Fine-tuning a small open-source model (7B) is often the answer. FDEs must know when to fine-tune vs. few-shot prompt, and how to do it.

---

## Fine-Tuning Decision Matrix

| Scenario | Approach |
|----------|---------|
| New output format or style | Fine-tuning ✅ |
| New facts/knowledge | RAG (not fine-tuning — LLMs don't reliably memorize facts) |
| Consistent JSON schema | Instructor/structured output first; fine-tune if still failing |
| Domain vocabulary (medical, legal, code) | Fine-tuning ✅ |
| Can't afford API costs at scale | Fine-tuning small model ✅ |
| Air-gapped deployment | Fine-tuning open-source model ✅ |
| One-off task with < 100 examples | Few-shot prompting |

---

## LoRA vs. QLoRA

| | LoRA | QLoRA |
|---|------|-------|
| **Idea** | Add small trainable rank-decomposition matrices to frozen base model | Same as LoRA but base model is 4-bit quantized |
| **Memory** | Lower than full fine-tune | Much lower (7B model fits on 1x 24GB GPU) |
| **Quality** | Near full fine-tune | Slightly lower than LoRA |
| **GPU needed** | 40GB+ for 7B | 24GB for 7B |
| **Use case** | When you have A100s | When you have a 3090/4090 or cloud T4 |

---

## LoRA Core Concept

```
Base model weight W (frozen)
LoRA adapter: W + α × (A × B)

Where:
  A: (d × r) matrix — randomly initialized
  B: (r × k) matrix — initialized to zero
  r: rank (typically 4–64, lower = fewer params)
  α: scaling factor

At inference, merge: W_merged = W + (α/r) × A @ B
```

This adds only ~0.1–1% extra parameters for fine-tuning.

---

## QLoRA Fine-Tuning with Hugging Face

```python
# finetune/train.py
import torch
from transformers import (
    AutoModelForCausalLM,
    AutoTokenizer,
    BitsAndBytesConfig,
    TrainingArguments,
)
from peft import (
    LoraConfig,
    get_peft_model,
    prepare_model_for_kbit_training,
    TaskType,
)
from trl import SFTTrainer
from datasets import load_dataset

# ── Model & Tokenizer ──────────────────────────────────────────────────────
MODEL_ID = "meta-llama/Meta-Llama-3-8B"  # or "mistralai/Mistral-7B-v0.3"

# 4-bit quantization config
bnb_config = BitsAndBytesConfig(
    load_in_4bit=True,
    bnb_4bit_quant_type="nf4",           # NF4 quantization
    bnb_4bit_compute_dtype=torch.bfloat16,
    bnb_4bit_use_double_quant=True,       # Nested quantization
)

model = AutoModelForCausalLM.from_pretrained(
    MODEL_ID,
    quantization_config=bnb_config,
    device_map="auto",
    torch_dtype=torch.bfloat16,
)
model.config.use_cache = False
model = prepare_model_for_kbit_training(model)

tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
tokenizer.pad_token = tokenizer.eos_token
tokenizer.padding_side = "right"

# ── LoRA Config ───────────────────────────────────────────────────────────
lora_config = LoraConfig(
    task_type=TaskType.CAUSAL_LM,
    r=16,                    # Rank — higher = more params, more capacity
    lora_alpha=32,           # Scaling factor (typically 2x rank)
    target_modules=[         # Which layers to add LoRA adapters to
        "q_proj", "k_proj", "v_proj",   # Attention heads
        "o_proj",
        "gate_proj", "up_proj", "down_proj",  # MLP
    ],
    lora_dropout=0.05,
    bias="none",
)

model = get_peft_model(model, lora_config)
model.print_trainable_parameters()
# e.g., "trainable params: 41,943,040 || all params: 8,072,204,288 || trainable%: 0.52"

# ── Dataset ───────────────────────────────────────────────────────────────
# Format: chat template or instruction format
def format_sample(sample: dict) -> str:
    """Convert to Llama 3 instruction format."""
    return f"""<|begin_of_text|><|start_header_id|>system<|end_header_id|>
You are a helpful assistant specializing in enterprise software support.<|eot_id|>
<|start_header_id|>user<|end_header_id|>
{sample['question']}<|eot_id|>
<|start_header_id|>assistant<|end_header_id|>
{sample['answer']}<|eot_id|>"""

# Load your domain dataset (min 100 examples, ideally 500-5000)
dataset = load_dataset("json", data_files={
    "train": "data/train.jsonl",
    "eval": "data/eval.jsonl",
})

# ── Training Arguments ────────────────────────────────────────────────────
training_args = TrainingArguments(
    output_dir="./checkpoints",
    num_train_epochs=3,
    per_device_train_batch_size=4,
    gradient_accumulation_steps=4,    # Effective batch = 4*4 = 16
    gradient_checkpointing=True,      # Save memory at cost of compute
    learning_rate=2e-4,
    lr_scheduler_type="cosine",
    warmup_ratio=0.03,
    fp16=False,
    bf16=True,
    logging_steps=10,
    eval_steps=100,
    save_steps=100,
    save_total_limit=3,
    evaluation_strategy="steps",
    load_best_model_at_end=True,
    report_to="wandb",
)

# ── Trainer ───────────────────────────────────────────────────────────────
trainer = SFTTrainer(
    model=model,
    tokenizer=tokenizer,
    args=training_args,
    train_dataset=dataset["train"],
    eval_dataset=dataset["eval"],
    formatting_func=format_sample,
    max_seq_length=2048,
    dataset_num_proc=4,
)

trainer.train()
trainer.save_model("./final-model")
```

---

## Dataset Format

```jsonl
{"question": "How do I configure SSO with Okta?", "answer": "To configure SSO with Okta: 1. Navigate to Admin > Security > SSO. 2. Select SAML 2.0..."}
{"question": "What does error code E-4012 mean?", "answer": "Error E-4012 indicates a database connection timeout. Check that your database host is reachable..."}
```

**Data quality rules:**
- Minimum 100 examples; ideally 1,000+
- Keep input+output under 2048 tokens each
- Quality > quantity (bad data = catastrophic forgetting)
- Include ~10% negative examples (things the model should NOT do)

---

## Inference with Fine-Tuned Model

```python
# finetune/inference.py
from peft import PeftModel
from transformers import AutoModelForCausalLM, AutoTokenizer, pipeline
import torch

base_model = AutoModelForCausalLM.from_pretrained(
    MODEL_ID,
    torch_dtype=torch.bfloat16,
    device_map="auto",
)
model = PeftModel.from_pretrained(base_model, "./final-model")
model = model.merge_and_unload()  # Merge LoRA weights into base model

tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)

pipe = pipeline(
    "text-generation",
    model=model,
    tokenizer=tokenizer,
    max_new_tokens=512,
    temperature=0.1,
    do_sample=True,
)

result = pipe(format_prompt("How do I configure SSO?"))
print(result[0]["generated_text"])
```

---

## Benchmark: Fine-Tuned vs. Base

```python
# benchmark/compare.py
from deepeval.metrics import AnswerRelevancyMetric, AnswerCorrectnessMetric
from deepeval.test_case import LLMTestCase

def benchmark_models(
    base_pipeline,
    finetuned_pipeline,
    test_cases: list[dict],
) -> dict:
    metrics = [
        AnswerRelevancyMetric(threshold=0.7, model="gpt-4o"),
        AnswerCorrectnessMetric(threshold=0.7, model="gpt-4o"),
    ]

    results = {"base": [], "finetuned": []}
    for model_name, pipe in [("base", base_pipeline), ("finetuned", finetuned_pipeline)]:
        for tc in test_cases:
            answer = pipe(format_prompt(tc["question"]))[0]["generated_text"]
            case = LLMTestCase(
                input=tc["question"],
                actual_output=answer,
                expected_output=tc["expected_answer"],
            )
            results[model_name].append(case)

    return results
```

---

## Hardware Guide

| GPU | VRAM | What fits |
|-----|------|-----------|
| RTX 3090 / 4090 | 24 GB | 7B QLoRA (r=16), 3B LoRA |
| A10G | 24 GB | 7B QLoRA |
| A100 40GB | 40 GB | 13B QLoRA, 7B LoRA |
| A100 80GB | 80 GB | 70B QLoRA, 13B LoRA |

**Free cloud options:** Google Colab Pro (A100), Lambda Labs, RunPod

---

## Checklist

- [ ] 100+ domain-specific examples in `train.jsonl` and `eval.jsonl`
- [ ] QLoRA config with 4-bit quantization on a 7B model
- [ ] LoRA adapters applied to attention and MLP layers
- [ ] Training run completes (track loss in W&B or TensorBoard)
- [ ] Inference script working with merged model
- [ ] Benchmark table: base vs. fine-tuned on 20 domain questions
- [ ] Report: at what example count did fine-tuning start helping?

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Natural Language Processing with Transformers* | Lewis Tunstall et al. (free on HuggingFace) | Complete transformer fine-tuning guide — covers full fine-tuning, LoRA, and instruction tuning with HuggingFace |
| *Hands-On Large Language Models* | Jay Alammar & Maarten Grootendorst | Practical LLM engineering including fine-tuning, PEFT, and adapter methods with code |
| *Deep Learning* | Goodfellow, Bengio & Courville | Mathematical foundations — gradient descent, regularization, and optimization theory underlying LoRA |
| *AI Engineering* | Chip Huyen | Fine-tuning strategy, dataset preparation, and when to fine-tune vs. prompt engineering |
| *Designing Machine Learning Systems* | Chip Huyen | Training data quality, evaluation strategy, and deployment patterns for fine-tuned models |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| LoRA Paper (Hu et al. 2021) | [arxiv.org/abs/2106.09685](https://arxiv.org/abs/2106.09685) | Original LoRA paper — rank decomposition of weight updates, the math behind the method |
| QLoRA Paper (Dettmers et al. 2023) | [arxiv.org/abs/2305.14314](https://arxiv.org/abs/2305.14314) | 4-bit quantization + LoRA — how to fine-tune 65B models on a single GPU |
| Hugging Face PEFT Documentation | [huggingface.co/docs/peft](https://huggingface.co/docs/peft/) | Complete PEFT library reference — LoRA, QLoRA, prefix tuning, and adapter configurations |
| TRL (Transformer RL) Library | [huggingface.co/docs/trl](https://huggingface.co/docs/trl/) | SFTTrainer, DPO, PPO for instruction tuning and RLHF |
| Axolotl Framework | [github.com/axolotl-org/axolotl](https://github.com/axolotl-org/axolotl) | Production-grade fine-tuning framework — config-driven training with LoRA/QLoRA support |
| Unsloth | [github.com/unslothai/unsloth](https://github.com/unslothai/unsloth) | 2x faster fine-tuning with 60% less VRAM — practical for constrained hardware |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Finetuning Large Language Models* | DeepLearning.AI (free) | Short course on instruction fine-tuning, LoRA, and dataset preparation |
| *LLM Fine-Tuning & Alignment* | Hugging Face (free) | Full fine-tuning, PEFT, DPO, and deployment with HuggingFace ecosystem |
| *Generative AI with Large Language Models* | Coursera / DeepLearning.AI | Comprehensive LLM course including fine-tuning, RLHF, and evaluation |
