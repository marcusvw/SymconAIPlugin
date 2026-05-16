# AISymconPlugin — Proposal

An IP-Symcon module that exposes the Symcon object tree to a Large Language Model (LLM)
so the user can:

1. **Chat-control** the home automation (interactive, multi-turn conversation).
2. **Script-control** via a single-shot "agent call" from a Symcon PHP script
   (one request → internal tool loop → result returned to the script).

The LLM backend is **provider-agnostic**: local **LM Studio** (default) and **Anthropic
Claude API** are the first two implementations, but adding further providers
(Ollama, OpenAI-compatible, Mistral, …) must be a small, isolated change.

---

## 1. Goals

- Single Symcon module the user installs from a Git module store URL.
- Configurable LLM provider, endpoint, model, temperature, system prompt, tool
  permission scope (read-only / control / full).
- A well-defined **tool interface** that lets the LLM navigate and manipulate the
  Symcon object tree (categories → instances → variables → scripts → events).
- Two entry points:
  - **Chat** — WebFront / Symcon Dashboard "AI Console" with conversation history.
  - **Scripting API** — `AISYMCON_Ask($instanceID, $prompt)` callable from any
    Symcon PHP script; runs a bounded tool loop and returns a string or structured
    result.
- Local-first: with LM Studio nothing leaves the LAN. Claude API is opt-in.

## 2. Non-Goals (v1)

- No streaming voice / STT-TTS (could be added later via existing Symcon modules).
- No multi-user RBAC beyond the Symcon WebFront session.
- No vector store / RAG — the object tree itself is the "knowledge base" and is
  queried lazily through tools.

---

## 3. High-Level Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                     IP-Symcon (PHP 8.x)                      │
│                                                              │
│  ┌──────────────────┐    ┌────────────────────────────────┐  │
│  │  AISymconChat    │    │   AISymconAgent (scripting)    │  │
│  │  (instance)      │    │   AISYMCON_Ask(id, prompt)     │  │
│  └────────┬─────────┘    └──────────────┬─────────────────┘  │
│           │                             │                    │
│           └──────────┬──────────────────┘                    │
│                      ▼                                       │
│            ┌─────────────────────┐                           │
│            │   AgentRuntime      │  tool loop, history,      │
│            │   (PHP class)       │  budget, safety checks    │
│            └────────┬────────────┘                           │
│                     │                                        │
│        ┌────────────┼─────────────────────┐                  │
│        ▼            ▼                     ▼                  │
│  ┌──────────┐  ┌──────────────┐   ┌───────────────────┐      │
│  │ ToolKit  │  │ LLMProvider  │   │ Audit / Logging   │      │
│  │ (Symcon  │  │ (interface)  │   │ (Symcon log +     │      │
│  │  tools)  │  │              │   │  optional file)   │      │
│  └──────────┘  └──────┬───────┘   └───────────────────┘      │
│                       │                                      │
│            ┌──────────┴───────────┐                          │
│            ▼                      ▼                          │
│      LMStudioProvider     ClaudeProvider     (future …)      │
│      (OpenAI-compatible)  (Anthropic SDK / REST)             │
└──────────────────────────────────────────────────────────────┘
```

### 3.1 Module layout

Standard Symcon module repository:

```
AISymconPlugin/
├── library.json
├── README.md
├── CHANGELOG.md
├── AISymconChat/                # Instance: persistent chat session
│   ├── module.json
│   ├── module.php               # extends IPSModule
│   ├── form.json
│   └── locale.json
├── AISymconAgent/               # Instance: one-shot agent for scripts
│   ├── module.json
│   ├── module.php
│   ├── form.json
│   └── locale.json
└── libs/
    ├── AgentRuntime.php
    ├── ToolKit.php
    ├── Tools/                   # one file per tool group
    │   ├── TreeTools.php
    │   ├── VariableTools.php
    │   ├── InstanceTools.php
    │   ├── ScriptTools.php
    │   └── ProfileTools.php
    └── Providers/
        ├── LLMProvider.php      # interface + DTOs
        ├── LMStudioProvider.php
        ├── ClaudeProvider.php
        └── Transport.php        # cURL helpers, retries, timeouts
```

Two instance types are deliberately separated: a chat instance keeps long-lived
state (history, last tool result, token usage); the agent instance is stateless
per call and optimized for scripting.

---

## 4. The LLM Provider Abstraction

A minimal interface that hides provider-specific tool/function-calling formats:

```php
interface LLMProvider {
    /** Provider metadata for the UI/dropdowns. */
    public function getName(): string;
    public function listModels(): array;        // optional, may return []

