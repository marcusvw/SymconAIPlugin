# AISymcon

LLM integration for IP-Symcon. Talk to your home automation or let a Symcon
script delegate decisions to an LLM, with a strict tool-based interface to the
Symcon object tree.

> **Hinweis / Disclaimer**
>
> Dies ist ein **privates Hobby-Projekt** — keine Garantie, kein Support, kein
> SLA, keine kommerzielle Nutzung impliziert. Die Verwendung erfolgt
> **auf eigenes Risiko**. Ein LLM kann Geräte falsch steuern, falsche
> Werte schreiben oder Skripte unerwartet ausführen. Vor produktivem Einsatz
> in `read_only` testen, sensible Bereiche per `AllowedRoots` ausgrenzen
> und Dry-run nur deaktivieren, wenn du verstanden hast, was passiert.
>
> *This is a personal hobby project, provided as-is without warranty.
> Use at your own risk.*

Two providers ship today:

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
   `https://github.com/marcusvw/SymconAIPlugin`
2. Create an `AISymconAgent` (or `AISymconChat`) instance.
3. Pick a provider (LM Studio at `http://localhost:1234/v1` is the default).
4. Pick a tool-calling-capable model. Recommended local minimums:
   - Qwen2.5 Instruct 7B+
   - Llama 3.1 Instruct 8B+
   - Mistral-Nemo Instruct
   - Hermes 3
5. Click **Test connection**. It executes one tool round-trip and reports
   model + latency.
6. (Optional) Click **Rebuild index** so `semantic_search` works — see
   [Embeddings](#embeddings) below.
7. From any Symcon script:
   ```php
   $answer = AISYMCON_Ask($agentID, "Welche Lampen sind im Wohnzimmer an?");
   ```

## Providers

### LM Studio (local)

Default. Set **Endpoint** to your LM Studio server URL (typically
`http://localhost:1234/v1`). API key is optional and only required if you
enabled one in LM Studio. The plugin uses LM Studio for both chat **and**
embeddings — load a chat-capable model AND an embedding model in LM Studio
before using `semantic_search`.

### Anthropic Claude API

Set **Provider** to `Anthropic Claude API` and provide an API key
(`sk-ant-…`) from <https://console.anthropic.com/settings/keys>. The
endpoint is hard-wired to `https://api.anthropic.com/v1/messages` — the
**Endpoint** field in the form is ignored for this provider.

Recommended models: `claude-haiku-4-5-20251001` (cheap, fast, sufficient
for this use case) or `claude-sonnet-4-6`.

Anthropic does **not** provide an embeddings API, so `semantic_search`
is unavailable when Claude is the active provider — the tool returns
`index_unavailable` and the agent falls back to `search` (substring) and
`get_children`. This works on small trees but is less helpful for fuzzy
natural-language references.

## Embeddings

`semantic_search` is the agent's preferred way to find objects by meaning
(e.g. "Wohnzimmer-Lampen" → variable IDs). It uses an embedding model that
runs **inside the active provider**, not on the Symcon host directly:

| Provider     | Embedding endpoint                       | Status                           |
|--------------|------------------------------------------|----------------------------------|
| LM Studio    | `POST {Endpoint}/embeddings`             | Works — load an embedding model. |
| Claude API   | none                                     | `semantic_search` disabled.      |

### LM Studio setup

1. In LM Studio, load both a chat model AND an embedding model.
   Common choices for embeddings:
   - `nomic-embed-text-v1.5` (default in the form)
   - `bge-small-en-v1.5`
   - `bge-m3`
2. Enter the embedding model's exact LM Studio identifier into the
   plugin's **Embedding model** field.
3. Click **Rebuild index** in the instance form. The index is stored as
   a packed binary file under
   `<Symcon kernel dir>/media/AISymcon/<instanceID>/index.bin` and is
   rebuilt on demand. SQLite is not required.

## Scopes

| Scope        | Read tree | Read values | Set values | Run scripts | Edit objects |
|--------------|:---------:|:-----------:|:----------:|:-----------:|:------------:|
| `read_only`  | yes       | yes         |            |             |              |
| `control`    | yes       | yes         | yes        |             |              |
| `automation` | yes       | yes         | yes        | yes         |              |
| `full`       | yes       | yes         | yes        | yes         | yes          |

Dry-run is on by default. Write operations return what *would* happen
until dry-run is disabled.

## Debugging

Every interaction is mirrored to the instance's **Debug** tab in the
Management Console — agent start/end, every LLM round-trip, every tool
call with arguments and result. Increase **Audit verbosity** to
`Verbose` for full tool payloads.

## License & contributions

Hobby project. No support promise. Issues and PRs are welcome but may
be ignored. Use at your own risk.
