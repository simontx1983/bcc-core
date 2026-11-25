# Codex instructions

Before making changes, read the umbrella AI guidance:

- In the full local tree, read `../../../../../docs/ai/README.md`.
- In a standalone or cloud checkout, clone or otherwise access the umbrella Blue Collar Crypto repository and read its `docs/ai/README.md`.

`bcc-core` owns shared infrastructure for the Blue Collar Crypto plugins. Changes here can affect multiple consumers, so preserve shared contracts and extend canonical implementations rather than creating parallel ones.

Before writing or modifying code, follow §11 Cross-Codebase Reuse Rule: search this repository, `/bcc-global-library/` when present, `docs/pattern-registry.md`, and any related repositories. Produce the required CROSS-CODEBASE SCAN REPORT before implementation, then reuse or extend existing logic where possible.