    /**
     * Run one LLM round-trip.
     *
     * @param Message[]  $messages  full conversation so far
     * @param ToolSpec[] $tools     declared tools (JSON-schema-ish)
     * @param array      $opts      ['model'=>..., 'temperature'=>..., 'max_tokens'=>...]
     * @return LLMResponse         either { text } or { toolCalls: ToolCall[] }
     */
    public function chat(array $messages, array $tools, array $opts): LLMResponse;
}
```

Shared DTOs (`Message`, `ToolSpec`, `ToolCall`, `LLMResponse`) live in
`Providers/LLMProvider.php`. Each concrete provider is responsible for:

- mapping the canonical `Message` / `ToolSpec` into its wire format,
- parsing tool calls back into canonical `ToolCall` objects,
- normalizing errors (timeout, rate-limit, auth, bad-tool-args) into a single
  `LLMException` hierarchy so the `AgentRuntime` is provider-agnostic.

### 4.1 LMStudioProvider

LM Studio exposes an **OpenAI-compatible** REST API on `http://localhost:1234/v1`.
We hit `/v1/chat/completions` with `tools` + `tool_choice="auto"`. Auth is
optional (LM Studio supports a static API key). Models discoverable via `/v1/models`.

### 4.2 ClaudeProvider

Direct call to `https://api.anthropic.com/v1/messages` with the Anthropic
tool-use format (`tools: [{name, description, input_schema}]`, response contains
`content` blocks of type `tool_use`). Uses prompt caching for the (large) tool
manifest and any pinned object-tree snapshot to keep cost down. API key stored
in module configuration; never logged.

### 4.3 Adding a third provider

Implement `LLMProvider`, register in a small factory keyed by the form
dropdown value. No changes to `AgentRuntime` or any tool.

---

## 5. The Symcon Tool Interface

Tools are the **only** way the LLM touches Symcon. Each tool is a PHP method
with a JSON-schema description; the toolkit hands the list to the active
provider on every request. Tools are grouped by capability and gated by the
permission scope configured on the instance:

| Scope          | Read tree | Read values | Set values / call actions | Run scripts | Create/edit objects |
|----------------|:---------:|:-----------:|:-------------------------:|:-----------:|:-------------------:|
| `read_only`    | ✓         | ✓           |                           |             |                     |
| `control`      | ✓         | ✓           | ✓                         |             |                     |
| `automation`   | ✓         | ✓           | ✓                         | ✓           |                     |
| `full`         | ✓         | ✓           | ✓                         | ✓           | ✓                   |

### 5.1 Initial tool catalog (v1)

**Tree / discovery**
- `tree_root()` → root children with id, name, type.
- `get_children(parent_id)` → direct children (lazy walk — avoids dumping the
  entire tree into the prompt).
- `get_object(id)` → `{id, name, type, parentID, info, hasChildren}` via
  `IPS_GetObject`.
- `search(query, type?, limit?)` → name/ident substring search, used as the
  primary entry point ("turn off the kitchen lights").
- `get_path(id)` → human-readable path (`"Erdgeschoss / Wohnzimmer / Licht"`).

**Variables**
- `get_variable(id)` → value + type + profile + last update.
- `set_variable(id, value)` → writes via `RequestAction` if the variable has
  an action, else `SetValue`. Profile-based type coercion + range check.
- `get_profile(name)` → associations (so the LLM can translate "warm white"
  into the underlying integer).

**Instances**
- `get_instance(id)` → module GUID, module name, status, configuration keys
  (values redacted for secret fields).
- `list_instances(module_guid?)` → filter by module.
- `request_action(instance_id, ident, value)` → generic action call.

**Scripts (scope ≥ automation)**
- `run_script(id, params?)` → `IPS_RunScriptEx`, returns result + log lines.
- `get_script_source(id)` (read-only by default; useful for "explain this script").

**Object management (scope = full, off by default)**
- `create_variable`, `create_event`, `set_position`, `set_parent`, `delete_object`.
  Each one requires an extra "destructive operation" confirmation flag set on
  the instance and is logged with the full call args.

All tools return JSON-serializable arrays. Errors return
`{error: "...", code: "..."}` so the model can recover gracefully.

### 5.2 Safety rails

- Per-call **budgets**: max tool calls, max tokens, max wall-clock seconds.
- **Allow/deny lists** by object ID or category subtree (e.g. exclude alarm
  system, exclude `Energy/Meters`).
