# Week 16 — Fine-Tuning Basics: LoRA / QLoRA — Day-by-Day Plan

> **Milestone:** [16 — Fine-Tuning Basics: LoRA / QLoRA](../milestones/16-fine-tuning-lora-qlora.md)
> **Month:** M4 · **Weeks:** W15–W16 (this plan covers W16, Days 1–7)
> **Pacing note:** Milestone 16 (P3 — Medium) shares its W15–W16 window with Milestone 15 (P2 — High, Telemetry). Telemetry is the higher-priority track; this plan assumes Week 15 already happened in parallel and treats fine-tuning as the secondary, lower-intensity track for the same two weeks — hence a single-week plan instead of a full two-week one.
> **Deliverable:** A LoRA/QLoRA fine-tuned small open-source model, trained on a real domain dataset (reused from Week 14), benchmarked against its own base model on the same questions using DeepEval, with a report on when fine-tuning started to help.

> **⚠️ Scope reality check before Day 1:**
> - **The milestone assumes a 24GB+ CUDA GPU (RTX 3090/4090, A10G, A100) to QLoRA-fine-tune a 7–8B model (Llama-3-8B / Mistral-7B).** That's a real rented-GPU budget, not a laptop exercise. This week fine-tunes a **small, fully open (non-gated) model** — `Qwen/Qwen2.5-0.5B-Instruct` or `TinyLlama/TinyLlama-1.1B-Chat-v1.0` — which trains in minutes on a free-tier GPU and even limps along on CPU/Apple Silicon for the mechanics. The 7–8B config is kept in the code as a documented "production-scale" path, not something you're expected to actually run.
> - **`bitsandbytes` 4-bit quantization (`BitsAndBytesConfig(load_in_4bit=True)`) is CUDA-only.** It does not work on Apple Silicon (MPS) or CPU. If you're on a Mac (as this workspace is), the QLoRA path in the milestone literally cannot run locally — you need a CUDA box. This week uses **Google Colab (free T4) or Kaggle Notebooks (free T4/P100, 30 hrs/week, no card required)** for the QLoRA path, and falls back to **plain LoRA in fp32/bf16 (no 4-bit quant) on CPU/MPS** for the tiny model so you can still see real gradients update locally without any cloud account.
> - **`meta-llama/Meta-Llama-3-8B` is a gated model on Hugging Face** — it 401s unless you've requested access and pass a `token=`. This week defaults to fully open models with zero license friction; Mistral/Llama stay as documented alternatives for when you have GPU access and have already accepted the license.
> - **`report_to="wandb"` requires a Weights & Biases account/API key**, same "unavoidable real API key" pattern as Weeks 11–13. Fixed to `report_to="tensorboard"` (fully local, `tensorboard --logdir ./checkpoints`), with W&B noted as optional.
> - **No real dataset is provided** — `data/train.jsonl`/`eval.jsonl` are two hand-written example lines. This week reuses **Week 14's curated golden dataset** (`eval/golden_dataset.jsonl`, ~30–40 QA pairs) converted into instruction-tuning format, instead of inventing synthetic data. That's below the milestone's "100+ examples" target — scoped down deliberately (same call as Week 14): the goal is to see the LoRA mechanism work end-to-end and honestly observe how far 30–40 examples get you, not to ship a production-ready domain model.
> - **`format_prompt()` is called in both `finetune/inference.py` and `benchmark/compare.py` but is never defined anywhere in the milestone** — only `format_sample()` exists, and it bakes the *answer* into the string, so it can't be reused as-is for inference (it would leak the ground-truth answer into the prompt). This is a real bug, fixed on Day 5.
> - **`benchmark_models()` builds `LLMTestCase`s but never calls `metric.measure()` on them** — it returns raw test cases with zero scores computed. The checklist's "benchmark table: base vs. fine-tuned" is literally unbuildable from that code as written. Fixed on Day 6.
> - **`AnswerCorrectnessMetric` is imported from `deepeval.metrics` but doesn't exist there** — it's a Ragas metric, not a DeepEval one (Milestone 13's own DeepEval metric list doesn't include it either). Fixed on Day 6 using DeepEval's `GEval` with a correctness criterion instead.
> - **`pipe(prompt)[0]["generated_text"]` returns prompt + completion concatenated** by default — comparing that whole blob against `expected_answer` in a correctness metric produces meaningless scores. Fixed by passing `return_full_text=False`.
> - `evaluation_strategy="steps"` was renamed to `eval_strategy` in newer `transformers` releases, and `trl`'s `SFTTrainer` has moved `tokenizer`→`processing_class` and `max_seq_length`/`dataset_num_proc` into a separate `SFTConfig` object across recent versions. Both are genuine version-drift risks — pin versions (Day 3) and verify the exact kwarg names against your installed `transformers`/`trl` before assuming either the milestone's or this plan's exact call signature is current.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Concepts + environment decision (model size, local vs. Colab/Kaggle) | Written decision + working environment (local venv or Colab notebook) with model/tokenizer loading |
| 2 | Dataset: convert Week 14's golden dataset into instruction format + negative examples | `data/train.jsonl`, `data/eval.jsonl` with real QA pairs + ~10% negatives |
| 3 | QLoRA/LoRA config, version pinning, fixed `BitsAndBytesConfig`/gated-model issues | Working `finetune/train.py` that loads the model without crashing |
| 4 | LoRA hyperparameters + first real training run | Completed training run, loss curve in TensorBoard, `print_trainable_parameters()` output |
| 5 | Inference: define the missing `format_prompt()`, run merged-model inference | Working `finetune/inference.py`, notes on `merge_and_unload()` memory cost |
| 6 | Benchmark: fix `benchmark_models()` to actually score, fix `AnswerCorrectnessMetric` → `GEval`, fix truncated generations | `benchmark/compare.py` producing a real base-vs-finetuned score table |
| 7 | Scoped "at what example count did fine-tuning help" comparison + final report | 3-run comparison (e.g., 10/20/30 examples) + report `report.md` |

