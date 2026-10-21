# The categorizer benchmark

Two questions, measured on the production box against the editor's own
categories (the ground truth of what a gallery should be tagged with).

1. **Does a better thumbnail sample make the AI more accurate?**
2. **Does the AI learn from the editor's corrections?**

## The method

`bin/categorize_bench.php` runs the real analysis (the same model, the same
prompt, the same five category batches of 25 candidates) over a set of
galleries and scores every run against the editor's tags:

```
recall    = |proposals ∩ editor tags| / |editor tags|
precision = |proposals ∩ editor tags| / |proposals|
```

It never writes to the database (`CategoryAdvisor::suggest()` only reads) and
it takes the categorizer's own lock, so it never overlaps the worker. Every
run lands in `storage/logs/categorizer-bench.csv` (the numbers) and
`storage/logs/categorizer-bench.jsonl` (the proposals by name).

The galleries (production, chosen because the editor has a tag set for them):

| gallery | photos | editor tags | why |
|---|---|---|---|
| 348 | 471 | 48 | the thin-coverage case: 3 photos are 0.6 % of it |
| 320 | 154 | 32 | a mid-sized gallery |
| 154 | 53 | 26 | a small gallery |
| 299 | 3 | 21 | the control: the whole gallery fits in the sample |

## What the runs cost (the box is CPU-only)

| | |
|---|---|
| one image through the vision encoder | **25-32 s** |
| one batch (3 images + a 25 name list + the answer) | 80-160 s |
| one gallery (5 batches) | 7-14 min |
| the model's answer | up to 800 tokens at ~4 tokens/s |

The vision encoding dominates, and the same three images are encoded again
for every batch. That is what the "describe" variant was meant to fix.

## 1. The thumbnail sample

| gallery | head3 (the shipped behaviour) | spread3 (the same three, spread over the gallery) |
|---|---|---|
| 348 (471 photos) | 2.1 %, 2.1 % | **25.0 %, 22.9 %** |
| 320 (154) | 28.1 % | 6.3 % |
| 154 (53) | 38.5 % | 15.4 % |
| the mean | **22.9 %** | 15.6 % |

**The sample is not the lever.** Spreading the sample rescues the extreme
case (a 471 photo gallery: 2 % → 25 %) and hurts the others, so the mean
gets *worse*. The shipped sampling stays as it is.

The reason is visible in the control: gallery 299 has **three** photos, the
model saw all three, and it still proposed 2 categories that matched none of
the editor's 21 tags. The model is not short of pictures - it is short of
**willingness to name categories**.

### The noise floor

The same gallery, the same sample, twice: gallery 299 scored 0 % and 19 %
(two runs, 854 s and 443 s). The model's own variance is large, so a single
run is not a measurement - which is why the two galleries that were run
twice agree with themselves (348: 2.1 % / 2.1 % and 25.0 % / 22.9 %).

### The "describe" variant

One vision call over 12 spread images writes a short description, and the
five category batches are then judged from that text (the images are
encoded once instead of once a batch). It is the cheapest way to see many
images - but 12 images are 12,500 prompt tokens, and the prefill alone is
~14 tokens/s on this CPU: the call ran past the 900 second timeout and
produced nothing. With six images it would fit, at ~2x the cost of the
baseline. Not worth it next to the result in part 2.

## 2. The learning (the editor's corrections)

The same runs with the learning layer on (`--learn=1`): the prompt carries
the editor's corrections for the batch being judged, a category the editor
took off is never proposed on that gallery again, one they keep is worth
more, and the editor's own vocabulary for similar galleries is proposed with
a low confidence.

| gallery | without the learning | with the learning | proposals (of) | the seconds |
|---|---|---|---|---|
| 348 (471) | 2.1 %, 2.1 % | **68.8 %** | 43 (of 48 tags hit) | 480 s (was 558 s) |
| 320 (154) | 28.1 % | **71.9 %** | 35 | 441 s (was 448 s) |
| 154 (53) | 38.5 % | **84.6 %** | 38 | 287 s (was 723 s) |
| 299 (3, control) | 0 %, 19.1 % | **85.7 %** | 38 | 309 s (was 443-854 s) |
| **the mean** | **22.9 %** | **77.8 %** | | **the same or faster** |

**+55 points of recall, at the same cost or less.** The learning is not
slower: the categories the editor rejects are never offered, and the ones
their vocabulary already contains arrive with the retrieval instead of from
the model.

The signal the learning used (seeded from the proposals and the audit log by
`bin/categorize_feedback_backfill.php`):

| the editor usually keeps | the editor usually removes |
|---|---|
| showing tits (8 kept, 1 removed) | public (1 kept, 6 removed) |
| exposed tits (6, 1) | public nudity (0, 5) |
| touching myself (3, 0) | naked in the hot tub (0, 3) |
| tits outside (2, 0) | in the car (0, 2) |

"public" and "public nudity" were the categories the editor removed most -
they are now never proposed, and "showing tits" (which they keep) is
proposed more confidently.

## The decisions

1. **The sampling stays as it is** (`CATEGORIZER_SAMPLE=head`,
   `CATEGORIZER_SAMPLE_MAX=3`). The mean got worse when it was spread.
2. **The learning is the feature** - it is what moved the number, and it is
   shipped (migration 080, `/admin/category-suggestions/learned`).
3. **The "describe" variant is parked**: it needs a bigger context window
   and a bigger timeout than the box can give, and the learning already
   covers what it was after.

## Two fixes the benchmark found on the way

- **`HTTP_TIMEOUT` was 420 s** and a single batch now needs longer than
  that (25-32 s per image plus a long answer). The calls were being cut off
  mid-flight and the batch was lost - in production too, not only in the
  benchmark. It is 900 s now.
- **`num_ctx`** was Ollama's default 4096, which is too small for more than
  three images (12 images are 12,576 tokens). `CategoryAdvisor::contextFor()`
  sizes the window from the number of images.

## Reproducing it

```bash
# on the production box, as www-data, detached (it holds the categorizer lock)
php bin/categorize_bench.php --galleries=348,320,154 --control=299 \
    --control-rounds=2 --variants=head3,spread3 --learn=0
php bin/categorize_bench.php --galleries=348,320,154 --control=299 \
    --variants=head3 --learn=1
```