- **Dry-run mode**: every write tool returns what *would* happen without
  executing. Toggleable per call from script API, default on for the first
  N minutes after install.
- **Audit log**: every tool call (tool, args, result hash, instance, user)
  written to a Symcon log category and optionally a rotating file.

---

## 6. The Two Entry Points

### 6.1 Chat (AISymconChat instance)

- One instance per chat session (so multiple personas / scopes can coexist).
- Variables on the instance:
  - `History` (string, JSON-encoded message array, trimmed to N tokens).
  - `LastResponse` (string).
  - `Busy` (bool).
  - `InputPrompt` (string, with action → triggers the agent).
- WebFront/Dashboard tile: a simple chat UI built with the standard Symcon
  HTMLBox + a small JS frontend, posting to `RequestAction("Send", prompt)`.
- Streaming is optional: provider may return tokens incrementally, but v1 can
  ship as request/response.

### 6.2 Scripting API (AISymconAgent instance)

```php
$result = AISYMCON_Ask($agentInstanceID, "Turn off all lights in the kitchen if nobody is there");
// $result is the assistant's final text, after the tool loop terminated.

$json = AISYMCON_AskStructured(
    $agentInstanceID,
    "Return JSON {room, temperature_c} for every room thermostat",
    /* schema */ '{"type":"array", "items":{...}}'
);
```

- Stateless per call; the system prompt + tool catalog + (optional) pinned
  context snippets are assembled fresh each time.
- The runtime loops: `LLM → tool calls → execute → feed back → LLM …` until
  the model emits a final message or a budget is hit.
- Errors propagate as PHP exceptions or as a `["error" => ...]` result,
  configurable per instance.

---

## 7. Configuration (form.json highlights)

- **Provider**: dropdown (LM Studio, Claude, …).
- **Endpoint / API key / Model**: shown conditionally.
- **System prompt**: textarea with a sensible default ("You control IP-Symcon
  via the provided tools. Prefer `search` and `get_children` over guessing IDs.
  Confirm destructive actions.").
- **Scope**: `read_only | control | automation | full`.
- **Allowed root IDs**: multi-select object picker; empty = whole tree.
- **Budgets**: max tool calls (default 16), max tokens (default 8k), timeout (60s).
- **Audit**: log category picker + verbosity.
- **Dry-run**: bool, default true on install.

A "Test connection" button in the form runs a minimal `chat()` call against the
configured provider and prints model + latency + sample tool round-trip.

---

## 8. Implementation Plan

| Phase | Deliverable                                                                  |
|-------|------------------------------------------------------------------------------|
| 0     | Repo scaffold, `library.json`, CI lint, dev install via Module Control       |
| 1     | `LLMProvider` interface + `LMStudioProvider` + manual chat console (no tools)|
| 2     | `ToolKit` with read-only tools (`tree_root`, `get_children`, `search`, `get_variable`) and the agent loop |
| 3     | Write tools (`set_variable`, `request_action`), scope gating, dry-run, audit |
| 4     | `ClaudeProvider` + provider switch in form                                   |
| 5     | `AISymconAgent` scripting entry point + structured-output helper             |
| 6     | WebFront chat tile                                                           |
| 7     | Full-scope tools (object creation), confirmation flag, docs + examples       |
| 8     | Optional: streaming, multi-language system prompts, prompt caching tuning    |

## 9. Decisions (resolved open questions)

These were left open in an earlier draft and have since been decided. They
are now binding for v1 unless explicitly revisited.

### 9.1 Object-tree context size — **RAG from day one**

Even with lazy walking, large Symcon installs would force the model into many
sequential tool calls just to locate the right object. v1 ships with an
embedding-based index of the object tree.

- **Indexed fields**: per object, the concatenation of `name`, ident, parent
  path (e.g. `"Erdgeschoss / Wohnzimmer / Licht"`), object type, and — for
  variables — profile name and last value type. Optional: short user-supplied
  description stored in a custom info attribute.
- **Embedding source**: pluggable, defaults to the active `LLMProvider` if it
  offers an embeddings endpoint (LM Studio: `/v1/embeddings`; Anthropic: via
  a configurable third party or a small local sentence-transformer model
  shipped as an optional dependency). The index is provider-agnostic — vectors
  are stored alongside their model id so a provider switch triggers a rebuild.
- **Storage**: a single binary flat file (`<kernel>/media/AISymcon/<instanceID>/index.bin`)
  with packed little-endian float32 vectors and a fixed-format header. Stock
  IP-Symcon on Windows does **not** ship the SQLite3 PHP extension, so a
  SQLite-backed index would not work out-of-the-box; cosine similarity is
  computed in PHP over the in-memory map loaded once per search call.
- **Maintenance**: full rebuild on demand from the form ("Rebuild index"),
  incremental updates driven by Symcon kernel messages
  (`IPS_KERNELMESSAGE` / object created / renamed / deleted).
- **New tool**: `semantic_search(query, k=8, type?)` returns the top-k object
  IDs with score. Becomes the *primary* discovery tool; `search` (substring)
  and `get_children` remain for deterministic walks.

Implementation moves into Phase 1.5 of the plan (between the read-only tools
and the agent loop) so the agent ships with `semantic_search` available from
the first tool-capable build.

### 9.2 Audit identity — **Both: actor + caller**

Every audit entry contains two identity fields:

- `actor` — the configured "actor name" on the AISymcon instance (e.g.
  `kitchen-assistant`, `night-mode-agent`). Mandatory form field, defaults
  to the instance name.
- `caller` — best-effort origin of the request:
  - Script API → calling script's object ID (`$_IPS['SELF']` captured at
    `AISYMCON_Ask` entry).
  - WebFront chat → session user / WebFront connection ID if exposed by
    Symcon, else the literal `webfront`.
  - Internal (timer / event) → `internal:<event_id>`.

