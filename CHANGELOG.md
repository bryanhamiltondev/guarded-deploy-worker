# Changelog

## 1.0.0 - 2026-11-14

- First release, extracted and sanitized from a production cPanel deploy workflow (~60 patches).
- Skeleton: token gate, needle-count guards, patch-marker idempotency, backup -> write -> php -l -> verify -> restore, self-cleaning backups, optional self-delete.
- README documents the operating contract and the incident lessons behind each guard.
