# Private embedding worker (Tier 2, optional)

A small Python program that computes Qwen3-Embedding vectors on a machine **you** control and
sends them to your WordPress site through the plugin's REST API. It is not a hosted service and
never required: the plugin's lexical tier works without it. It is not included in the plugin ZIP.

## What it does

1. Reads the pinned model profile from `GET /wp-json/semantic-seo/v1/embeddings/profile`.
2. Downloads exactly the pinned files of the pinned revision from the profile's host
   (`huggingface.co`), streaming each one and verifying **size and SHA-256**; cached files are
   re-verified on every start.
3. Reproduces the model-card reference similarities (**self-test**). If they differ beyond the
   tolerance for the configured precision, it exits without uploading anything. It then sends the
   reference vectors to `POST /embeddings/selftest`: the **server** recomputes the matrix and only
   its pass (valid 24 h for this account and model profile) lets `complete` store vectors.
4. Loops: `claim` (≤ 16 items, single-use lease) → tokenize → ONNX Runtime (CPU) → last-token
   pooling → L2 → MRL truncation → `complete`. On any error it `release`s the lease.

## Setup

```bash
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt               # numpy, onnxruntime, tokenizers
```

In WordPress: create a dedicated user, give it the role **SEO Embedding Worker** (Semantic SEO →
Agent Connections), and create an Application Password on that user's profile.

```bash
export SSEO_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'   # never pass it as an argument
python sseo_worker.py --site https://example.com --user seo-worker --once
```

Options: `--batch 8` (≤ 16), `--threads N`, `--poll 60` (seconds between polls without `--once`),
`--max-items N`, `--selftest-only`, `--cache-dir ~/.cache/sseo-worker`, `--allow-http` (local test
sites only; credentials are refused over plain HTTP otherwise).

## Security properties

* Least privilege: the worker role can only read the embedding profile and claim/complete/release
  embedding work. It cannot read drafts through this API, change content or approve anything.
* Replay protection: lease tokens are single-use, stored server-side only as SHA-256, and expire
  after 180 s; completed or released leases cannot be reused.
* Rate limited like every other API call; batch size bounded server-side.
* The server re-validates the model key, dimension, finite values and unit norm, and rejects
  vectors for content that changed since the claim.

## Verification

`tests/e2e/private-worker.sh` runs this worker against a disposable WordPress site with the
labelled **tiny random fixture model** (not Qwen): 89/89 queued items stored, and a stored vector
matches an independent NumPy computation (max |Δ| 4.5e-8). With the **real** pinned
Qwen3-Embedding-0.6B (q4f16) the same script runs in the "Real Qwen3-Embedding-0.6B" GitHub Actions
workflow: 89/89 stored in 76.6 s on a 4-vCPU runner, stored vectors equal to an independent
recompute (max |Δ| 0.0). See `docs/TEST-REPORT.md` §6.
