# AI providers and models

Alfred has its own client for the OpenAI Responses API and can run on any provider [LarAgent](https://laragent.ai) supports. Either way, Alfred's own agent loop, tools, safeguards, step limits and trace are the same.

## A different OpenAI model or a compatible gateway

Pick one of these. Each block sets the same keys, so don't combine them.

A larger model for better explanations:

```dotenv
ALFRED_OPENAI_MODEL=gpt-5
ALFRED_OPENAI_REASONING_EFFORT=medium
```

A non-reasoning model (leave both efforts empty):

```dotenv
ALFRED_OPENAI_MODEL=gpt-4.1-mini
ALFRED_OPENAI_REASONING_EFFORT=
ALFRED_EDIT_REASONING_EFFORT=
```

Your own gateway or proxy that exposes the OpenAI Responses API with a Bearer token:

```dotenv
ALFRED_OPENAI_BASE_URL=https://ai-gateway.internal.example.com/v1
ALFRED_OPENAI_API_KEY=gateway-key
```

## Claude, Gemini, Groq, Ollama or OpenRouter, through LarAgent

Alfred can run on any provider [LarAgent](https://laragent.ai) supports. LarAgent needs PHP 8.3+. Install it and publish its config:

```bash
composer require maestroerror/laragent
php artisan vendor:publish --tag="laragent-config"
```

Put the provider's API key where `config/laragent.php` expects it (e.g. `ANTHROPIC_API_KEY` for `claude`; OpenAI providers also fall back to `ALFRED_OPENAI_API_KEY`), then point Alfred at that provider:

```dotenv
ALFRED_PROVIDER=laragent
ALFRED_LARAGENT_PROVIDER=claude           # a key under "providers" in config/laragent.php
ALFRED_LARAGENT_MODEL=claude-sonnet-4-5   # optional: otherwise the provider's model from config/laragent.php
```

Local development with no API costs, using Ollama:

```dotenv
ALFRED_PROVIDER=laragent
ALFRED_LARAGENT_PROVIDER=ollama
ALFRED_LARAGENT_MODEL=qwen3:14b
```

Alfred uses only LarAgent's drivers. Its own agent loop, safeguards, step limit and trace stay the same whichever model you pick. Explanations and changes from small local models will be weaker than from frontier models.

**What each provider gets:**

| LarAgent provider | Forces the final answer on the last step | Reasoning effort |
|---|---|---|
| `openai_responses` (recommended for OpenAI) | yes | yes, same as Alfred's own client: `reasoning.effort`, with encrypted reasoning carried between steps and `store: false` |
| `default` (OpenAI Chat Completions), `openrouter`, `gemini` (Google's OpenAI-compatible endpoint), `ollama` | yes | `reasoning_effort`, sent only to reasoning models (o-series, gpt-5, gpt-oss, Gemini 2.5+, Qwen 3, DeepSeek R1) |
| `groq` | yes | same as above |
| `claude` | yes | not used: Claude's extended thinking can't be combined with forcing a tool, and LarAgent doesn't carry thinking between tool steps, which Anthropic requires |
| `gemini_native` | no (Alfred asks in words instead) | not available in the driver; prefer `gemini` |

Reasoning settings:

```dotenv
ALFRED_LARAGENT_REASONING=auto            # auto (as in the table) | on (always send it) | off
ALFRED_LARAGENT_REASONING_EFFORT=low      # explanations; changes use ALFRED_EDIT_REASONING_EFFORT (medium)
```

### Your config/laragent.php is respected

**Already using LarAgent? Don't repeat its settings for Alfred.** The rule: **how to reach a model comes from LarAgent; how Alfred does its job is Alfred's.** `config/laragent.php` stays the one place for providers, keys, URLs, models and fallbacks: with `ALFRED_PROVIDER=laragent` alone, Alfred uses the same provider(s) as your app's agents. What suits Alfred's task (long, precise answers about code) is not taken from your agents' tuning: the temperature and the like are left to the model unless you set `ALFRED_LARAGENT_TEMPERATURE`, and the token limit never goes below what Alfred needs. Add an `ALFRED_LARAGENT_*` variable only where Alfred should differ; none of them has to be set.

| In `config/laragent.php` (per provider) | Used by Alfred | Override with |
|---|---|---|
| `driver` (or `default_driver`) | yes | – |
| `api_key` | yes (OpenAI providers without one fall back to `ALFRED_OPENAI_API_KEY`) | – |
| `api_url` | yes, passed to the driver. LarAgent's `default` and `openai_responses` drivers always use api.openai.com; for a gateway use `OpenAiCompatible` (or Alfred's own client with `ALFRED_OPENAI_BASE_URL`) | – |
| `model` | yes | `ALFRED_LARAGENT_MODEL` (first provider only) |
| `default_max_completion_tokens` | yes when above 16000 (Alfred's minimum: LarAgent's stock 10000 would cut its explanations off) | `ALFRED_LARAGENT_MAX_TOKENS` (exact value, higher or lower) |
| `default_temperature`, `default_top_p`, `default_frequency_penalty`, `default_presence_penalty` | no: these tune your app's own agents (a chatbot may want a creative temperature; reading code doesn't). Alfred sends none, so the model's default applies | `ALFRED_LARAGENT_TEMPERATURE` |
| `parallel_tool_calls` | yes | – |
| `default_providers` (top level), with per-provider overrides | yes, when `ALFRED_LARAGENT_PROVIDER` is empty: first is used, the rest are fallbacks | `ALFRED_LARAGENT_PROVIDER=a,b,c` |
| `fallback_provider` (top level, older) | yes | – |
| `default_truncation_threshold`, chat history, storage, `track_usage`, MCP servers | no: these belong to LarAgent's agents. Alfred runs its own loop on LarAgent's drivers, keeps no chat history beyond one run, and counts usage itself (`alfred:usage`) | – |

**Fallbacks.** With several providers (`ALFRED_LARAGENT_PROVIDER=openai_responses,claude`, or LarAgent's `default_providers`), a provider that fails (an outage, a rate limit, a bad key) hands over to the next one for the rest of that explanation or change, and the switch is logged. A reply that was only cut off is retried on the same provider instead (see below).

`php artisan alfred:doctor` lists each provider Alfred will use, with its model, whether a key is set (never the key), and the token limit.

## When a reply is cut off

Every model has a limit on how much it can write in one reply. If a reply hits it, Alfred asks the model once more for a shorter one; if that is cut off too, the person sees a message naming the setting to raise (`ALFRED_LARAGENT_MAX_TOKENS` through LarAgent; Alfred's own OpenAI client sets no limit of its own). A tool call whose arguments arrive broken is never run: the model is told and calls it again.

Writing your own `LlmClient`? Throw `Atoria\Alfred\Llm\LlmCutOff` for a reply that was cut off, and pass each call's raw argument text as `raw`. You then get the same recovery.

## Usage and budgets

Every AI call is counted per day, person, tenant, kind (explanation, change, follow-up) and model:

```bash
php artisan alfred:usage                 # last 7 days by kind
php artisan alfred:usage --by=user --days=30
php artisan alfred:usage --by=model
```

Optional daily budgets stop new AI work once spent (cached explanations keep working), and reset at midnight:

```dotenv
ALFRED_DAILY_TOKENS=2000000            # each tenant (the whole app without tenancy)
ALFRED_DAILY_TOKENS_PER_USER=200000    # each person
```
