# Model availability, alternatives and fallback

What happens when a model you selected cannot answer — and how to make choosing
a replacement a two-click decision instead of an investigation.

| | |
|---|---|
| Backend | `src/Models/Fallback/` (8 classes) |
| HTTP | `ModelFallbackController` — 12 routes under `/api/models/…` |
| UI | **Models → Fallback & alternatives** (`assets/js/fallback.js`) |
| Schema | `revoap_model_availability`, `revoap_fallback_rules` (migration 023) |
| Tests | `tests/ModelFallbackTest.php` (134 assertions) |

---

## 1. The problem this solves

`App\Connectors\ModelBreaker` already answers *"is this model still worth calling
inside **this** run?"* — and it is deliberately in-memory and per-run, because
state that outlives an incident is stale state. Its own docblock says a cross-run
breaker "would need a store and a recovery policy; that is a different feature
and is not smuggled in here."

That different feature is what this is. Without it, two things happen on every
run:

1. A provider that has been down for twenty minutes is **rediscovered from
   scratch** each time, by paying the timeout again.
2. The consensus pool the operator configured **silently runs short** — and a
   five-voice council that quietly becomes four changes what the consensus score
   means.

`ModelSubstitution` (v1.x) already planned replacements at pool-assembly time. It
took the *first* candidate whose vendor was not already in the pool, which is the
right idea and the wrong tie-break: it would happily substitute a 4k-context model
for a 200k-context one, or a model costing 40× more, because both are "a
different vendor". When the substitution is silent, the operator discovers it in
the invoice or in a truncated answer.

---

## 2. Availability: five states, windowed and decaying

```
Healthy  →  Unknown  →  Degraded  →  Unavailable  →  Retired
  (4)        (3)         (2)            (1)            (0)
```

`AvailabilityStatus::rank()` defines the order and `worseThan()` uses it, so
"should this replace that?" is one comparison rather than a re-derived rule at
every call site.

* **Unknown** is treated as *usable*. A model nobody has probed is not a broken
  model, and refusing it would empty a fresh install's pool. It does sort below
  Healthy when both are candidates.
* **Retired** is *sticky*. It records an administrator's decision ("we are not
  allowed to use this any more") or a catalogue fact ("the provider removed it"),
  not a transient health measurement. One lucky successful call must not undo
  either — which is a bug this codebase had, and `tests/ModelFallbackTest.php`
  now asserts against.

Counters are **windowed, not lifetime**. `AvailabilityRepository::applyDecay()`
halves them per elapsed window on every *read*, so a model that failed 400 times
last month and has answered the last 50 calls converges back to Healthy without a
scheduled job to forget. Doing it on read rather than in a cron task means there
is no job to forget to install.

Latency is an exponential moving average (α = 0.3). A plain window mean would let
one 40 s timeout dominate the verdict for the whole window.

A hard streak overrides the ratio: **3 consecutive failures** mean Unavailable
regardless of a 99 % lifetime rate, because something just broke.

---

## 3. Ranking: five signals, hard constraints first

`AlternativeResolver` is **pure** — no database, no network, no config reads. A
ranking rule that cannot be exercised in isolation is a ranking rule nobody
trusts. The caller supplies catalogue rows, verdicts and a policy.

Every candidate gets a `0..1` **fit score** built from five weighted signals:

| Signal | Weight | Rewards |
|---|---|---|
| `vendor` | 0.22 | A vendor not already in the pool — a council from one house agrees with itself |
| `context` | 0.20 | Equal or larger context window (more is capped at 1.0: a 1M window is not *better* than 200k for a 4k prompt, it is usually just dearer) |
| `reliability` | 0.26 | Measured success rate; Healthy > Unknown > Degraded |
| `latency` | 0.14 | Faster than the model being replaced |
| `price` | 0.18 | Cheaper, normalised against the original |

Weights sum to 1.0 so the score reads directly as a percentage and retuning one
signal cannot silently inflate the total.

### Hard vs. soft

| Rule | Kind | Why |
|---|---|---|
| `respect_context_window` | **hard** | Shrinking the window truncates the prompt. That is data loss, and no score justifies it. |
| `same_vendor_only` | **hard** | If pricing and capability parity is required, a cross-vendor pick is simply wrong. |
| `max_cost_multiplier` | soft → hard via `cost_is_hard_limit` | A run that needs five voices should not fail because the only affordable replacement is 1.6× instead of 1.5×. |
| `max_latency_ms` | **hard** | A slow replacement defeats the point of parallel dispatch. |
| `prefer_vendor_diversity` | soft | Genuinely better same-vendor models should still win over worse diverse ones. |
| `allow_degraded_substitutes` | **hard** (off by default) | A flaky voice weakens a consensus in a way the score cannot see. |