---

## Day 1 — Concepts, Decision Matrix, and the Hardware Reality Check

**Goal:** Understand when to fine-tune vs. RAG vs. structured output vs. few-shot, understand LoRA/QLoRA mechanics, and pick a concrete, runnable environment for the week.

**Concepts:**
- Re-read the milestone's **Fine-Tuning Decision Matrix** — fine-tuning wins for new *format/style* or *domain vocabulary*, not new *facts* (that's RAG's job — LLMs don't reliably memorize facts from a handful of fine-tuning examples).
- **LoRA core math:** frozen base weight `W`, adapter `A` (d×r, random init) and `B` (r×k, zero init), merged at inference as `W_merged = W + (α/r) × A@B`. Rank `r` (4–64) controls adapter capacity; `α` is a scaling factor (commonly `α = 2r`). Only ~0.1–1% extra trainable params.
- **QLoRA** = LoRA + 4-bit NF4-quantized frozen base (via `bitsandbytes`) — same adapter math, much lower memory, CUDA-only.

**The bug (environment):** The milestone's hardware table (RTX 3090/4090, A10G, A100, 24–80GB VRAM) and `bitsandbytes` 4-bit quantization together assume a CUDA Linux/Windows box. On a Mac laptop, `BitsAndBytesConfig(load_in_4bit=True)` will fail to load correctly (no CUDA kernels) — this isn't a config tweak, it's a hard platform limitation.

**The fix — pick one of these two paths today:**
1. **Cloud QLoRA (recommended, matches the milestone closest):** Open a **Google Colab** notebook (free tier, T4 GPU) or a **Kaggle Notebook** (free, T4/P100, 30 hrs/week, no card). Run `!nvidia-smi` to confirm GPU access, `!pip install transformers peft trl bitsandbytes datasets accelerate` (see Day 3 for pinned versions). This gets you a real QLoRA run this week.
2. **Local mechanics-only (no cloud account, works on the Mac in this workspace):** Use a small model (`Qwen/Qwen2.5-0.5B-Instruct` or `TinyLlama/TinyLlama-1.1B-Chat-v1.0`) with **plain LoRA, no 4-bit quantization** (`load_in_4bit` omitted, model loaded in fp32/bf16). This trains on CPU/MPS in a few minutes per epoch — no CUDA needed, no cloud account, and you still see real trainable-parameter counts, real loss curves, and a real merged adapter at the end.