Audit record shape:

```json
{
  "ts": "2026-05-16T14:22:01+02:00",
  "instance": 12345,
  "actor": "kitchen-assistant",
  "caller": "script:67890",
  "tool": "set_variable",
  "args": {"id": 23456, "value": false},
  "result": "ok",
  "dry_run": false,
  "scope": "control"
}
```

### 9.3 Tool-call compatibility — **Native only, document minimum model**

v1 requires a provider/model with native tool calling. No JSON-in-text
fallback ships in v1 — it doubles the runtime surface and adds prompt-engineering
debt that would be hard to remove later.

- **Documented minimum models** for LM Studio (kept in README, not in code):
  Qwen2.5 (7B+ Instruct), Llama 3.1 (8B+ Instruct), Mistral-Nemo Instruct,
  Hermes 3. The "Test connection" button performs one round-trip with a
  trivial tool and fails loudly with a clear error if the model can't tool-call.
- **Claude**: any current Sonnet/Opus/Haiku family model qualifies.
- A future v2 may add a JSON-in-text mode; the `LLMProvider` interface is
  designed so that becomes a new provider implementation, not a fork of the
  runtime.

### 9.4 Localization — **Form setting (EN / DE)**

Language is a single dropdown on each instance (`en` | `de`, default `de`
given the user base). The choice drives:

- the system prompt template loaded by the runtime,
- the `description` field of every tool spec (descriptions live in
  `libs/Tools/locales/{en,de}.json` keyed by tool name),
- error messages returned to the model (so it can echo them coherently).

Tool **names** and **argument schemas** stay English-only — they are the
contract with the provider, not user-facing text. No auto-detection in v1;
deterministic and trivially testable.

### 9.5 Secrets in `get_instance` — **Heuristic + per-GUID deny-list**

Two layers, applied in order:

1. **Per-GUID deny-list** in `libs/Tools/instance_secrets.json`:
   ```json
   {
     "{MODULE-GUID-…}": ["APIKey", "Password", "AccessToken"],
     "...": ["..."]
   }
   ```
   Maintained in-repo; users can override via a module setting that points to
   an additional JSON file outside the module directory (so upgrades don't
   clobber local edits).
2. **Heuristic** on the field name applied to all remaining fields,
   case-insensitive regex:
   `/(pass(word)?|secret|token|api[_-]?key|client[_-]?secret|credential|pin)/i`.

Redaction replaces the value with the literal string `"<redacted>"` and
adds `_redacted: ["FieldA", "FieldB"]` to the returned object so the model
knows fields exist but were withheld. The deny-list **cannot** be disabled;
the heuristic can be turned off only when `scope = full` AND a separate
`expose_secrets` flag is set on the instance (off by default, surfaces a
warning in the form).

---

## 10. Out-of-Scope / Future

- Voice input via Symcon's existing speech modules feeding into the chat instance.
- "Explain this automation" feature: feed an event / script source to the LLM
  with read-only tools.
- Proactive agent: a timer-triggered "what would you suggest?" run that posts
  proposals into a Symcon variable for the user to approve.
- Multi-provider routing (cheap local model for tool-heavy steps, Claude for
  the final synthesis).
