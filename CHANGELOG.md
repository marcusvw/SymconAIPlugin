# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.2] - 2026-05-16

### Fixed
- Chat tile was not appearing — Symcon's HTML-SDK (≥ 7.1) requires the
  module to call `SetVisualizationType(1)` in `Create()` to register as
  a custom-HTML instance. Without it Symcon falls back to the default
  variable list.
- `handleMessage(value)` in the tile JS now expects a single JSON-string
  argument (the actual HTML-SDK shape), not a `{SenderID, Data}` event
  object.
- `AISymconChat` now pushes its state to open tiles via
  `UpdateVisualizationValue()` after every send / reset / busy
  transition so the bubbles update live.

## [0.2.1] - 2026-05-16

### Fixed
- `module.json` `type` changed from `0` (Core) to `3` (Device) on both
  `AISymconChat` and `AISymconAgent` so the instances show up in the
  Tile Editor's picker and in the standard "add instance" dialog.

## [0.2.0] - 2026-05-16

### Added
- WebFront/Tile chat UI for `AISymconChat`: bubble layout, Enter-to-send,
  Reset button, optional "show tool calls" trace toggle, animated busy
  indicator, live updates via Symcon's `handleMessage` callback on the
  `History` and `Busy` variables.
- `GetVisualizationTile()` on `AISymconChat` renders `module.html` with
  variable IDs and current state injected.

### Changed
- Repository URL updated to `https://github.com/marcusvw/SymconAIPlugin`
  in `library.json`, `README.md` and both `module.json` manifests.

## [0.1.0] - 2026-05-16

### Added
- Initial scaffold: `AISymconChat` and `AISymconAgent` modules.
- Provider-agnostic LLM layer with `LMStudioProvider` and `ClaudeProvider`.
- Tool catalog: tree, variable, instance, script, profile, semantic search.
- Flat-file (binary) embedding index over the object tree. Stock IP-Symcon
  does not ship the SQLite3 PHP extension, so the index is a single packed
  float32 file under `<kernel>/media/AISymcon/<instanceID>/index.bin`.
- Scope-based permission gating (`read_only` / `control` / `automation` / `full`).
- Audit logging with `actor` + `caller` identity.
- Dry-run mode (default on).
- Secret redaction (heuristic + per-module-GUID deny-list).
- EN / DE localization for system prompt and tool descriptions.