Hard-excluded candidates are **not returned at all**, so the UI cannot offer a
swap that the policy forbids.

### The components are the point

`AlternativeCandidate::$components` ships all five sub-scores next to the total.
A bare number invites "why 87 and not 91" and cannot be argued with; five named
components can. The UI renders them as bars, and the audit trail records them, so
*"why did my run use a different model?"* has an answer that does not require
reading the resolver's source.

---

## 4. Pool planning: nothing disappears quietly

`planPool()` returns a `SubstitutionPlan` carrying **three** lists, each of which
exists because dropping it would hide something:

* `pool` — the models that will actually run;
* `replaced` — every `from → to` swap with its score and component breakdown;
* `unfilled` — slots that could **not** be filled, with the reason.

`unfilled` is the list that makes "you asked for five, you got four" impossible to
miss.

Two invariants the tests enforce:

* **Two broken models never get the same replacement.** Chosen candidates are
  removed from the remaining pool as slots are processed.
* **An unfilled slot is dropped from the effective pool, not left holding a model
  that cannot answer.** Leaving it in would make `effective()` count a voice the
  run does not have — precisely the silent short-council this plan exists to
  prevent. `requested()` and `effective()` are tracked separately, so
  `isShort()` is a real signal rather than arithmetic that double-counts.

### The four modes

| Mode | Substitutes? | On a gap |
|---|---|---|
| `never` | no | Runs short, reports the gap. The pre-v2 behaviour. |
| `auto` *(default)* | yes | Substitutes, then reports every swap with the run. |
| `ask` | yes, after confirmation | Pauses and offers the alternatives. Nothing is chosen for you. |
| `strict` | **no** | Aborts. A missing member is worse than no answer. |

`auto` is the default because it is the only mode that both keeps the council at
its configured size — so the consensus score still means what it says — *and*
reports every substitution, so nothing changes behind the operator's back.

Asking the enum (`$mode->substitutes()`) rather than comparing against one case is
what keeps a future mode from silently inheriting substitution it should not have.
That was a live bug: `strict` used to substitute *and then* abort, i.e. it did the
thing the operator explicitly forbade and failed anyway.

---

## 5. Unavailability reasons drive behaviour, not just text

`UnavailableReason` is not cosmetic. Each case carries three predicates:

| | `transient()` | `probeWorthy()` | `substitutable()` |
|---|---|---|---|
| `KeyMissing` | no | yes | **no** |
| `Removed` | no | **no** | **no** |
| `Disabled` | no | **no** | **no** |
| `Quota` | yes | yes | yes |
| `Breaker` | yes | yes | yes |
| `Timeout` | yes | yes | yes |
| `ProviderError` | yes | yes | yes |
| `ContentFiltered` | yes | yes | yes |
| `ContextTooSmall` | no | yes | **no** |
| `Unverified` | no | yes | yes |

* `transient()` decides whether to retry and what message to show. "The provider
  no longer serves this model" suggests refreshing the catalogue; "quota
  exhausted" suggests topping up, and no amount of refreshing helps.
* `probeWorthy()` stops the monitor from producing a permanent 404 against the
  operator's own provider account every five minutes.
* `substitutable()` excludes configuration facts (`KeyMissing`, `Disabled`) from
  replacement duty — offering them would just move the failure to a different
  slot.

`AvailabilityMonitor::classify()` maps error text onto these. Matching on text is
inherently fragile, so the patterns are ordered most-specific-first and **the
default is `ProviderError` (transient), never `Removed` (permanent)**. Guessing
"removed" from a network blip would retire a healthy model.

---

## 6. The monitor

The pipeline records every real call, which is enough for popular models. It is
not enough for:

1. **A model nobody has called for a week** — its verdict is a week old, and if
   the provider retired it in the meantime the next run pays a full timeout to
   find out.
2. **A model that just recovered** — its counters are still full of the outage, so
   it keeps being substituted out long after it is fine. A false `Unavailable`
   silently shrinks every council.

`AvailabilityMonitor` closes both gaps with a scheduled probe: a minimal chat call
(`"ping"`, `max_tokens: 1`, temperature 0, 12 s timeout) costing a fraction of a
cent. Deliberate limits:

