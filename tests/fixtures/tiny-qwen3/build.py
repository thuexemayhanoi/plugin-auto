"""
Builds the TINY TEST FIXTURE used by tests/e2e/worker-fixture.mjs.

THIS IS NOT QWEN3-EMBEDDING. It is a ~100 KB randomly initialised model with a Qwen3-shaped
config (model_type "qwen3"), a byte-level BPE tokenizer trained on a few sentences, left padding
and an appended <|endoftext|> token, so the browser pipeline (pinned + SHA-256 verified download,
tokenizer, ONNX Runtime Web, position ids, last-token pooling, L2, MRL, base64) can be checked
end-to-end against an independent NumPy reference implementation.

Usage: python build.py  (needs: onnx, numpy, tokenizers; onnxruntime optional for a cross-check)
"""

import hashlib
import json
import os

import numpy as np
import onnx
from onnx import TensorProto, helper, numpy_helper
from tokenizers import Tokenizer, decoders, models, normalizers, pre_tokenizers, processors, trainers

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.join(HERE, "repo")
HIDDEN = 64
DIM = 32  # MRL target used by the fixture profile.
MAX_POS = 512
EOS = "<|endoftext|>"
TASK = "Given a web search query, retrieve relevant passages that answer the query"

CORPUS = [
    "What is the capital of China?",
    "Explain gravity",
    "The capital of China is Beijing.",
    "Gravity is a force that attracts two bodies towards each other.",
    "Thuê xe máy ở Hà Nội giá rẻ, đặt cọc theo từng loại xe.",
    "Kinh nghiệm du lịch Hà Giang bằng xe máy.",
    "Motorbike rental in Hanoi: daily, weekly and monthly prices.",
    "Instruct: Given a web search query, retrieve relevant passages that answer the query\nQuery:",
]


def build_tokenizer():
    tok = Tokenizer(models.BPE(unk_token=None))
    tok.normalizer = normalizers.NFC()
    tok.pre_tokenizer = pre_tokenizers.ByteLevel(add_prefix_space=False)
    tok.decoder = decoders.ByteLevel()
    trainer = trainers.BpeTrainer(
        vocab_size=400,
        special_tokens=[EOS],
        initial_alphabet=pre_tokenizers.ByteLevel.alphabet(),
        show_progress=False,
    )
    tok.train_from_iterator(CORPUS, trainer=trainer)
    eos_id = tok.token_to_id(EOS)
    tok.post_processor = processors.TemplateProcessing(
        single="$A " + EOS, pair="$A " + EOS + " $B " + EOS, special_tokens=[(EOS, eos_id)]
    )
    return tok, eos_id


def build_model(vocab):
    rng = np.random.default_rng(20261022)
    emb = (rng.standard_normal((vocab, HIDDEN)) * 0.5).astype(np.float32)
    pos = (rng.standard_normal((MAX_POS, HIDDEN)) * 0.1).astype(np.float32)
    w = (rng.standard_normal((HIDDEN, HIDDEN)) / np.sqrt(HIDDEN)).astype(np.float32)
    nodes = [
        helper.make_node("Gather", ["emb", "input_ids"], ["tok"], axis=0),
        helper.make_node("Gather", ["pos", "position_ids"], ["pe"], axis=0),
        helper.make_node("Add", ["tok", "pe"], ["x"]),
        helper.make_node("Cast", ["attention_mask"], ["mf"], to=TensorProto.FLOAT),
        helper.make_node("Unsqueeze", ["mf", "axis2"], ["m"]),
        helper.make_node("Mul", ["x", "m"], ["xm"]),
        helper.make_node("CumSum", ["xm", "axis1"], ["cs"]),
        helper.make_node("CumSum", ["m", "axis1"], ["cnt0"]),
        helper.make_node("Max", ["cnt0", "one"], ["cnt"]),
        helper.make_node("Div", ["cs", "cnt"], ["avg"]),
        helper.make_node("MatMul", ["avg", "w"], ["lin"]),
        helper.make_node("Tanh", ["lin"], ["last_hidden_state"]),
    ]
    inits = [
        numpy_helper.from_array(emb, "emb"),
        numpy_helper.from_array(pos, "pos"),
        numpy_helper.from_array(w, "w"),
        numpy_helper.from_array(np.array([2], dtype=np.int64), "axis2"),
        numpy_helper.from_array(np.array(1, dtype=np.int64), "axis1"),
        numpy_helper.from_array(np.array(1.0, dtype=np.float32), "one"),
    ]
    graph = helper.make_graph(
        nodes,
        "tiny_qwen3_fixture",
        [
            helper.make_tensor_value_info("input_ids", TensorProto.INT64, ["batch_size", "sequence_length"]),
            helper.make_tensor_value_info("attention_mask", TensorProto.INT64, ["batch_size", "sequence_length"]),
            helper.make_tensor_value_info("position_ids", TensorProto.INT64, ["batch_size", "sequence_length"]),
        ],
        [helper.make_tensor_value_info("last_hidden_state", TensorProto.FLOAT, ["batch_size", "sequence_length", HIDDEN])],
        inits,
    )
    model = helper.make_model(graph, opset_imports=[helper.make_opsetid("", 17)], producer_name="sseo-fixture")
    model.ir_version = 8
    onnx.checker.check_model(model)
    return model, emb, pos, w


