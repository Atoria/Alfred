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
ALFRED_LARAGENT_MAX_TOKENS=16000          # per answer; raise it if you see "the answer was cut off"
```

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
