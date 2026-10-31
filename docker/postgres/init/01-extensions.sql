-- Bảo Sơn SaaS — required PostgreSQL extensions (pinned stack, CLAUDE.md §1).
-- Runs once on a fresh data volume via /docker-entrypoint-initdb.d.
-- Re-applied idempotently by migration database/migrations for CI/managed PG.

CREATE EXTENSION IF NOT EXISTS pgcrypto;   -- gen_random_uuid, crypto helpers
CREATE EXTENSION IF NOT EXISTS ltree;      -- taxonomy paths (category tree)
CREATE EXTENSION IF NOT EXISTS vector;     -- pgvector (AI embeddings, GĐ sau)
CREATE EXTENSION IF NOT EXISTS citext;     -- D12: case-insensitive email/slug/name
CREATE EXTENSION IF NOT EXISTS pg_trgm;    -- trigram GIN indexes: name/email search of the activity log