def reference(tok, emb, pos, w, texts):
    """NumPy re-implementation: left padding, Transformers.js-style position ids, last-token pooling."""
    encs = [tok.encode(t).ids for t in texts]
    seq = max(len(e) for e in encs)
    ids = np.zeros((len(encs), seq), dtype=np.int64)
    mask = np.zeros((len(encs), seq), dtype=np.int64)
    for i, e in enumerate(encs):
        ids[i, seq - len(e):] = e
        mask[i, seq - len(e):] = 1
    position = np.cumsum(mask, axis=1) - 1
    position[mask == 0] = 1
    x = emb[ids] + pos[position]
    m = mask[..., None].astype(np.float32)
    cs = np.cumsum(x * m, axis=1)
    cnt = np.maximum(np.cumsum(m, axis=1), 1.0)
    h = np.tanh((cs / cnt) @ w)
    pooled = h[:, -1, :]
    full = pooled / np.linalg.norm(pooled, axis=1, keepdims=True)
    return full, (ids, mask, position, h)


def sha256(path):
    with open(path, "rb") as f:
        return hashlib.sha256(f.read()).hexdigest()


def main():
    os.makedirs(os.path.join(REPO, "onnx"), exist_ok=True)
    tok, eos_id = build_tokenizer()
    tok.save(os.path.join(REPO, "tokenizer.json"))
    vocab = tok.get_vocab_size()
    model, emb, pos, w = build_model(vocab)
    onnx.save(model, os.path.join(REPO, "onnx", "model.onnx"))
    config = {
        "_comment": "TEST FIXTURE - random tiny model, NOT Qwen3-Embedding weights",
        "architectures": ["Qwen3Model"],
        "model_type": "qwen3",
        "hidden_size": HIDDEN,
        "num_hidden_layers": 1,
        "num_attention_heads": 1,
        "num_key_value_heads": 1,
        "head_dim": HIDDEN,
        "vocab_size": vocab,
        "max_position_embeddings": MAX_POS,
        "eos_token_id": eos_id,
        "torch_dtype": "float32",
    }
    with open(os.path.join(REPO, "config.json"), "w", encoding="utf-8") as f:
        json.dump(config, f, indent=2)
    tok_config = {
        "tokenizer_class": "Qwen2Tokenizer",
        "model_max_length": 32768,
        "padding_side": "left",
        "eos_token": EOS,
        "pad_token": EOS,
        "unk_token": None,
        "bos_token": None,
        "clean_up_tokenization_spaces": False,
        "added_tokens_decoder": {str(eos_id): {"content": EOS, "special": True, "lstrip": False, "rstrip": False, "normalized": False, "single_word": False}},
    }
    with open(os.path.join(REPO, "tokenizer_config.json"), "w", encoding="utf-8") as f:
        json.dump(tok_config, f, indent=2)

    queries = ["What is the capital of China?", "Explain gravity"]
    documents = [
        "The capital of China is Beijing.",
        "Gravity is a force that attracts two bodies towards each other. It gives weight to physical objects and is responsible for the movement of planets around the sun.",
    ]
    q_full, _ = reference(tok, emb, pos, w, ["Instruct: " + TASK + "\nQuery:" + q for q in queries])
    d_full, _ = reference(tok, emb, pos, w, documents)
    scores = (q_full @ d_full.T).tolist()

    items = [
        {"queue_id": 101, "role": "document", "text": "Thuê xe máy ở Hà Nội giá rẻ, đặt cọc theo từng loại xe."},
        {"queue_id": 102, "role": "document", "text": "Kinh nghiệm du lịch Hà Giang bằng xe máy."},
        {"queue_id": 103, "role": "query", "text": "thuê xe máy Hà Nội"},
        {"queue_id": 104, "role": "document", "text": "Motorbike rental in Hanoi: daily, weekly and monthly prices."},
        {"queue_id": 105, "role": "document", "text": "Gravity"},
    ]
    texts = [("Instruct: " + TASK + "\nQuery:" + it["text"]) if it["role"] == "query" else it["text"] for it in items]
    # Batches of 4 inside the worker: compute per batch so padding matches exactly.
    vectors = []
    for start in range(0, len(texts), 4):
        full, _ = reference(tok, emb, pos, w, texts[start:start + 4])
        for v in full:
            cut = v[:DIM] / np.linalg.norm(v[:DIM])
            vectors.append(cut.astype(np.float32).tolist())

    files = {}
    for rel in ["config.json", "tokenizer.json", "tokenizer_config.json", "onnx/model.onnx"]:
        p = os.path.join(REPO, rel)
        files[rel] = [os.path.getsize(p), sha256(p)]

    try:
        import onnxruntime as ort

        sess = ort.InferenceSession(os.path.join(REPO, "onnx", "model.onnx"))
        _, (ids, mask, position, h) = reference(tok, emb, pos, w, texts[:4])
        out = sess.run(None, {"input_ids": ids, "attention_mask": mask, "position_ids": position})[0]
        ort_check = float(np.abs(out - h).max())
    except ImportError:
        ort_check = None

    expected = {
        "_comment": "TEST FIXTURE - expected outputs from the NumPy reference; NOT Qwen3-Embedding",
        "profile": {
            "model_key": "f" * 40,
            "model_id": "sseo-fixtures/tiny-qwen3",
            "revision": "0123456789abcdef0123456789abcdef01234567",
            "dtype": "fp32",
            "dimension": DIM,
            "pooling": "last_token",
            "normalize": True,
            "preprocess_version": "p1",
            "max_tokens": 512,
            "host": "",
            "files": files,
            "query_task": TASK,
        },
        "reference": {"queries": queries, "documents": documents, "scores": scores, "tolerance": {"fp32": 1e-4}},
        "items": items,
        "vectors": vectors,
        "onnxruntime_python_max_abs_diff": ort_check,
    }
    with open(os.path.join(HERE, "expected.json"), "w", encoding="utf-8") as f:
        json.dump(expected, f, ensure_ascii=False, indent=1)
    print("vocab", vocab, "files", files, "ort check", ort_check)


if __name__ == "__main__":
    main()
