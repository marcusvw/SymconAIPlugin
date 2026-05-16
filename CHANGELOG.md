# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
