# AISymcon

LLM integration for IP-Symcon. Talk to your home automation or let a Symcon
script delegate decisions to an LLM, with a strict tool-based interface to the
Symcon object tree.

Two providers ship in v1:

- **LM Studio** (local, OpenAI-compatible) — default.
- **Anthropic Claude API** — opt-in.

The provider layer is a single PHP interface; adding more providers is one
file.

See [PROPOSAL.md](PROPOSAL.md) for the design rationale and the v1 decisions
(RAG indexing, audit identity, native tool calling, language handling, secret
redaction).

## Modules

| Module          | Purpose                                                   |
|-----------------|-----------------------------------------------------------|
| AISymconChat    | Persistent chat session, WebFront-facing.                 |
| AISymconAgent   | Stateless one-shot agent for scripts. `AISYMCON_Ask(...)` |

## Quick start

1. Install the module library via Module Control:
   `https://github.com/wilamowitz/AISymconPlugin`
2. Create an `AISymconAgent` instance.
3. Pick a provider (LM Studio at `http://localhost:1234/v1` is the default).
4. Pick a tool-calling-capable model. Recommended local minimums:
   - Qwen2.5 Instruct 7B+
   - Llama 3.1 Instruct 8B+
   - Mistral-Nemo Instruct
   - Hermes 3
5. Click **Test connection**. It executes one tool round-trip and reports
   model + latency.
6. From any Symcon script:
   ```php
   $answer = AISYMCON_Ask($agentID, "Welche Lampen sind im Wohnzimmer an?");
   ```

## Scopes

| Scope        | Read tree | Read values | Set values | Run scripts | Edit objects |
|--------------|:---------:|:-----------:|:----------:|:-----------:|:------------:|
| `read_only`  | yes       | yes         |            |             |              |
| `control`    | yes       | yes         | yes        |             |              |
| `automation` | yes       | yes         | yes        | yes         |              |
| `full`       | yes       | yes         | yes        | yes         | yes          |

Dry-run is on by default. Write operations return what *would* happen until
dry-run is disabled.