* **Concurrency capped** (default 4). Probing 200 models at once looks like an
  attack and gets the key rate-limited — which then makes every model look
  Unavailable.
* **Real traffic beats a synthetic ping.** A model that answered successfully
  inside the window is skipped.
* **Retired and disabled models are never probed.**
* **A probe never throws.** A monitor that can take down the request it runs in is
  worse than no monitor.
* Probes are recorded through a separate path from real calls, so a cheap ping
  cannot overwrite a verdict built from hundreds of real responses.

Due-selection prioritises a currently-`Unavailable` model over a healthy-but-quiet
one, for exactly the reason in (2).

---

## 7. Operator rules

A score is a good default and a bad policy. An operator who knows that
`gpt-5.5-pro` must **never** be replaced by something cheaper needs a hard
statement, not a weight. `revoap_fallback_rules` holds them, evaluated in
`priority` order before the scored ranking:

```
when  match_model / match_provider / match_reason
then  substitute <action_model> | never | abort
```

Rules live in the runtime overlay by default (`models.fallback.rules`) so a
single-node install needs no migration applied before the feature works, and so
one mental model covers every other operator setting. The table exists for a
multi-node deployment, where every node must see the same rules.

---

## 8. HTTP surface

| Route | Gate | Purpose |
|---|---|---|
| `GET /api/models/availability/summary` | **public** | Statuses for the models in the Chat pool, so the picker flags a dead one *before* Run is pressed |
| `GET /api/models/availability` | admin | Full verdict table |
| `POST /api/models/availability/probe` | admin | Run the monitor; `?ids=` for specific models |
| `POST /api/models/availability/reset` | admin | Forget a verdict — "I fixed the key, stop treating this as dead" |
| `POST /api/models/availability/retire` | admin | Retire / un-retire |
| `GET /api/models/alternatives?model=…&pool=…` | admin | Ranked candidates with score breakdown |
| `POST /api/models/alternatives/plan` | admin | Dry run over the whole pool |
| `GET`/`POST /api/models/fallback/policy` | admin | Mode + soft/hard rules |
| `GET`/`POST`/`DELETE /api/models/fallback/rules` | admin | Operator overrides |

The one public endpoint returns **statuses and counts only** — never pricing,
never catalogue detail, never a key fingerprint, never error text (which can
contain a provider response body and therefore an account id). `tests/LiveSmoke.php`
asserts that against real response bytes, so it cannot widen by accident.

Policy writes go through `RuntimeConfig::updateChecked()` and **report the
write**: an overlay that cannot be written (read-only filesystem, wrong owner on
`var/`) returns `persisted: false` rather than claiming success. Reporting "saved"
while the file was never written is how an operator ends up debugging a policy
they can see in the UI and that the pipeline does not apply.

Every response is `no-store` — an availability verdict is by definition
time-sensitive, and a shared proxy caching it would show a dead model as healthy
for as long as the proxy felt like it.

---

## 9. Configuration

```php
// config.php or .config.php
'models' => [
    'fallback' => [
        'mode' => 'auto',                    // never|auto|ask|strict
        'prefer_vendor_diversity' => true,
        'same_vendor_only' => false,
        'respect_context_window' => true,    // hard — do not turn off casually
        'allow_degraded_substitutes' => false,
        'max_latency_ms' => 0,               // 0 = no ceiling
        'max_cost_multiplier' => 1.5,
        'cost_is_hard_limit' => false,
        'max_alternatives' => 6,
        'chains' => [                        // hard, ordered overrides
            'openai/gpt-5.5' => ['anthropic/claude-opus-5', 'google/gemini-3.1-pro'],
        ],
        'monitor' => [
            'enabled' => true,
            'interval_seconds' => 300,       // clamped to 30..86400
            'window_seconds' => 3600,        // clamped to 60..604800
            'degraded_below' => 0.85,
            'unavailable_below' => 0.40,
            'min_samples' => 5,
        ],
        'rules' => [ /* operator overrides */ ],
    ],
],
```

Every value is coerced and clamped in `FallbackPolicy::fromConfig()`. A runtime
overlay an operator edits by hand must not be able to produce a negative
multiplier (which disables every candidate) or a zero-second probe interval
(which is a denial of service against the operator's own provider quota).

Without a database the whole subsystem still works: verdicts are then `Unknown`
for everything, ranking proceeds on catalogue metadata alone, and a
request-scoped in-memory store keeps the resolver usable. That is the same
contract the rest of RevoAp's repositories follow.