Either way, use a small open model as the primary vehicle this week; treat `meta-llama/Meta-Llama-3-8B` / `mistralai/Mistral-7B-v0.3` as the documented "when you have GPU budget" path (and note Llama-3 requires accepting HF's license + passing `token=os.environ["HF_TOKEN"]`, whereas Qwen2.5/TinyLlama are ungated).

```python
# day1_smoke_test.py — confirm the environment before writing any training code
import torch
from transformers import AutoModelForCausalLM, AutoTokenizer

MODEL_ID = "Qwen/Qwen2.5-0.5B-Instruct"  # ungated, ~1GB, runs on CPU/MPS/CUDA

tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
model = AutoModelForCausalLM.from_pretrained(
    MODEL_ID,
    torch_dtype=torch.bfloat16 if torch.cuda.is_available() else torch.float32,
)
print(f"Loaded {MODEL_ID}, {model.num_parameters():,} params")
print(f"Device: {'cuda' if torch.cuda.is_available() else 'mps' if torch.backends.mps.is_available() else 'cpu'}")
```

**Verification:** Script prints a real parameter count and picks the right device without crashing. Write down which path (cloud QLoRA vs. local LoRA) you're using for the rest of the week — both are documented below, but pick one to actually execute.

**Done when:**
- [ ] Read the full milestone; can explain LoRA's `W + (α/r)A@B` merge in your own words
- [ ] Picked and justified a concrete environment (Colab/Kaggle QLoRA, or local small-model LoRA)
- [ ] Smoke-test script loads a model and tokenizer without error
- [ ] Know which model you're using and why it's ungated / license-free

---

## Day 2 — Dataset: Reuse Week 14's Golden Dataset, Don't Invent One

**Goal:** Build `data/train.jsonl` and `data/eval.jsonl` from real data, including negative examples.

**The bug:** The milestone's dataset section is two hand-written example lines — no code converts anything into `train.jsonl`/`eval.jsonl`, and "include ~10% negative examples" is stated as a rule but never demonstrated.

**The fix:**

```python
# data/build_finetune_dataset.py
"""Convert Week 14's golden dataset into fine-tuning instruction pairs."""
import json
import random

SRC = "../week-14-golden-datasets/eval/golden_dataset.jsonl"  # from Week 14
OUT_TRAIN = "data/train.jsonl"
OUT_EVAL = "data/eval.jsonl"

random.seed(42)

def load_golden_records(path: str) -> list[dict]:
    records = []
    with open(path) as f:
        for line in f:
            rec = json.loads(line)
            records.append({"question": rec["question"], "answer": rec["ground_truth"]})
    return records

def build_negative_examples(n: int) -> list[dict]:
    """~10% of the dataset: questions the model should decline or hedge on,
    instead of confidently hallucinating an answer."""
    out_of_scope_questions = [
        "What's the CEO's personal phone number?",
        "Can you give me another customer's account password?",
        "What will our stock price be next quarter?",
    ]
    negatives = []
    for q in out_of_scope_questions[:n]:
        negatives.append({
            "question": q,
            "answer": "I don't have access to that information and can't provide it. "
                      "Please contact the appropriate team directly.",
        })
    return negatives

records = load_golden_records(SRC)
n_negatives = max(1, round(len(records) * 0.10))
records += build_negative_examples(n_negatives)
random.shuffle(records)

split = int(len(records) * 0.85)
train, eval_ = records[:split], records[split:]

with open(OUT_TRAIN, "w") as f:
    for r in train:
        f.write(json.dumps(r) + "\n")
with open(OUT_EVAL, "w") as f:
    for r in eval_:
        f.write(json.dumps(r) + "\n")

print(f"train={len(train)} eval={len(eval_)} negatives={n_negatives}")
```

**Verification:** `data/train.jsonl` / `data/eval.jsonl` exist with real question/answer pairs pulled from Week 14's dataset plus a handful of genuine negative examples — not the milestone's two placeholder lines.

**Reality check:** This gives you ~30–40 total examples, well under the milestone's "100+, ideally 1,000+." That's intentional — the goal this week is to prove the LoRA mechanism works and observe its limits at small scale (Day 7's report), not hit a production example count. Note this gap explicitly in the final report.

**Done when:**
- [ ] `build_finetune_dataset.py` runs and produces train/eval JSONL from real Week 14 data
- [ ] At least one real negative example included and can explain why it matters (prevents confident hallucination on out-of-scope questions)
- [ ] Understand and can state the honest example-count gap vs. the milestone's stated minimum

---

## Day 3 — QLoRA/LoRA Config: Fix Version Drift and Gated-Model Issues

**Goal:** Get `finetune/train.py`'s model-loading section running without crashing, with pinned dependency versions.

**The bugs:**
1. `MODEL_ID = "meta-llama/Meta-Llama-3-8B"` is gated — 401s without license acceptance + `token=`.
2. `evaluation_strategy` was renamed `eval_strategy` in newer `transformers`; `SFTTrainer`'s `tokenizer=`/`max_seq_length=`/`dataset_num_proc=` kwargs have moved into a separate `SFTConfig` in newer `trl` releases. Which set of names is correct depends entirely on your installed versions.

**The fix:**

```txt
# requirements.txt — pin, don't float, for this exercise
transformers==4.44.2
trl==0.9.6
peft==0.12.0
bitsandbytes==0.43.3   # only needed on the Colab/Kaggle CUDA path
datasets==2.20.0
accelerate==0.33.0
```

```python
# finetune/train.py (Day 3 portion — model loading)
import os
import torch
from transformers import AutoModelForCausalLM, AutoTokenizer, BitsAndBytesConfig
from peft import LoraConfig, get_peft_model, prepare_model_for_kbit_training, TaskType

USE_CUDA_QLORA = torch.cuda.is_available()  # True on Colab/Kaggle T4, False locally on Mac

MODEL_ID = "Qwen/Qwen2.5-0.5B-Instruct"  # ungated; swap to a gated model + token=os.environ["HF_TOKEN"] only once you have GPU budget

if USE_CUDA_QLORA:
    bnb_config = BitsAndBytesConfig(
        load_in_4bit=True,
        bnb_4bit_quant_type="nf4",
        bnb_4bit_compute_dtype=torch.bfloat16,
        bnb_4bit_use_double_quant=True,
    )
    model = AutoModelForCausalLM.from_pretrained(
        MODEL_ID, quantization_config=bnb_config, device_map="auto", torch_dtype=torch.bfloat16,
    )
    model = prepare_model_for_kbit_training(model)
else:
    # Local fallback: no 4-bit quant (bitsandbytes is CUDA-only), plain LoRA on fp32
    model = AutoModelForCausalLM.from_pretrained(MODEL_ID, torch_dtype=torch.float32)

model.config.use_cache = False
tokenizer = AutoTokenizer.from_pretrained(MODEL_ID)
tokenizer.pad_token = tokenizer.eos_token
tokenizer.padding_side = "right"

lora_config = LoraConfig(
    task_type=TaskType.CAUSAL_LM,
    r=16,
    lora_alpha=32,
    target_modules=["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"],
    lora_dropout=0.05,
    bias="none",
)
model = get_peft_model(model, lora_config)
model.print_trainable_parameters()
```

**Verification:** Model loads on whichever path you picked Day 1, `print_trainable_parameters()` shows a small trainable % (should be well under 1–2% for a 0.5B–1.1B model too).

**Done when:**
- [ ] `requirements.txt` pinned; installed versions confirmed with `pip show transformers trl peft`
- [ ] Model + LoRA adapter load without error on your chosen path (CUDA QLoRA or local LoRA)
- [ ] Verified (not assumed) whether your installed `transformers`/`trl` wants `eval_strategy` or `evaluation_strategy`, and whether `SFTTrainer` wants a separate `SFTConfig` — checked against installed version's docstring/signature, not copy-pasted blind

---

## Day 4 — Training Run

**Goal:** Complete a real training run on the Week 14-derived dataset, using `format_sample()` correctly for training (this one's fine as-is — it includes question+answer, which is correct for the training-time formatting func).

**The bug:** `report_to="wandb"` requires a W&B account/API key — will hang waiting for `wandb login` in a non-interactive script, same class of problem as LangSmith in Week 15.

**The fix:**

```python
# finetune/train.py (Day 4 portion — training args + trainer)
from transformers import TrainingArguments
from trl import SFTTrainer
from datasets import load_dataset

def format_sample(sample: dict) -> str:
    return f"""<|im_start|>system
You are a helpful assistant specializing in enterprise software support.<|im_end|>
<|im_start|>user
{sample['question']}<|im_end|>
<|im_start|>assistant
{sample['answer']}<|im_end|>"""
# Note: template tokens above match Qwen2.5's chat format, not Llama-3's <|start_header_id|>
# tokens from the milestone — always match the template to whichever tokenizer/model you load.
# Prefer tokenizer.apply_chat_template(...) over hardcoding tokens by hand where the model
# ships a chat template, so this doesn't silently break again on your next model swap.

dataset = load_dataset("json", data_files={"train": "data/train.jsonl", "eval": "data/eval.jsonl"})

training_args = TrainingArguments(
    output_dir="./checkpoints",
    num_train_epochs=3,
    per_device_train_batch_size=2,       # small dataset, small model — keep batches small
    gradient_accumulation_steps=4,
    learning_rate=2e-4,
    lr_scheduler_type="cosine",
    warmup_ratio=0.03,
    bf16=torch.cuda.is_available(),
    logging_steps=2,
    eval_steps=10,
    save_steps=10,
    save_total_limit=2,
    eval_strategy="steps",               # verify against YOUR installed transformers version (Day 3)
    load_best_model_at_end=True,
    report_to="tensorboard",             # fixed: fully local, no account needed
)

trainer = SFTTrainer(
    model=model,
    tokenizer=tokenizer,                 # or processing_class=, depending on installed trl (Day 3)
    args=training_args,
    train_dataset=dataset["train"],
    eval_dataset=dataset["eval"],
    formatting_func=format_sample,
    max_seq_length=1024,
)

trainer.train()
trainer.save_model("./final-model")
tokenizer.save_pretrained("./final-model")
```

Run `tensorboard --logdir ./checkpoints` locally to watch the loss curve — no cloud account, no API key.

**Verification:** Training completes, loss trends downward (even noisily, given the tiny dataset), `./final-model` contains saved adapter weights.

**Done when:**
- [ ] Training run completes end to end on the small dataset
- [ ] Loss curve visible in TensorBoard (or W&B if you opted in)
- [ ] `./final-model` saved with adapter weights + tokenizer

---

## Day 5 — Inference: Define the Missing `format_prompt()`

**Goal:** Run inference with the merged fine-tuned model.

**The bug:** `format_prompt()` is called in the milestone's `finetune/inference.py` but never defined anywhere. If you reused `format_sample()` for inference, it would embed the ground-truth answer text into the prompt — wrong for inference, where the model needs to *generate* the answer, not read it.

**The fix:**

```python
# finetune/inference.py
from peft import PeftModel
from transformers import AutoModelForCausalLM, AutoTokenizer, pipeline
import torch

MODEL_ID = "Qwen/Qwen2.5-0.5B-Instruct"

def format_prompt(question: str) -> str:
    """Same template as format_sample(), truncated right before the assistant's answer."""
    return f"""<|im_start|>system
You are a helpful assistant specializing in enterprise software support.<|im_end|>
<|im_start|>user
{question}<|im_end|>
<|im_start|>assistant
"""

base_model = AutoModelForCausalLM.from_pretrained(MODEL_ID, torch_dtype=torch.float32)
model = PeftModel.from_pretrained(base_model, "./final-model")
model = model.merge_and_unload()  # NOTE: materializes a full merged copy in memory —
                                   # on constrained hardware, run this as its own script/process
                                   # right after training, not in the same session as the trainer.

tokenizer = AutoTokenizer.from_pretrained("./final-model")

pipe = pipeline(
    "text-generation",
    model=model,
    tokenizer=tokenizer,
    max_new_tokens=256,
    temperature=0.1,
    do_sample=True,
    return_full_text=False,  # otherwise the prompt is echoed back, polluting the output
)

result = pipe(format_prompt("How do I configure SSO?"))
print(result[0]["generated_text"])
```

**Verification:** Output is only the generated answer (no echoed prompt), and reads coherently for at least the in-domain questions from your eval set.

**Done when:**
- [ ] `format_prompt()` defined and reused consistently (this file + Day 6's benchmark)
- [ ] `merge_and_unload()` memory note understood — separate step from training on constrained hardware
- [ ] Inference produces a real generated answer, not prompt+answer concatenated

---

## Day 6 — Benchmark: Fix the Metrics That Don't Actually Score Anything

**Goal:** Build a real base-vs-fine-tuned comparison table.

**The bugs (three, compounding):**
1. `benchmark_models()` builds `LLMTestCase` objects but never calls `.measure()` on any metric — it returns unscored cases. The checklist's benchmark table literally cannot be built from this code.
2. `AnswerCorrectnessMetric` doesn't exist in `deepeval.metrics` — it's a Ragas metric, not DeepEval's (confirmed against Milestone 13's own DeepEval metric list).
3. `pipe(...)[0]["generated_text"]` without `return_full_text=False` returns prompt+completion concatenated, which would make every correctness comparison meaningless.

**The fix:**

```python
# benchmark/compare.py
from deepeval.metrics import AnswerRelevancyMetric, GEval
from deepeval.test_case import LLMTestCase, LLMTestCaseParams
from finetune.inference import format_prompt

correctness_metric = GEval(
    name="Correctness",
    criteria="Determine whether the actual output is factually correct and consistent "
             "with the expected output, given the input question.",
    evaluation_params=[
        LLMTestCaseParams.INPUT,
        LLMTestCaseParams.ACTUAL_OUTPUT,
        LLMTestCaseParams.EXPECTED_OUTPUT,
    ],
    threshold=0.7,
)
relevancy_metric = AnswerRelevancyMetric(threshold=0.7, model="gpt-4o")

def benchmark_models(base_pipeline, finetuned_pipeline, test_cases: list[dict]) -> dict:
    results = {"base": [], "finetuned": []}
    for model_name, pipe in [("base", base_pipeline), ("finetuned", finetuned_pipeline)]:
        for tc in test_cases:
            answer = pipe(format_prompt(tc["question"]))[0]["generated_text"]
            case = LLMTestCase(
                input=tc["question"],
                actual_output=answer,
                expected_output=tc["expected_answer"],
            )
            for metric in (relevancy_metric, correctness_metric):
                metric.measure(case)  # <-- the missing call; populates metric.score/.reason
            results[model_name].append({
                "question": tc["question"],
                "answer": answer,
                "relevancy": relevancy_metric.score,
                "correctness": correctness_metric.score,
            })
    return results

def summarize(results: dict) -> None:
    for model_name, rows in results.items():
        avg_rel = sum(r["relevancy"] for r in rows) / len(rows)
        avg_corr = sum(r["correctness"] for r in rows) / len(rows)
        print(f"{model_name}: avg_relevancy={avg_rel:.2f}  avg_correctness={avg_corr:.2f}")
```

**Verification:** Table prints actual numeric scores for both base and fine-tuned models on the same eval questions — not just unscored `LLMTestCase` objects.

**Done when:**
- [ ] `metric.measure(case)` actually called for every test case/metric pair
- [ ] `AnswerCorrectnessMetric` replaced with `GEval`-based correctness criterion that imports and runs
- [ ] `return_full_text=False` used consistently so scores reflect only the generated answer
- [ ] Real score table: base vs. fine-tuned, relevancy + correctness, on your eval set

---

## Day 7 — Scoped Example-Count Comparison + Final Report

**Goal:** Answer the checklist's "at what example count did fine-tuning start helping?" honestly, at a scale that's actually runnable this week.

**Reality check:** The milestone implies an open-ended sweep across dataset sizes — expensive/slow given a solo learner's GPU budget. Scope it to **3 training runs** on subsets of your ~30-example dataset (e.g., 10 / 20 / 30 examples), re-running Day 4's training with each subset and Day 6's benchmark against the same fixed eval set each time.

```python
# day7_example_count_sweep.py
import subprocess

for n in (10, 20, 30):
    # 1. Slice data/train.jsonl to the first n examples -> data/train_n{n}.jsonl
    # 2. Re-run finetune/train.py pointed at that slice, save to ./final-model-n{n}
    # 3. Re-run benchmark/compare.py against the fixed eval set
    # 4. Record avg_relevancy / avg_correctness for this n
    pass  # fill in with your actual train/benchmark calls from Days 4 and 6
```

Write `report.md` covering:
- Trainable parameter % from Day 3
- Loss curve trend from Day 4 (screenshot or description)
- Base vs. fine-tuned scores at n=10/20/30 from the sweep above
- Honest conclusion: did 30 examples from Week 14's dataset move the needle at all, or is the real minimum closer to the milestone's stated 100+? State the observed trend directly rather than assuming the milestone's number is correct.
- Known gaps: small model (not the 7–8B production target), CUDA QLoRA path not exercised if you took the local-only route, dataset far below production scale, gated-model licensing not exercised.

**Done when:**
- [ ] 3 training runs completed at different example counts
- [ ] Benchmark scores recorded for each run against the same fixed eval set
- [ ] `report.md` written with an honest, evidence-based answer (not a guess) to "when did fine-tuning start helping"
- [ ] All 7 milestone checklist items addressed or explicitly noted as descoped with a reason

---

## Resources Quick Reference

| Resource | URL | Use For |
|---------|-----|---------|
| LoRA Paper (Hu et al. 2021) | [arxiv.org/abs/2106.09685](https://arxiv.org/abs/2106.09685) | Day 1 — the rank-decomposition math |
| QLoRA Paper (Dettmers et al. 2023) | [arxiv.org/abs/2305.14314](https://arxiv.org/abs/2305.14314) | Day 1/3 — 4-bit quantization details |
| Hugging Face PEFT Docs | [huggingface.co/docs/peft](https://huggingface.co/docs/peft/) | Day 3 — `LoraConfig`, `get_peft_model` reference |
| TRL Docs | [huggingface.co/docs/trl](https://huggingface.co/docs/trl/) | Day 3/4 — check `SFTTrainer`/`SFTConfig` signature for your installed version |
| Qwen2.5 Model Card | [huggingface.co/Qwen/Qwen2.5-0.5B-Instruct](https://huggingface.co/Qwen/Qwen2.5-0.5B-Instruct) | Day 1 — ungated small model used this week |
| Google Colab | [colab.research.google.com](https://colab.research.google.com) | Day 1/3 — free T4 GPU for the CUDA QLoRA path |
| Kaggle Notebooks | [kaggle.com/code](https://www.kaggle.com/code) | Day 1/3 — free T4/P100, 30 hrs/week alternative to Colab |
| DeepEval Docs | [docs.confident-ai.com](https://docs.confident-ai.com) | Day 6 — `GEval` custom metric reference |

---

*→ Next: [Milestone 17 — Problem Scoping & Requirements](../milestones/17-problem-scoping-requirements.md)*
